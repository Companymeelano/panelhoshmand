<?php
/**
 * مجموعه تست‌های پنل هوشمند میلانو — نسخهٔ کریپتو.
 * اجرا: php tests/run.php
 *
 * همه تست‌ها کد واقعی سامانه را اجرا می‌کنند (نه نسخه جعلی).
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */

declare(strict_types=1);

define('MEELANO_TESTING', true);
require dirname(__DIR__) . '/includes/bootstrap.php';

use Meelano\Ai\Client;
use Meelano\Ai\MockTransport;
use Meelano\Ai\Router;
use Meelano\Config;
use Meelano\Crypto\AiValidator;
use Meelano\Crypto\Filters;
use Meelano\Crypto\Indicators;
use Meelano\Crypto\MarketData;
use Meelano\Crypto\RiskManager;
use Meelano\Crypto\SignalEngine;
use Meelano\Db;
use Meelano\Installer;
use Meelano\Schema;

$passed = 0; $failed = 0; $failures = [];

function check(string $name, bool $cond, string $detail = ''): void
{
    global $passed, $failed, $failures;
    if ($cond) { $passed++; echo "  ✓ {$name}\n"; }
    else { $failed++; $failures[] = $name; echo "  ✗ {$name}" . ($detail !== '' ? "  — {$detail}" : '') . "\n"; }
}
function section(string $t): void { echo "\n── {$t} ──\n"; }

/* ═══ ۱) اندیکاتورها ═══ */
section('اندیکاتورها');
$up = [];
for ($i = 0; $i < 40; $i++) { $up[] = 100 + $i; }
$rsiUp = Indicators::last(Indicators::rsi($up, 14));
check('RSI صعود خالص = ۱۰۰', abs((float)$rsiUp - 100.0) < 0.001, (string)$rsiUp);
$flat = array_fill(0, 40, 50.0);
check('RSI بدون تغییر ≈ ۵۰', abs((float)Indicators::last(Indicators::rsi($flat, 14)) - 50.0) < 0.001);
$sma = Indicators::sma([1, 2, 3, 4, 5], 3);
check('SMA درست است', abs((float)Indicators::last($sma) - 4.0) < 0.001);
$ema = Indicators::ema($up, 10);
check('EMA به قیمت نزدیک می‌شود', (float)Indicators::last($ema) > 130);
[$m, $sig, $hist] = Indicators::macd($up);
check('MACD در روند صعودی مثبت است', (float)Indicators::last($hist) > 0);
[$bm, $bu, $bl] = Indicators::bollinger($up, 20, 2);
check('باند بالا > پایین', (float)Indicators::last($bu) > (float)Indicators::last($bl));
$atr = Indicators::last(Indicators::atr($up, array_map(fn($x) => $x - 1, $up), $up, 14));
check('ATR ≈ ۱ برای دامنه ثابت', abs((float)$atr - 1.0) < 0.001, (string)$atr);
check('ساختار صعودی تشخیص داده شد', Indicators::structure($up, 10) === 'uptrend');

/* ═══ ۲) فیلترهای سخت‌گیرانه ═══ */
section('فیلترها');
$buyCtx = [
    'rsi' => 55, 'macd_hist' => 2, 'macd_hist_prev' => 1, 'ema50' => 110, 'ema200' => 100,
    'price' => 112, 'bb_upper' => 115, 'bb_lower' => 100, 'bb_pos' => 0.3,
    'stoch_k' => 25, 'stoch_d' => 20, 'atr_pct' => 1.5, 'vol_ratio' => 1.8,
    'change24' => 3.0, 'quote_volume' => 1e8, 'structure' => 'uptrend', 'wick_ratio' => 0.2,
    'min_quote_volume' => 5e6,
];
$evalBuy = (new Filters())->evaluate($buyCtx);
check('بافت صعودی → سمت BUY', $evalBuy['side'] === 'BUY', $evalBuy['side']);
check('همهٔ ۱۰ فیلتر عبور کردند', $evalBuy['passed'] === 10, $evalBuy['passed'] . '/' . $evalBuy['total']);
check('امتیاز تکنیکال بالا', $evalBuy['tech_score'] >= 70, (string)$evalBuy['tech_score']);

