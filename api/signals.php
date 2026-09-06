<?php
/**
 * GET|POST /api/signals.php — فهرست سیگنال‌ها، آمار و فهرست رصد.
 * ورودی: {action: list|stats|watch_add|watch_list|watch_remove, symbol?}
 */

require __DIR__ . '/bootstrap.php';

use Meelano\Db;
use Meelano\Security;

m_guard(false, 'signals');
$in = m_input();
$action = (string)($in['action'] ?? ($_GET['action'] ?? 'list'));

$db = Db::make();
if (!$db->isConnected()) {
    m_json(['ok' => false, 'error' => 'اتصال دیتابیس برقرار نیست.'], 503);
}

switch ($action) {
    case 'stats':
        $t = $db->table('signals');
        $row = $db->tableExists('signals') ? $db->selectOne("
            SELECT COUNT(*) AS total,
                   SUM(CASE WHEN side='BUY' THEN 1 ELSE 0 END) AS buys,
                   SUM(CASE WHEN side='SELL' THEN 1 ELSE 0 END) AS sells,
                   COALESCE(AVG(combined_score),0) AS avg_score,
                   COALESCE(MAX(combined_score),0) AS max_score
            FROM {$t}") : [];
        $scans = $db->tableExists('scans') ? $db->count('scans') : 0;
        m_json(['ok' => true, 'stats' => [
            'total' => (int)($row['total'] ?? 0),
            'buys' => (int)($row['buys'] ?? 0),
            'sells' => (int)($row['sells'] ?? 0),
            'avg_score' => round((float)($row['avg_score'] ?? 0), 1),
            'max_score' => round((float)($row['max_score'] ?? 0), 1),
            'scans' => $scans,
        ]]);
        break;

    case 'watch_add':
        Security::requireCsrf();
        $symbol = strtoupper(m_clean_string($in['symbol'] ?? '', 20));
        if ($symbol === '') { m_json(['ok' => false, 'error' => 'نماد لازم است.'], 422); }
        try {
            $db->insert('watchlist', ['symbol' => $symbol, 'is_active' => 1, 'created_at' => date('Y-m-d H:i:s')]);
            m_json(['ok' => true, 'message' => $symbol . ' به فهرست رصد اضافه شد.']);
        } catch (Throwable $e) {
            m_json(['ok' => false, 'error' => 'قبلاً اضافه شده است.']);
        }
        break;

    case 'watch_remove':
        Security::requireCsrf();
        $symbol = strtoupper(m_clean_string($in['symbol'] ?? '', 20));
        $db->execute('DELETE FROM ' . $db->table('watchlist') . ' WHERE symbol = ?', [$symbol]);
        m_json(['ok' => true, 'message' => $symbol . ' حذف شد.']);
        break;

    case 'watch_list':
        m_json(['ok' => true, 'items' => $db->tableExists('watchlist') ? $db->select('SELECT * FROM ' . $db->table('watchlist') . ' ORDER BY id DESC') : []]);
        break;

    case 'list':
    default:
        $limit = max(1, min(100, (int)m_numeric($in['limit'] ?? 30, 30)));
        $items = $db->tableExists('signals')
            ? $db->select('SELECT * FROM ' . $db->table('signals') . ' ORDER BY id DESC LIMIT ' . $limit)
            : [];
        m_json(['ok' => true, 'items' => $items, 'total' => count($items)]);
        break;
}
