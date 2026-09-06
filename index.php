<?php
/**
 * پنل هوشمند میلانو — داشبورد سیگنال کریپتو.
 * رصد کل بازار، عبور از فیلترهای سخت‌گیرانه و اجماع AI → سیگنال خرید/فروش.
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

use Meelano\Config;
use Meelano\Db;
use Meelano\Installer;
use Meelano\Security;

Security::secureHeaders();

$db = Db::make();
$dbOk = $db->isConnected();
$install = $dbOk ? (new Installer($db))->status() : null;

$stats = null;
$recent = [];
if ($dbOk && $db->tableExists('signals')) {
    $row = $db->selectOne("SELECT COUNT(*) AS total, SUM(CASE WHEN side='BUY' THEN 1 ELSE 0 END) AS buys,
            SUM(CASE WHEN side='SELL' THEN 1 ELSE 0 END) AS sells,
            COALESCE(AVG(combined_score),0) AS avg_score, COALESCE(MAX(combined_score),0) AS max_score
            FROM " . $db->table('signals'));
    $stats = [
        'total' => (int)($row['total'] ?? 0),
        'buys' => (int)($row['buys'] ?? 0),
        'sells' => (int)($row['sells'] ?? 0),
        'avg_score' => round((float)($row['avg_score'] ?? 0), 1),
        'max_score' => round((float)($row['max_score'] ?? 0), 1),
        'scans' => $db->tableExists('scans') ? $db->count('scans') : 0,
    ];
    $recent = $db->select('SELECT * FROM ' . $db->table('signals') . ' ORDER BY id DESC LIMIT 20');
}

$trading = (array)Config::get('trading', []);

m_layout_head('سیگنال کریپتو', 'panel');
?>
<div class="shell">
<?php m_layout_nav('panel'); ?>

<?php if (!$dbOk || !$install || !$install['complete']): ?>
<div class="card-3d section" style="border-color:rgba(251,191,36,.4)">
    <div class="section__head" style="border:none;margin:0;padding:0">
        <h2 class="section__title"><i class="fa-solid fa-triangle-exclamation" style="color:#fbbf24"></i> راه‌اندازی دیتابیس کامل نشده</h2>
        <a class="btn btn--gold btn--sm" href="<?= meelano_e(m_url('settings.php')) ?>"><i class="fa-solid fa-sliders"></i> تنظیمات</a>
    </div>
    <p class="section__hint" style="margin-top:10px">سیگنال‌ها برای ذخیره و تاریخچه به دیتابیس نیاز دارند؛ تحلیل بازار بدون دیتابیس هم کار می‌کند.</p>
</div>
<?php endif; ?>

<div class="page-head">
    <div>
        <h1 class="page-title">ماشین سیگنال هوشمند کریپتو</h1>
        <p class="page-sub">
            رصد کل بازار · عبور از سخت‌گیرانه‌ترین فیلترهای تکنیکال · تأیید با اجماع چندمدلی هوش مصنوعی.
            فقط ارزهایی که به مرحلهٔ خرید/فروش رسیده‌اند اعلام می‌شوند.
        </p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <input type="text" id="single_symbol" placeholder="نماد تک‌ارز: BTCUSDT" class="mono" style="width:150px">
        <button class="btn btn--indigo btn--sm" id="btn_single"><i class="fa-solid fa-crosshairs"></i> تحلیل تک‌ارز</button>
        <button class="btn btn--gold" id="btn_scan"><i class="fa-solid fa-satellite-dish"></i> رصد کل بازار</button>
    </div>
</div>

<?php if ($stats !== null): ?>
<div class="grid grid--6" style="margin-bottom:18px">
    <div class="stat stat--gold"><div class="stat__label"><i class="fa-solid fa-signal"></i> کل سیگنال‌ها</div><div class="stat__value"><?= m_persian_digits(number_format($stats['total'])) ?></div></div>
    <div class="stat stat--emerald"><div class="stat__label"><i class="fa-solid fa-arrow-trend-up"></i> خرید</div><div class="stat__value"><?= m_persian_digits(number_format($stats['buys'])) ?></div></div>
    <div class="stat stat--rose"><div class="stat__label"><i class="fa-solid fa-arrow-trend-down"></i> فروش</div><div class="stat__value"><?= m_persian_digits(number_format($stats['sells'])) ?></div></div>
    <div class="stat stat--indigo"><div class="stat__label"><i class="fa-solid fa-gauge-high"></i> میانگین امتیاز</div><div class="stat__value"><?= m_persian_digits((string)$stats['avg_score']) ?></div></div>
    <div class="stat stat--gold"><div class="stat__label"><i class="fa-solid fa-trophy"></i> بیشترین امتیاز</div><div class="stat__value"><?= m_persian_digits((string)$stats['max_score']) ?></div></div>
    <div class="stat stat--indigo"><div class="stat__label"><i class="fa-solid fa-rotate"></i> اسکن‌ها</div><div class="stat__value"><?= m_persian_digits(number_format($stats['scans'])) ?></div></div>
</div>
<?php endif; ?>

<!-- ── نتایج زنده اسکن ─────────────────────────────────────────────── -->
<div class="card-3d section">
    <div class="section__head">
        <h2 class="section__title"><i class="fa-solid fa-bolt"></i> سیگنال‌های صادرشده (عبورکرده از فیلترها)</h2>
        <span id="scan_meta" class="badge">در انتظار اسکن</span>
    </div>
    <div id="live_signals" class="stack"></div>
    <div id="no_signal" class="help" style="margin-top:8px">
        هنوز سیگنالی در این نشست صادر نشده. روی «رصد کل بازار» بزنید تا موتور، ارزهای عبورکرده از فیلترها را پیدا کند.
    </div>
</div>

<!-- ── تاریخچه ذخیره‌شده ──────────────────────────────────────────── -->
<?php if ($recent): ?>
<div class="card-3d section" style="margin-top:18px">
    <div class="section__head"><h2 class="section__title"><i class="fa-solid fa-clock-rotate-left"></i> تاریخچهٔ سیگنال‌های ذخیره‌شده</h2></div>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>نماد</th><th>جهت</th><th>اعتماد</th><th>تکنیکال</th><th>AI</th><th>ورود</th><th>استاپ</th><th>TP2</th><th>R:R</th><th>پوزیشن٪</th><th>فیلترها</th><th>زمان</th></tr></thead>
            <tbody>
            <?php foreach ($recent as $s): ?>
            <tr>
                <td class="mono" style="font-weight:700"><?= meelano_e($s['symbol']) ?></td>
                <td><span class="badge <?= $s['side'] === 'BUY' ? 'badge--ok' : 'badge--bad' ?>"><?= meelano_e($s['side']) ?></span></td>
                <td class="mono"><?= m_persian_digits((string)round((float)$s['combined_score'], 1)) ?></td>
                <td class="mono"><?= m_persian_digits((string)round((float)$s['tech_score'], 1)) ?></td>
                <td class="mono"><?= m_persian_digits((string)round((float)$s['ai_score'], 1)) ?></td>
                <td class="mono"><?= meelano_e(rtrim(rtrim(number_format((float)$s['entry_price'], 6), '0'), '.')) ?></td>
                <td class="mono" style="color:var(--rose)"><?= meelano_e(rtrim(rtrim(number_format((float)$s['stop_loss'], 6), '0'), '.')) ?></td>
                <td class="mono" style="color:var(--emerald)"><?= meelano_e(rtrim(rtrim(number_format((float)$s['take_profit_2'], 6), '0'), '.')) ?></td>
                <td class="mono"><?= m_persian_digits((string)round((float)$s['risk_reward'], 1)) ?></td>
                <td class="mono"><?= m_persian_digits((string)round((float)$s['position_pct'], 1)) ?></td>
                <td class="mono"><?= m_persian_digits((string)(int)$s['filters_passed']) ?>/<?= m_persian_digits((string)(int)$s['filters_total']) ?></td>
                <td style="font-size:11px;color:var(--text-mute)"><?= meelano_e((string)$s['created_at']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ── پارامترهای موتور ────────────────────────────────────────────── -->
<details class="collapse" style="margin-top:18px">
    <summary><i class="fa-solid fa-sliders"></i> پارامترهای فعلی موتور (از تنظیمات)</summary>
    <div class="grid grid--4" style="margin-top:14px">
        <div class="kv"><span>تایم‌فریم</span><span><?= meelano_e((string)($trading['timeframe'] ?? '1h')) ?></span></div>
        <div class="kv"><span>حداقل فیلتر عبوری</span><span><?= m_persian_digits((string)(int)($trading['min_filters_passed'] ?? 8)) ?></span></div>
        <div class="kv"><span>حداقل امتیاز تکنیکال</span><span><?= m_persian_digits((string)(float)($trading['min_tech_score'] ?? 62)) ?></span></div>
        <div class="kv"><span>حداقل امتیاز ترکیبی</span><span><?= m_persian_digits((string)(float)($trading['min_combined_score'] ?? 68)) ?></span></div>
        <div class="kv"><span>الزام اجماع AI</span><span><?= !empty($trading['require_ai_agreement']) ? 'بله' : 'خیر' ?></span></div>
        <div class="kv"><span>وزن تکنیکال / AI</span><span><?= m_persian_digits((string)(float)($trading['tech_weight'] ?? .6)) ?> / <?= m_persian_digits((string)(float)($trading['ai_weight'] ?? .4)) ?></span></div>
        <div class="kv"><span>ریسک هر معامله</span><span><?= m_persian_digits((string)(float)($trading['risk_per_trade_percent'] ?? 1)) ?>٪</span></div>
        <div class="kv"><span>حداقل حجم ۲۴س</span><span><?= m_persian_digits(number_format((float)($trading['min_quote_volume'] ?? 5000000))) ?></span></div>
    </div>
</details>

<?php m_layout_toasts(); ?>
<?php m_layout_footer(); ?>
</div>
<?php m_layout_foot(['assets/js/crypto.js']); ?>