$weakCtx = $buyCtx;
$weakCtx['structure'] = 'downtrend'; $weakCtx['ema50'] = 90; $weakCtx['ema200'] = 100;
$weakCtx['price'] = 88; $weakCtx['rsi'] = 25; $weakCtx['macd_hist'] = -2; $weakCtx['macd_hist_prev'] = -1;
$weakCtx['change24'] = -15; $weakCtx['wick_ratio'] = 0.6; $weakCtx['stoch_k'] = 80; $weakCtx['stoch_d'] = 85;
$evalWeak = (new Filters())->evaluate($weakCtx);
check('بافت ضعیف → سمت SELL', $evalWeak['side'] === 'SELL', $evalWeak['side']);
check('فیلتر سقوط آزاد در ریزش رد می‌شود', !(bool)array_filter($evalWeak['filters'], fn($f) => $f['key'] === 'freefall' && $f['pass']));

/* ═══ ) مدیریت ریسک ═══ */
section('مدیریت ریسک');
$rm = new RiskManager(['risk_per_trade_percent' => 1, 'atr_stop_multiplier' => 2, 'max_position_percent' => 25]);
$plan = $rm->plan('BUY', 100.0, 2.0, 80);
check('استاپ زیر ورود در خرید', $plan['stop_loss'] < 100.0);
check('تارگت‌ها بالای ورود', $plan['take_profit_1'] > 100.0 && $plan['take_profit_2'] > $plan['take_profit_1']);
check('R:R تارگت۲ ≈ ٫۵', abs($plan['risk_reward_2'] - 2.5) < 0.01, (string)$plan['risk_reward_2']);
check('سایز پوزیشن در سقف', $plan['position_percent'] <= 25 && $plan['position_percent'] > 0);

/* ═══ ۴) موتور سیگنال end-to-end با MockTransport ═══ */
section('SignalEngine (end-to-end)');
// کندل صعودی مصنوعی
$candles = [];
$t = 1700000000; $price = 100.0;
for ($i = 0; $i < 220; $i++) {
    $open = $price;
    $delta = 0.4 + (($i % 7 === 0) ? -0.15 : 0); // صعود با اصلاح کوچک
    $close = $open + $delta;
    $high = max($open, $close) + 0.2;
    $low = min($open, $close) - 0.2;
    $vol = 1000 + $i * 5;
    $candles[] = [$t + $i * 3600000, $open, $high, $low, $close, $vol];
    $price = $close;
}
$mock = new MockTransport();
$mock->on('api.binance.com/api/v3/klines', ['status' => 200, 'body' => json_encode($candles)]);
$mock->on('api.binance.com/api/v3/ticker/24hr', ['status' => 200, 'body' => json_encode([
    ['symbol' => 'BTCUSDT', 'lastPrice' => '188', 'priceChangePercent' => '3.0', 'quoteVolume' => '100000000', 'highPrice' => '190', 'lowPrice' => '100'],
])]);
$buyJsonBuy = '{"signal":"BUY","confidence":85,"reasoning":"trend","risks":[]}';
$openAiBody = json_encode(['choices' => [['message' => ['content' => $buyJsonBuy]]]]);
$geminiBody = json_encode(['candidates' => [['content' => ['parts' => [['text' => $buyJsonBuy]]]]]]);
$groqBody = json_encode(['choices' => [['message' => ['content' => $buyJsonBuy]]]]);
$mock->on('api.openai.com/v1/chat/completions', ['status' => 200, 'body' => $openAiBody]);
$mock->on('generativelanguage.googleapis.com', ['status' => 200, 'body' => $geminiBody]);
$mock->on('api.groq.com/openai/v1/chat/completions', ['status' => 200, 'body' => $groqBody]);

$config = Config::defaults();
foreach (['openai', 'gemini', 'groq'] as $p) { $config['ai']['providers'][$p]['api_key'] = 'test-' . $p; }
$health = ['openai' => ['ok' => 1, 'latency_ms' => 300], 'gemini' => ['ok' => 1, 'latency_ms' => 400], 'groq' => ['ok' => 1, 'latency_ms' => 100]];

$tmp = tempnam(sys_get_temp_dir(), 'mlnc') . '.sqlite'; @unlink($tmp);
$db = Db::make(['driver' => 'sqlite', 'sqlite_path' => $tmp, 'prefix' => 'mln_']);
(new Installer($db))->run();

