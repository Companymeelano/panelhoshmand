<?php
/**
 * POST /api/scan.php — اجرای موتور سیگنال کریپتو.
 * ورودی: {symbol?: "BTCUSDT"} برای تحلیل تک‌ارز؛ بدون symbol → رصد کل بازار.
 */

require __DIR__ . '/bootstrap.php';

use Meelano\Ai\Client;
use Meelano\Ai\Health;
use Meelano\Crypto\MarketData;
use Meelano\Crypto\SignalEngine;
use Meelano\Db;
use Meelano\Security;

m_guard(false, 'scan');
$in = m_input();
Security::requireRateLimit('scan', 12);

$db = null;
try {
    $candidate = Db::make();
    $db = $candidate->isConnected() ? $candidate : null;
} catch (Throwable $e) {
    $db = null;
}

$ai = null;
try {
    $client = new Client(null, null, $db);
    if ($db !== null) {
        $client->setHealth(Health::cached($db));
    }
    $ai = $client;
} catch (Throwable $e) {
    $ai = null;
}

$engine = new SignalEngine(new MarketData(), $ai, $db);

$symbol = m_clean_string($in['symbol'] ?? '', 20);
if ($symbol !== '') {
    $signal = $engine->analyzeSymbol($symbol);
    m_json([
        'ok' => true,
        'mode' => 'single',
        'signal' => $signal,
        'is_signal' => !empty($signal['is_signal']),
    ]);
}

$result = $engine->scanMarket();
m_json([
    'ok' => (bool)$result['ok'],
    'mode' => 'market',
    'scanned' => $result['scanned'],
    'signals' => $result['signals'],
    'signal_count' => count($result['signals']),
    'ai_used' => $result['ai_used'],
    'duration_ms' => $result['duration_ms'],
    'errors' => $result['errors'],
]);
