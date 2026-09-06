<?php
namespace Meelano\Crypto;

use Meelano\Ai\Client;

/**
 * اعتبارسنج هوش مصنوعی — کاهش خطا با «اجماع چندمدلی».
 *
 * به‌جای اعتماد به یک مدل، از چند موتور مستقل (برترین‌های مسیریاب) نظر گرفته و
 * با رأی‌گیری وزنی به اجماع می‌رسد. سیگنال نهایی فقط وقتی صادر می‌شود که
 * اجماع AI با سمت تکنیکال هم‌راستا باشد؛ این «فیلتر دوم» خطای تک‌مدل را حذف می‌کند.
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
final class AiValidator
{
    /** @var Client */
    private $client;
    /** @var int */
    private $panelSize;

    public function __construct(Client $client, int $panelSize = 3)
    {
        $this->client = $client;
        $this->panelSize = $panelSize;
    }

    /**
     * @param array $summary خلاصهٔ اندیکاتورها و نتیجهٔ فیلترها برای ارسال به مدل‌ها
     * @param string $techSide سمت تکنیکال (BUY/SELL)
     * @return array{ok:bool,side:?string,ai_score:float,agreement:bool,opinions:array,notes:array}
     */
    public function validate(array $summary, string $techSide): array
    {
        $candidates = $this->panel();
        if (!$candidates) {
            return ['ok' => false, 'side' => null, 'ai_score' => 0.0, 'agreement' => false, 'opinions' => [], 'notes' => ['هیچ موتور AI فعالی برای اجماع موجود نیست.']];
        }

        $prompt = $this->prompt($summary);
        $opinions = [];
        foreach ($candidates as $provider) {
            $res = $this->client->json('crypto.signal', $prompt, [
                'provider' => $provider,
                'temperature' => 0.2,
                'max_tokens' => 350,
            ]);
            if (!empty($res['ok']) && is_array($res['data'])) {
                $signal = strtoupper((string)($res['data']['signal'] ?? ''));
                $conf = min(100.0, max(0.0, (float)($res['data']['confidence'] ?? 0)));
                if (in_array($signal, ['BUY', 'SELL', 'NEUTRAL', 'HOLD'], true)) {
                    $opinions[] = [
                        'provider' => $provider,
                        'signal' => $signal,
                        'confidence' => $conf,
                        'reasoning' => mb_substr((string)($res['data']['reasoning'] ?? ''), 0, 400),
                        'risks' => array_slice((array)($res['data']['risks'] ?? []), 0, 4),
                    ];
                }
            }
        }

        if (!$opinions) {
            return ['ok' => false, 'side' => null, 'ai_score' => 0.0, 'agreement' => false, 'opinions' => [], 'notes' => ['هیچ مدلی پاسخ معتبر نداد.']];
        }

        // رأی‌گیری وزنی بر پایهٔ اعتماد هر مدل
        $buyW = 0.0; $sellW = 0.0; $totalW = 0.0;
        foreach ($opinions as $o) {
            $w = $o['confidence'];
            $totalW += $w;
            if ($o['signal'] === 'BUY') { $buyW += $w; }
            elseif ($o['signal'] === 'SELL') { $sellW += $w; }
        }
        $aiSide = $buyW >= $sellW ? 'BUY' : 'SELL';
        $dominant = max($buyW, $sellW);
        $aiScore = $totalW > 0 ? ($dominant / $totalW) * 100 : 0;

        // میزان همگرایی مدل‌ها با هم و با تکنیکال
        $agreeWithTech = 0;
        foreach ($opinions as $o) {
            if ($o['signal'] === $techSide) { $agreeWithTech++; }
        }
        $agreement = $aiSide === $techSide && ($agreeWithTech / count($opinions)) >= 0.5;

        $notes = [];
        foreach ($opinions as $o) {
            $notes[] = $o['provider'] . ': ' . $o['signal'] . ' (' . round($o['confidence']) . '٪)';
        }

        return [
            'ok' => true,
            'side' => $aiSide,
            'ai_score' => round($aiScore, 1),
            'agreement' => $agreement,
            'agree_ratio' => round($agreeWithTech / count($opinions), 2),
            'opinions' => $opinions,
            'notes' => $notes,
        ];
    }

    /** برترین ارائه‌دهندگان واجد شرایط برای وظیفهٔ سیگنال. */
    private function panel(): array
    {
        $ranked = $this->client->router()->rank('crypto.signal');
        $out = [];
        foreach ($ranked as $row) {
            if (!empty($row['eligible']) && $row['score'] > 0) {
                $out[] = $row['provider'];
            }
            if (count($out) >= $this->panelSize) {
                break;
            }
        }
        return $out;
    }

    private function prompt(array $s): string
    {
        return "تو یک معامله‌گر نهادی کریپتو هستی. دادهٔ فنی یک ارز:\n"
            . "نماد: {$s['symbol']}\nقیمت: {$s['price']}\nRSI(14): {$s['rsi']}\n"
            . "MACD hist: {$s['macd_hist']}\nEMA50/EMA200: {$s['ema50']}/{$s['ema200']}\n"
            . "موقعیت بولینگر٪: {$s['bb_pos']}\nاستوکستیک K/D: {$s['stoch_k']}/{$s['stoch_d']}\n"
            . "ATR٪: {$s['atr_pct']}\nنسبت حجم: {$s['vol_ratio']}\nتغییر ۲۴س٪: {$s['change24']}\n"
            . "ساختار: {$s['structure']}\nامتیاز تکنیکال: {$s['tech_score']}\nسمت تکنیکال: {$s['tech_side']}\n\n"
            . "با سخت‌گیری قضاوت کن و اگر شواهد کافی نیست NEUTRAL بده.\n"
            . 'خروجی JSON: {"signal":"BUY|SELL|NEUTRAL","confidence":0_100,"reasoning":string,"risks":[string]}';
    }
}
