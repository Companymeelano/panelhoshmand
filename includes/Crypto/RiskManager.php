<?php
namespace Meelano\Crypto;

/**
 * مدیریت ریسک و پوزیشن — خروجی عملیاتی هر سیگنال.
 *
 * ورودی: قیمت ورود، ATR، جهت، اعتماد. خروجی: استاپ، تارگت‌ها، R:R، درصد پوزیشن.
 * اصل: هرگز بیش از r٪ از سرمایه در یک معامله ریسک نشود و سایز پوزیشن از فاصلهٔ
 * استاپ مشتق شود (نه سلیقه‌ای).
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
final class RiskManager
{
    /** @var array */
    private $cfg;

    public function __construct(array $cfg = [])
    {
        $this->cfg = $cfg;
    }

    /**
     * @param string $side BUY|SELL
     * @param float $entry قیمت ورود
     * @param float $atr مقدار ATR
     * @param float $confidence اعتماد ترکیبی ۰..۱۰۰
     * @return array
     */
    public function plan(string $side, float $entry, float $atr, float $confidence): array
    {
        $riskPerTrade = (float)($this->cfg['risk_per_trade_percent'] ?? 1.0);   // ٪ سرمایه
        $atrStopMult = (float)($this->cfg['atr_stop_multiplier'] ?? 2.0);
        $maxPosition = (float)($this->cfg['max_position_percent'] ?? 25.0);     // سقف پوزیشن
        $buy = $side === 'BUY';

        $stopDistance = max($atr * $atrStopMult, $entry * 0.005); // حداقل ۰٫۵٪
        $stop = $buy ? $entry - $stopDistance : $entry + $stopDistance;

        $tp1 = $buy ? $entry + $stopDistance * 1.5 : $entry - $stopDistance * 1.5;
        $tp2 = $buy ? $entry + $stopDistance * 2.5 : $entry - $stopDistance * 2.5;
        $tp3 = $buy ? $entry + $stopDistance * 4.0 : $entry - $stopDistance * 4.0;

        $rr1 = $stopDistance > 0 ? abs($tp1 - $entry) / $stopDistance : 0;
        $rr2 = $stopDistance > 0 ? abs($tp2 - $entry) / $stopDistance : 0;

        // سایز پوزیشن از ریسک ثابت: position% = risk% / stopDistance%
        $stopPct = $entry > 0 ? ($stopDistance / $entry) * 100 : 100;
        $position = $stopPct > 0 ? ($riskPerTrade / $stopPct) * 100 : 0;
        // تعدیل با اعتماد: اعتماد بالاتر → سایز کمی بیشتر (با سقف)
        $confidenceFactor = 0.5 + ($confidence / 100) * 0.75; // 0.5..1.25
        $position = $position * $confidenceFactor;
        $position = max(0.0, min($maxPosition, $position));

        return [
            'side' => $side,
            'entry' => round($entry, 8),
            'stop_loss' => round($stop, 8),
            'take_profit_1' => round($tp1, 8),
            'take_profit_2' => round($tp2, 8),
            'take_profit_3' => round($tp3, 8),
            'risk_reward_1' => round($rr1, 2),
            'risk_reward_2' => round($rr2, 2),
            'stop_distance_pct' => round($stopPct, 2),
            'position_percent' => round($position, 2),
            'risk_per_trade_percent' => $riskPerTrade,
        ];
    }
}
