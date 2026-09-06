<?php
namespace Meelano\Crypto;

/**
 * زنجیرهٔ فیلترهای سخت‌گیرانهٔ کریپتو.
 *
 * این مجموعه قواعد، «فیلترهای عبوری» نام دارد: یک ارز فقط وقتی سیگنال می‌گیرد
 * که از تک‌تک این فیلترها عبور کند. هر فیلتر یک امتیاز ۰..۱ و جهت (BUY/SELL) برمی‌گرداند.
 *
 * فلسفهٔ طراحی (کاهش خطا): به‌جای یک مدل تنها، ترکیبی از روند، مومنتوم، حجم،
 * نوسان، ساختار و آنتی‌منیپولیشن استفاده می‌شود تا سیگنال‌های کاذب حذف شوند.
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
final class Filters
{
    /**
     * ارزیابی همهٔ فیلترها روی یک بافت (context) از اندیکاتورها.
     *
     * @param array $ctx شامل: closes,highs,lows,opens,volumes,ticker و مقادیر last اندیکاتورها
     * @return array{side:string,filters:array,tech_score:float,passed:int,total:int}
     */
    public function evaluate(array $ctx): array
    {
        $filters = [];
        $buyVotes = 0.0;
        $sellVotes = 0.0;

        $rsi = (float)($ctx['rsi'] ?? 50);
        $macdHist = (float)($ctx['macd_hist'] ?? 0);
        $macdHistPrev = (float)($ctx['macd_hist_prev'] ?? 0);
        $ema50 = (float)($ctx['ema50'] ?? 0);
        $ema200 = (float)($ctx['ema200'] ?? 0);
        $price = (float)($ctx['price'] ?? 0);
        $bbUpper = (float)($ctx['bb_upper'] ?? 0);
        $bbLower = (float)($ctx['bb_lower'] ?? 0);
        $bbPos = (float)($ctx['bb_pos'] ?? 0.5); // 0=کف باند، 1=سقف باند
        $stochK = (float)($ctx['stoch_k'] ?? 50);
        $stochD = (float)($ctx['stoch_d'] ?? 50);
        $atrPct = (float)($ctx['atr_pct'] ?? 0);
        $volRatio = (float)($ctx['vol_ratio'] ?? 1);
        $change24 = (float)($ctx['change24'] ?? 0);
        $quoteVolume = (float)($ctx['quote_volume'] ?? 0);
        $structure = (string)($ctx['structure'] ?? 'range');
        $wickRatio = (float)($ctx['wick_ratio'] ?? 0);

        // ── ) فیلتر نقدینگی: حجم ۲۴س کم نباشد ───────────────────────
        $minVol = (float)($ctx['min_quote_volume'] ?? 5000000);
        $liquidity = $quoteVolume >= $minVol;
        $filters[] = $this->f('liquidity', 'نقدینگی کافی (حجم ۲۴س)', $liquidity,
            $liquidity ? 1.0 : 0.0, 'NEUTRAL',
            'حجم ۲۴س: ' . $this->compact($quoteVolume) . ' (حداقل: ' . $this->compact($minVol) . ')');

        // ── ۲) فیلتر روند ساختاری ─────────────────────────────────────
        $trendBuy = $structure === 'uptrend' || ($ema50 > $ema200 && $price > $ema200);
        $trendSell = $structure === 'downtrend' || ($ema50 < $ema200 && $price < $ema200);
        $filters[] = $this->f('trend', 'هم‌راستایی با روند', $trendBuy || $trendSell,
            ($trendBuy || $trendSell) ? 0.9 : 0.3, $trendBuy ? 'BUY' : ($trendSell ? 'SELL' : 'NEUTRAL'),
            'ساختار: ' . $structure . ' · EMA50 ' . ($ema50 >= $ema200 ? 'بالای' : 'زیر') . ' EMA200');
        if ($trendBuy) { $buyVotes += 1.0; } elseif ($trendSell) { $sellVotes += 1.0; }

        // ── ۳) فیلتر مومنتوم RSI: منطقهٔ بهینه ──────────────────────
        $rsiBuy = $rsi >= 45 && $rsi <= 68;      // نه اشباع خرید، نه ضعف
        $rsiSell = $rsi >= 70 || $rsi <= 30;     // اشباع برای خروج/شورت
        $rsiStrongSell = $rsi >= 75;
        $filters[] = $this->f('rsi', 'مومنتوم RSI در منطقهٔ بهینه', $rsiBuy || $rsiSell,
            $rsiBuy ? 0.9 : ($rsiStrongSell ? 0.8 : ($rsiSell ? 0.5 : 0.2)),
            $rsiBuy ? 'BUY' : ($rsiSell ? 'SELL' : 'NEUTRAL'),
            'RSI(14): ' . round($rsi, 1));
        if ($rsiBuy) { $buyVotes += 0.9; } elseif ($rsiSell) { $sellVotes += 0.7; }

        // ── ۴) فیلتر MACD: چرخش هیستوگرام ────────────────────────────
        $macdBuy = $macdHist > 0 && $macdHist > $macdHistPrev;
        $macdSell = $macdHist < 0 && $macdHist < $macdHistPrev;
        $filters[] = $this->f('macd', 'چرخش هیستوگرام MACD', $macdBuy || $macdSell,
            ($macdBuy || $macdSell) ? 0.85 : 0.3, $macdBuy ? 'BUY' : ($macdSell ? 'SELL' : 'NEUTRAL'),
            'هیستوگرام: ' . $this->sci($macdHist) . ' (قبلی: ' . $this->sci($macdHistPrev) . ')');
        if ($macdBuy) { $buyVotes += 0.9; } elseif ($macdSell) { $sellVotes += 0.9; }

        // ── ۵) فیلتر حجم: تأیید با حجم ───────────────────────────────
        $volConfirm = $volRatio >= 1.3;
        $filters[] = $this->f('volume', 'تأیید حرکت با حجم', $volConfirm,
            $volConfirm ? min(1.0, $volRatio / 2.0) : 0.2, 'NEUTRAL',
            'نسبت حجم: ' . round($volRatio, 2) . 'x میانگین');

        // ── ۶) فیلتر نوسان: ATR نه خیلی وحشی ─────────────────────────
        $atrOk = $atrPct > 0.1 && $atrPct < 6.0;
        $filters[] = $this->f('volatility', 'نوسان در دامنهٔ قابل‌مدیریت', $atrOk,
            $atrOk ? 0.8 : 0.2, 'NEUTRAL', 'ATR% : ' . round($atrPct, 2));

        // ── ۷) فیلتر بولینگر: موقعیت قیمت ────────────────────────────
        $bbBuy = $bbPos <= 0.35;                 // نزدیک کف باند → بازگشت به میانگین
        $bbSell = $bbPos >= 0.9;                 // چسبیده به سقف → اشباع
        $filters[] = $this->f('bollinger', 'موقعیت بولینگر', $bbBuy || $bbSell || ($bbPos > 0.4 && $bbPos < 0.8),
            $bbBuy ? 0.8 : ($bbSell ? 0.6 : 0.5), $bbBuy ? 'BUY' : ($bbSell ? 'SELL' : 'NEUTRAL'),
            'موقعیت در باند: ' . round($bbPos * 100) . '٪');
        if ($bbBuy) { $buyVotes += 0.6; } elseif ($bbSell) { $sellVotes += 0.6; }

        // ── ۸) فیلتر استوکستیک: کراس در منطقهٔ اشباع ─────────────────
        $stochBuy = $stochK < 30 && $stochK > $stochD;
        $stochSell = $stochK > 70 && $stochK < $stochD;
        $filters[] = $this->f('stochastic', 'کراس استوکستیک', $stochBuy || $stochSell,
            ($stochBuy || $stochSell) ? 0.8 : 0.3, $stochBuy ? 'BUY' : ($stochSell ? 'SELL' : 'NEUTRAL'),
            'K: ' . round($stochK, 1) . ' · D: ' . round($stochD, 1));
        if ($stochBuy) { $buyVotes += 0.8; } elseif ($stochSell) { $sellVotes += 0.8; }

        // ── ۹) فیلتر سقوط آزاد: عدم ورود به تیغِ در حال سقوط ─────────
        $noFreefall = $change24 > -12.0;
        $filters[] = $this->f('freefall', 'عدم ورود به سقوط آزاد', $noFreefall,
            $noFreefall ? 0.9 : 0.0, $noFreefall ? 'NEUTRAL' : 'SELL',
            'تغییر ۲۴س: ' . round($change24, 1) . '٪');

        // ── ۱۰) فیلتر آنتی‌منیپولیشن: سایهٔ بالایی غیرعادی ───────────
        $antiManip = $wickRatio < 0.45;
        $filters[] = $this->f('manipulation', 'آنتی‌منیپولیشن (سایهٔ فروش)', $antiManip,
            $antiManip ? 0.8 : 0.1, $antiManip ? 'NEUTRAL' : 'SELL',
            'نسبت سایهٔ بالا: ' . round($wickRatio * 100) . '٪');

        // ── جمع‌بندی ─────────────────────────────────────────────────
        $passed = count(array_filter($filters, static function ($f) {
            return $f['pass'];
        }));
        $side = $buyVotes >= $sellVotes ? 'BUY' : 'SELL';
        $dominant = max($buyVotes, $sellVotes);
        $maxVotes = 5.0; // سقف تقریبی آرای جهت‌دار
        $techScore = min(100.0, ($dominant / $maxVotes) * 70 + ($passed / count($filters)) * 30);

        return [
            'side' => $side,
            'buy_votes' => round($buyVotes, 2),
            'sell_votes' => round($sellVotes, 2),
            'filters' => $filters,
            'passed' => $passed,
            'total' => count($filters),
            'tech_score' => round($techScore, 1),
        ];
    }

    private function f(string $key, string $label, bool $pass, float $score, string $side, string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'pass' => $pass, 'score' => round($score, 2), 'side' => $side, 'detail' => $detail];
    }

    private function compact(float $v): string
    {
        if ($v >= 1e9) { return round($v / 1e9, 2) . 'B'; }
        if ($v >= 1e6) { return round($v / 1e6, 2) . 'M'; }
        if ($v >= 1e3) { return round($v / 1e3, 1) . 'K'; }
        return (string)round($v, 1);
    }

    private function sci(float $v): string
    {
        if ($v == 0.0) { return '0'; }
        return number_format($v, 4);
    }
}
