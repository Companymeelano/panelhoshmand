<?php
namespace Meelano\Crypto;

use Meelano\Ai\Client;
use Meelano\Config;
use Meelano\Db;
use Meelano\Logger;
use Throwable;

/**
 * موتور سیگنال کریپتو — هماهنگ‌کنندهٔ کل جریان:
 *   داده بازار → اندیکاتورها → فیلترهای سخت‌گیرانه → اجماع AI → مدیریت ریسک → سیگنال
 *
 * خروجی فقط وقتی صادر می‌شود که از سخت‌ترین فیلترها عبور کرده و اجماع AI هم‌راستا باشد.
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
final class SignalEngine
{
    /** @var MarketData */
    private $market;
    /** @var Filters */
    private $filters;
    /** @var RiskManager */
    private $risk;
    /** @var Client|null */
    private $ai;
    /** @var Db|null */
    private $db;
    /** @var array */
    private $cfg;

    public function __construct(?MarketData $market = null, ?Client $ai = null, ?Db $db = null, ?array $cfg = null)
    {
        $this->market = $market ?: new MarketData();
        $this->filters = new Filters();
        $this->risk = new RiskManager((array)($cfg ?? (array)Config::get('trading', [])));
        $this->ai = $ai;
        $this->db = $db;
        $this->cfg = $cfg ?: (array)Config::get('trading', []);
    }

    /**
     * رصد کل بازار و تولید سیگنال‌های عبورکرده از فیلترها.
     *
     * @return array{ok:bool,scanned:int,signals:array,errors:array,duration_ms:float,ai_used:bool}
     */
    public function scanMarket(?int $limit = null): array
    {
        $started = m_microtime();
        $limit = $limit ?? (int)($this->cfg['scan_limit'] ?? 40);
        $tickers = $this->market->tickers($limit);
        $signals = [];
        $errors = [];
        $aiUsed = false;

        if (!$tickers) {
            return ['ok' => false, 'scanned' => 0, 'signals' => [], 'errors' => ['داده بازار دریافت نشد — اتصال سرور به Binance/CoinGecko را بررسی کنید.'], 'duration_ms' => 0, 'ai_used' => false];
        }

        foreach ($tickers as $t) {
            // پیش‌فیلتر سریع: نقدینگی
            if ((float)$t['quote_volume'] < (float)($this->cfg['min_quote_volume'] ?? 0)) {
                continue;
            }
            $signal = $this->analyzeSymbol($t['symbol'], $t);
            if (!empty($signal['is_signal'])) {
                $signals[] = $signal;
                if (!empty($signal['ai']['ok'])) { $aiUsed = true; }
            }
        }

        // مرتب‌سازی بر پایه امتیاز ترکیبی نزولی
        usort($signals, static function ($a, $b) {
            return $b['combined_score'] <=> $a['combined_score'];
        });

        $scanId = $this->recordScan(count($tickers), count($signals));
        foreach ($signals as &$s) {
            $s['scan_id'] = $scanId;
            $this->persistSignal($s);
        }
        unset($s);

        return [
            'ok' => true,
            'scanned' => count($tickers),
            'signals' => $signals,
            'errors' => $errors,
            'duration_ms' => round((m_microtime() - $started) * 1000, 1),
            'ai_used' => $aiUsed,
        ];
    }

    /**
     * تحلیل یک نماد (با ticker اختیاری برای جلوگیری از فراخوانی اضافه).
     *
     * @return array
     */
    public function analyzeSymbol(string $symbol, ?array $ticker = null): array
    {
        $interval = (string)($this->cfg['timeframe'] ?? '1h');
        $cres = $this->market->candles($symbol, $interval, 220);
        if (empty($cres['ok']) || count($cres['candles']) < 60) {
            return ['symbol' => $symbol, 'is_signal' => false, 'error' => $cres['error'] ?? 'کندل کافی نیست'];
        }
        $candles = $cres['candles'];
        $ctx = $this->buildContext($candles, $ticker);

        $eval = $this->filters->evaluate($ctx);

        $base = [
            'symbol' => $symbol,
            'base' => $ticker['base'] ?? rtrim($symbol, 'USDT'),
            'price' => $ctx['price'],
            'change24' => $ctx['change24'],
            'quote_volume' => $ctx['quote_volume'],
            'tech_side' => $eval['side'],
            'tech_score' => $eval['tech_score'],
            'filters' => $eval['filters'],
            'passed' => $eval['passed'],
            'total' => $eval['total'],
        ];

        // آیا از فیلترهای سخت عبور کرده؟
        if ($eval['passed'] < (int)($this->cfg['min_filters_passed'] ?? 8)
            || $eval['tech_score'] < (float)($this->cfg['min_tech_score'] ?? 62)) {
            return $base + ['is_signal' => false, 'reason' => 'عبور نکردن از فیلترهای سخت‌گیرانه'];
        }

        // اعتبارسنج AI (اجماع) برای کاهش خطا
        $ai = ['ok' => false, 'side' => null, 'ai_score' => 0.0, 'agreement' => false, 'opinions' => [], 'notes' => []];
        if ($this->ai !== null) {
            $validator = new AiValidator($this->ai, 3);
            $ai = $validator->validate($ctx + ['symbol' => $symbol, 'tech_side' => $eval['side'], 'tech_score' => $eval['tech_score']], $eval['side']);
        }

        $techW = (float)($this->cfg['tech_weight'] ?? 0.6);
        $aiW = (float)($this->cfg['ai_weight'] ?? 0.4);
        $combined = $ai['ok']
            ? ($eval['tech_score'] * $techW + $ai['ai_score'] * $aiW)
            : $eval['tech_score'];
        $combined = round($combined, 1);

        $requireAgree = (bool)($this->cfg['require_ai_agreement'] ?? true);
        $gateOk = $combined >= (float)($this->cfg['min_combined_score'] ?? 68)
            && (!$requireAgree || !$ai['ok'] || $ai['agreement']);

        if (!$gateOk) {
            return $base + ['is_signal' => false, 'ai' => $ai, 'combined_score' => $combined, 'reason' => 'امتیاز ترکیبی یا اجماع AI کافی نیست'];
        }

        $side = $ai['ok'] && $ai['agreement'] ? $ai['side'] : $eval['side'];
        $risk = $this->risk->plan($side, $ctx['price'], $ctx['atr'], $combined);

        return $base + [
            'is_signal' => true,
            'side' => $side,
            'ai' => $ai,
            'combined_score' => $combined,
            'confidence' => $combined,
            'risk' => $risk,
            'timeframe' => $interval,
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    /** ساخت بافت اندیکاتورها از کندل‌ها + ticker. */
    private function buildContext(array $candles, ?array $ticker): array
    {
        $opens = array_column($candles, 'open');
        $highs = array_column($candles, 'high');
        $lows = array_column($candles, 'low');
        $closes = array_column($candles, 'close');
        $volumes = array_column($candles, 'volume');

        $price = (float)$closes[count($closes) - 1];
        $rsi = Indicators::last(Indicators::rsi($closes, 14)) ?? 50.0;
        $ema50 = Indicators::last(Indicators::ema($closes, 50)) ?? $price;
        $ema200 = Indicators::last(Indicators::ema($closes, 200)) ?? $price;
        [$macdLine, $signalLine, $hist] = Indicators::macd($closes);
        $histLast = Indicators::last($hist) ?? 0.0;
        $histPrev = $this->prevNonNull($hist) ?? 0.0;
        [$bbMid, $bbUp, $bbLo] = Indicators::bollinger($closes);
        $bbUpLast = Indicators::last($bbUp) ?? $price;
        $bbLoLast = Indicators::last($bbLo) ?? $price;
        $bbRange = $bbUpLast - $bbLoLast;
        $bbPos = $bbRange > 0 ? max(0.0, min(1.0, ($price - $bbLoLast) / $bbRange)) : 0.5;
        [$sk, $sd] = Indicators::stochastic($highs, $lows, $closes);
        $atr = Indicators::last(Indicators::atr($highs, $lows, $closes, 14)) ?? 0.0;
        $atrPct = $price > 0 ? ($atr / $price) * 100 : 0.0;

        return [
            'price' => $price,
            'rsi' => round((float)$rsi, 2),
            'ema50' => $ema50,
            'ema200' => $ema200,
            'macd_hist' => $histLast,
            'macd_hist_prev' => $histPrev,
            'bb_upper' => $bbUpLast,
            'bb_lower' => $bbLoLast,
            'bb_pos' => round($bbPos, 3),
            'stoch_k' => round((float)(Indicators::last($sk) ?? 50), 2),
            'stoch_d' => round((float)(Indicators::last($sd) ?? 50), 2),
            'atr' => $atr,
            'atr_pct' => round($atrPct, 3),
            'vol_ratio' => round((float)(Indicators::volumeRatio($volumes) ?? 1), 2),
            'change24' => (float)($ticker['change_pct'] ?? (Indicators::last(Indicators::roc($closes, 24)) ?? 0)),
            'quote_volume' => (float)($ticker['quote_volume'] ?? 0),
            'structure' => Indicators::structure($closes, 12),
            'wick_ratio' => round((float)(Indicators::upperWickRatio($highs, $lows, $opens, $closes) ?? 0), 3),
            'min_quote_volume' => (float)($this->cfg['min_quote_volume'] ?? 5000000),
        ];
    }

    private function prevNonNull(array $arr)
    {
        $n = count($arr);
        $lastIdx = -1;
        for ($i = $n - 1; $i >= 0; $i--) {
            if ($arr[$i] !== null) { $lastIdx = $i; break; }
        }
        if ($lastIdx <= 0) { return null; }
        for ($i = $lastIdx - 1; $i >= 0; $i--) {
            if ($arr[$i] !== null) { return $arr[$i]; }
        }
        return null;
    }

    /* ── ذخیره‌سازی ─────────────────────────────────────────────────── */

    private function recordScan(int $scanned, int $found): ?int
    {
        if ($this->db === null) {
            return null;
        }
        try {
            if (!$this->db->tableExists('scans')) { return null; }
            return $this->db->insert('scans', [
                'started_at' => date('Y-m-d H:i:s'),
                'finished_at' => date('Y-m-d H:i:s'),
                'coins_scanned' => $scanned,
                'signals_found' => $found,
                'mode' => 'market',
                'summary_json' => json_encode(['scanned' => $scanned, 'found' => $found], JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable $e) {
            Logger::write('crypto', 'ثبت اسکن ناموفق: ' . $e->getMessage(), 'warning');
            return null;
        }
    }

    private function persistSignal(array $s): void
    {
        if ($this->db === null) {
            return;
        }
        try {
            if (!$this->db->tableExists('signals')) { return; }
            $this->db->insert('signals', [
                'scan_id' => $s['scan_id'] ?? null,
                'symbol' => $s['symbol'],
                'side' => $s['side'],
                'timeframe' => $s['timeframe'] ?? '',
                'confidence' => $s['combined_score'],
                'tech_score' => $s['tech_score'],
                'ai_score' => (float)($s['ai']['ai_score'] ?? 0),
                'combined_score' => $s['combined_score'],
                'entry_price' => $s['risk']['entry'],
                'stop_loss' => $s['risk']['stop_loss'],
                'take_profit_1' => $s['risk']['take_profit_1'],
                'take_profit_2' => $s['risk']['take_profit_2'],
                'take_profit_3' => $s['risk']['take_profit_3'],
                'risk_reward' => $s['risk']['risk_reward_2'],
                'position_pct' => $s['risk']['position_percent'],
                'filters_passed' => $s['passed'],
                'filters_total' => $s['total'],
                'filters_json' => json_encode($s['filters'], JSON_UNESCAPED_UNICODE),
                'ai_json' => json_encode($s['ai']['opinions'] ?? [], JSON_UNESCAPED_UNICODE),
                'status' => 'new',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            Logger::write('crypto', 'ثبت سیگنال ناموفق: ' . $e->getMessage(), 'warning');
        }
    }
}
