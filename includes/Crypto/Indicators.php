<?php
namespace Meelano\Crypto;

/**
 * توابع تحلیل تکنیکال — پیاده‌سازی خالص و قطعی (بدون وابستگی).
 *
 * همه توابع روی آرایهٔ عددی کار می‌کنند تا قابل تست و بازتولید باشند.
 * این لایه «ریاضی» است؛ تصمیم‌گیری در Filters و SignalEngine انجام می‌شود.
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
final class Indicators
{
    /** میانگین متحرک ساده. */
    public static function sma(array $values, int $period): array
    {
        $out = [];
        $n = count($values);
        $sum = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $sum += $values[$i];
            if ($i >= $period) {
                $sum -= $values[$i - $period];
            }
            $out[] = $i >= $period - 1 ? $sum / $period : null;
        }
        return $out;
    }

    /** میانگین متحرک نمایی. */
    public static function ema(array $values, int $period): array
    {
        $out = [];
        $n = count($values);
        if ($n === 0) {
            return $out;
        }
        $k = 2.0 / ($period + 1);
        $ema = $values[0];
        for ($i = 0; $i < $n; $i++) {
            $ema = $i === 0 ? $values[0] : ($values[$i] * $k + $ema * (1 - $k));
            $out[] = $i >= $period - 1 ? $ema : null;
        }
        return $out;
    }

    /** شاخص قدرت نسبی (Wilder). */
    public static function rsi(array $closes, int $period = 14): array
    {
        $out = [null];
        $n = count($closes);
        $gains = 0.0;
        $losses = 0.0;
        for ($i = 1; $i < $n; $i++) {
            $change = $closes[$i] - $closes[$i - 1];
            $gain = max(0.0, $change);
            $loss = max(0.0, -$change);
            if ($i <= $period) {
                $gains += $gain;
                $losses += $loss;
                $out[] = null;
                if ($i === $period) {
                    $avgG = $gains / $period;
                    $avgL = $losses / $period;
                    $out[$i] = self::rsiFromAvg($avgG, $avgL);
                }
            } else {
                $prev = self::lastNonNull($out);
                [$avgG, $avgL] = self::avgFromRsi($prev, $period);
                // بازسازی میانگین Wilder از مقدار قبلی
                $avgG = ($avgG * ($period - 1) + $gain) / $period;
                $avgL = ($avgL * ($period - 1) + $loss) / $period;
                $out[] = self::rsiFromAvg($avgG, $avgL);
            }
        }
        return $out;
    }

    private static function rsiFromAvg(float $avgG, float $avgL): float
    {
        if ($avgL == 0.0) {
            return $avgG == 0.0 ? 50.0 : 100.0;
        }
        $rs = $avgG / $avgL;
        return 100.0 - (100.0 / (1.0 + $rs));
    }

    /** تقریب میانگین سود/زیان از RSI قبلی (برای گام Wilder). */
    private static function avgFromRsi(?float $rsi, int $period): array
    {
        if ($rsi === null) {
            return [0.0, 0.0];
        }
        // بازگردانی نسبت RS از RSI
        if ($rsi >= 100.0) {
            return [1.0, 0.0];
        }
        if ($rsi <= 0.0) {
            return [0.0, 1.0];
        }
        $rs = (100.0 - $rsi) !== 0.0 ? $rsi / (100.0 - $rsi) : 0.0;
        // یک نرمال‌سازی ساده: avgL=1، avgG=rs
        return [$rs, 1.0];
    }

    private static function lastNonNull(array $arr)
    {
        for ($i = count($arr) - 1; $i >= 0; $i--) {
            if ($arr[$i] !== null) {
                return $arr[$i];
            }
        }
        return null;
    }

    /** MACD → [macd, signal, histogram]. */
    public static function macd(array $closes, int $fast = 12, int $slow = 26, int $signal = 9): array
    {
        $emaFast = self::ema($closes, $fast);
        $emaSlow = self::ema($closes, $slow);
        $macdLine = [];
        $n = count($closes);
        for ($i = 0; $i < $n; $i++) {
            $macdLine[] = ($emaFast[$i] !== null && $emaSlow[$i] !== null) ? $emaFast[$i] - $emaSlow[$i] : null;
        }
        $compact = array_values(array_filter($macdLine, static function ($v) {
            return $v !== null;
        }));
        $signalCompact = self::ema($compact, $signal);
        // هم‌تراز کردن signal با macdLine
        $signalLine = [];
        $hist = [];
        $ci = 0;
        for ($i = 0; $i < $n; $i++) {
            if ($macdLine[$i] === null) {
                $signalLine[] = null;
                $hist[] = null;
                continue;
            }
            $s = $signalCompact[$ci] ?? null;
            $signalLine[] = $s;
            $hist[] = ($s !== null) ? $macdLine[$i] - $s : null;
            $ci++;
        }
        return [$macdLine, $signalLine, $hist];
    }

    /** بولینگر → [middle, upper, lower]. */
    public static function bollinger(array $closes, int $period = 20, float $mult = 2.0): array
    {
        $middle = self::sma($closes, $period);
        $upper = [];
        $lower = [];
        $n = count($closes);
        for ($i = 0; $i < $n; $i++) {
            if ($middle[$i] === null) {
                $upper[] = null;
                $lower[] = null;
                continue;
            }
            $slice = array_slice($closes, $i - $period + 1, $period);
            $variance = 0.0;
            foreach ($slice as $v) {
                $variance += ($v - $middle[$i]) ** 2;
            }
            $sd = sqrt($variance / $period);
            $upper[] = $middle[$i] + $mult * $sd;
            $lower[] = $middle[$i] - $mult * $sd;
        }
        return [$middle, $upper, $lower];
    }

    /** استوکستیک کند → [K, D]. */
    public static function stochastic(array $highs, array $lows, array $closes, int $period = 14, int $smooth = 3): array
    {
        $k = [];
        $n = count($closes);
        for ($i = 0; $i < $n; $i++) {
            if ($i < $period - 1) {
                $k[] = null;
                continue;
            }
            $hh = max(array_slice($highs, $i - $period + 1, $period));
            $ll = min(array_slice($lows, $i - $period + 1, $period));
            $k[] = ($hh - $ll) > 0 ? (($closes[$i] - $ll) / ($hh - $ll)) * 100 : 50.0;
        }
        $kCompact = array_values(array_filter($k, static function ($v) {
            return $v !== null;
        }));
        $dCompact = self::sma($kCompact, $smooth);
        $d = [];
        $ci = 0;
        for ($i = 0; $i < $n; $i++) {
            if ($k[$i] === null) {
                $d[] = null;
                continue;
            }
            $d[] = $dCompact[$ci] ?? null;
            $ci++;
        }
        return [$k, $d];
    }

    /** میانگین دامنهٔ واقعی (ATR — Wilder). */
    public static function atr(array $highs, array $lows, array $closes, int $period = 14): array
    {
        $n = count($closes);
        $tr = [];
        for ($i = 0; $i < $n; $i++) {
            if ($i === 0) {
                $tr[] = $highs[$i] - $lows[$i];
            } else {
                $tr[] = max(
                    $highs[$i] - $lows[$i],
                    abs($highs[$i] - $closes[$i - 1]),
                    abs($lows[$i] - $closes[$i - 1])
                );
            }
        }
        $atr = [null];
        $sum = 0.0;
        for ($i = 1; $i < $n; $i++) {
            if ($i <= $period) {
                $sum += $tr[$i];
                $atr[] = $i === $period ? $sum / $period : null;
            } else {
                $prev = self::lastNonNull($atr);
                $atr[] = $prev !== null ? (($prev * ($period - 1)) + $tr[$i]) / $period : null;
            }
        }
        return $atr;
    }

    /** نرخ تغییر درصدی نسبت به n کندل قبل. */
    public static function roc(array $closes, int $period = 1): array
    {
        $out = [];
        $n = count($closes);
        for ($i = 0; $i < $n; $i++) {
            $prev = $i - $period >= 0 ? $closes[$i - $period] : null;
            $out[] = ($prev !== null && $prev != 0) ? (($closes[$i] - $prev) / $prev) * 100 : null;
        }
        return $out;
    }

    /** نسبت حجم فعلی به میانگین حجم. */
    public static function volumeRatio(array $volumes, int $period = 20): ?float
    {
        $n = count($volumes);
        if ($n < $period + 1) {
            return null;
        }
        $avg = array_sum(array_slice($volumes, $n - $period - 1, $period)) / $period;
        return $avg > 0 ? $volumes[$n - 1] / $avg : null;
    }

    /** تشخیص ساختار سقف/کف بالاتر. */
    public static function structure(array $closes, int $lookback = 10): string
    {
        $n = count($closes);
        if ($n < $lookback * 2) {
            return 'range';
        }
        $half = intdiv($lookback, 2) ?: 1;
        $firstHigh = max(array_slice($closes, $n - $lookback * 2, $lookback));
        $secondHigh = max(array_slice($closes, $n - $lookback, $lookback));
        $firstLow = min(array_slice($closes, $n - $lookback * 2, $lookback));
        $secondLow = min(array_slice($closes, $n - $lookback, $lookback));
        if ($secondHigh > $firstHigh && $secondLow > $firstLow) {
            return 'uptrend';
        }
        if ($secondHigh < $firstHigh && $secondLow < $firstLow) {
            return 'downtrend';
        }
        return 'range';
    }

    /** نسبت سایهٔ بالایی به کل دامنه (تشخیص دستکاری/فشار فروش). */
    public static function upperWickRatio(array $highs, array $lows, array $opens, array $closes): ?float
    {
        $n = count($closes);
        if ($n === 0) {
            return null;
        }
        $i = $n - 1;
        $range = $highs[$i] - $lows[$i];
        if ($range <= 0) {
            return null;
        }
        $body = abs($closes[$i] - $opens[$i]);
        $upperWick = $highs[$i] - max($closes[$i], $opens[$i]);
        return $upperWick / $range;
    }

    /** آخرین مقدار غیرتهی یک آرایه. */
    public static function last(array $values)
    {
        for ($i = count($values) - 1; $i >= 0; $i--) {
            if ($values[$i] !== null) {
                return $values[$i];
            }
        }
        return null;
    }
}