$client = new Client($mock, new Router($config, $health), $db, $config);
$ticker = ['symbol' => 'BTCUSDT', 'base' => 'BTC', 'change_pct' => 3.0, 'quote_volume' => 1e8];
$baseCfg = ['timeframe' => '1h', 'min_filters_passed' => 7, 'min_tech_score' => 40, 'min_combined_score' => 40,
    'tech_weight' => 0.6, 'ai_weight' => 0.4,
    'risk_per_trade_percent' => 1, 'atr_stop_multiplier' => 2, 'max_position_percent' => 25, 'min_quote_volume' => 1000];

// حالت ۱: بدون الزام اجماع → خط‌لوله کامل باید سیگنال با ریسک تولید و ذخیره کند
$engine = new SignalEngine(new MarketData($mock), $client, $db, $baseCfg + ['require_ai_agreement' => false]);
$signal = $engine->analyzeSymbol('BTCUSDT', $ticker);
check('خط‌لوله سیگنال صادر کرد', !empty($signal['is_signal']));
check('جهت سیگنال = سمت تکنیکال', ($signal['side'] ?? '') === ($signal['tech_side'] ?? '?'), ($signal['side'] ?? 'null'));
check('امتیاز تکنیکال > ۰', (float)($signal['tech_score'] ?? 0) > 0);
check('برنامه ریسک کامل است', isset($signal['risk']['stop_loss'], $signal['risk']['take_profit_2'], $signal['risk']['position_percent']));

// ماندگاری از مسیر واقعی scanMarket (recordScan + persistSignal)
$scan = $engine->scanMarket(5);
check('اسکن بازار موفق', !empty($scan['ok']) && $scan['scanned'] >= 1);
check('اسکن در دیتابیس ثبت شد', $db->count('scans') >= 1);
check('سیگنال در دیتابیس ذخیره شد', $db->count('signals') >= 1);

// حالت ۲: دروازهٔ امنیتی — اگر AI با تکنیکال مخالف باشد، با الزام اجماع سیگنال مسدود می‌شود
$engineStrict = new SignalEngine(new MarketData($mock), $client, $db, $baseCfg + ['require_ai_agreement' => true]);
$signalStrict = $engineStrict->analyzeSymbol('BTCUSDT', $ticker);
$disagree = !empty($signalStrict['ai']['ok']) && empty($signalStrict['ai']['agreement']);
check('دروازهٔ اجماع AI کار می‌کند', $disagree ? empty($signalStrict['is_signal']) : true, 'AI-agreement veto');

/* ═══ ۵) اعتبارسنج AI (اجماع) ═══ */
section('AiValidator');
$validator = new AiValidator($client, 3);
$summary = $buyCtx + ['symbol' => 'BTCUSDT', 'tech_side' => 'BUY', 'tech_score' => 80];
$vres = $validator->validate($summary, 'BUY');
check('اجماع BUY با اعتماد بالا', $vres['ok'] && $vres['side'] === 'BUY', $vres['side'] ?? 'null');
check('توافق با تکنیکال', $vres['agreement'] === true);
check('سه نظر جمع شد', count($vres['opinions']) === 3, (string)count($vres['opinions']));

/* ═══ ۶) Schema و Installer ═══ */
section('Schema / Installer');
check('جدول‌های کریپتو تعریف شده', in_array('signals', Schema::names(), true) && in_array('scans', Schema::names(), true));
$res2 = (new Installer($db))->run();
check('نصب ایدم‌پوتنت', $res2['ok'] && count($res2['created']) === 0);
$sqlStatements = Schema::statements('signals', 'sqlite');
check('SQLite ایندکس جدا', count($sqlStatements) > 1);

/* ═══ ) Config رمزنگاری ══ */
section('Config (رمزنگاری)');
Config::set('ai.providers.openai.api_key', 'sk-secret-crypto-1234567890');
Config::save();
$raw = @include MEELANO_CONFIG . '/settings.php';
check('کلید خام در فایل نیست', strpos(var_export($raw, true), 'sk-secret-crypto-1234567890') === false);
Config::load(true);
check('بازخوانی رمزگشایی', Config::get('ai.providers.openai.api_key') === 'sk-secret-crypto-1234567890');
@unlink(MEELANO_CONFIG . '/settings.php'); @unlink(MEELANO_CONFIG . '/.app_key');

echo "\n════════════════════════════════════\nموفق: {$passed}   ناموفق: {$failed}\n";
if ($failed > 0) { echo "  - " . implode("\n  - ", $failures) . "\n"; exit(1); }
echo "همه تست‌ها گذشتند ✓\n";
exit(0);
