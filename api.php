<?php
/* ═══════════════════════════════════════════════════════════════
   Meelano Panel — MySQL JSON API  (v1)
   محل نصب روی هاست اشتراکی: کنار index.html (مثلاً /public_html/universal/api.php)
   امنیت: رمز دیتابیس هرگز داخل این فایلِ داخل گیت نیست —
     ۱) اگر فایل db_config.php کنار همین فایل باشد، از آن خوانده می‌شود،
     ۲) وگرنه مشخصات از بدنه درخواست (تنظیمات اپ، فقط روی دستگاه کاربر) می‌آید.
   ═══════════════════════════════════════════════════════════════ */
header('Content-Type: application/json; charset=utf-8');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') { header('Access-Control-Allow-Origin: ' . $origin); header('Vary: Origin'); }
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { exit; }

function out($arr, $code = 200) { http_response_code($code); echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }
function fa_err($msg) { out(['ok' => false, 'error' => $msg]); }
if (!function_exists('mb_substr')) { function mb_substr($s, $a, $b = null, $e = null) { return $b === null ? substr($s, $a) : substr($s, $a, $b); } }

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) $body = [];
$action = trim($body['action'] ?? ($_GET['action'] ?? ''));

$cfg = null; $source = 'client';
$srvFile = __DIR__ . '/db_config.php';
if (is_file($srvFile)) { $c = include $srvFile; if (is_array($c) && !empty($c['name'])) { $cfg = $c; $source = 'server'; } }
if (!$cfg) {
    $cfg = [
        'host' => trim($body['host'] ?? 'localhost'),
        'name' => trim($body['name'] ?? ''),
        'user' => trim($body['user'] ?? ''),
        'pass' => (string)($body['pass'] ?? ''),
    ];
}
if ($cfg['name'] === '' || $cfg['user'] === '') fa_err('نام دیتابیس یا نام کاربری وارد نشده است.');

function db() {
    global $cfg;
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    try {
        $m = new mysqli($cfg['host'] !== '' ? $cfg['host'] : 'localhost', $cfg['user'], $cfg['pass'], $cfg['name']);
    } catch (Throwable $e) { fa_err('اتصال به MySQL برقرار نشد؛ هاست، نام دیتابیس، کاربر یا رمز را بررسی کنید.'); }
    try { $m->set_charset('utf8mb4'); } catch (Throwable $e) {}
    return $m;
}

function ddl_map() {
    $tail = ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
        'products' => 'CREATE TABLE IF NOT EXISTS `products` ('
            . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . '`title` VARCHAR(255) NOT NULL,`brand` VARCHAR(150) NULL DEFAULT \'\','
            . '`barcode` VARCHAR(64) NULL DEFAULT \'\',`color` VARCHAR(255) NULL DEFAULT \'\','
            . '`category` VARCHAR(100) NULL DEFAULT \'\','
            . '`purchase_price` BIGINT NOT NULL DEFAULT 0,`min_sale_price` BIGINT NOT NULL DEFAULT 0,'
            . '`retail_price` BIGINT NOT NULL DEFAULT 0,`wholesale_price` BIGINT NOT NULL DEFAULT 0,'
            . '`stock` INT NOT NULL DEFAULT 0,`weight` DECIMAL(10,3) NOT NULL DEFAULT 0,'
            . '`length` DECIMAL(10,2) NOT NULL DEFAULT 0,`width` DECIMAL(10,2) NOT NULL DEFAULT 0,'
            . '`height` DECIMAL(10,2) NOT NULL DEFAULT 0,`tax_percent` DECIMAL(5,2) NOT NULL DEFAULT 9,'
            . '`description` TEXT NULL,`brief_json` TEXT NULL,`image_webp` MEDIUMTEXT NULL,'
            . '`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . '`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
            . 'PRIMARY KEY (`id`),KEY `idx_title` (`title`),KEY `idx_barcode` (`barcode`)' . $tail,
        'price_history' => 'CREATE TABLE IF NOT EXISTS `price_history` ('
            . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . '`t` BIGINT NOT NULL,`title` VARCHAR(255) NOT NULL,`brand` VARCHAR(150) NULL DEFAULT \'\','
            . '`digi` BIGINT NOT NULL DEFAULT 0,`torob` BIGINT NOT NULL DEFAULT 0,'
            . '`chosen` BIGINT NOT NULL DEFAULT 0,`retail` BIGINT NOT NULL DEFAULT 0,`whole` BIGINT NOT NULL DEFAULT 0,'
            . '`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . 'PRIMARY KEY (`id`),UNIQUE KEY `uq_t_title` (`t`,`title`),KEY `idx_title_t` (`title`,`t`)' . $tail,
        'app_meta' => 'CREATE TABLE IF NOT EXISTS `app_meta` ('
            . '`k` VARCHAR(100) NOT NULL,`v` TEXT NULL,'
            . '`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
            . 'PRIMARY KEY (`k`)' . $tail,
    ];
}

function table_list($m) {
    $r = $m->query('SHOW TABLES');
    $t = [];
    while ($row = $r->fetch_array()) $t[] = $row[0];
    return $t;
}

/* ── status: سلامت اتصال ─────────────────────────────── */
if ($action === 'status') {
    $m = db();
    try { $ver = $m->query('SELECT VERSION() AS v')->fetch_assoc()['v']; }
    catch (Throwable $e) { $ver = '?'; }
    out(['ok' => true, 'mysql' => $ver, 'tables' => table_list($m), 'source' => $source, 'time' => time()]);
}

/* ── init: ساخت یک جدول ──────────────────────────────── */
if ($action === 'init') {
    $map = ddl_map();
    $tb = trim($body['table'] ?? '');
    if (!isset($map[$tb])) fa_err('نام جدول معتبر نیست.');
    $m = db();
    try { $m->query($map[$tb]); }
    catch (Throwable $e) { fa_err('ساخت جدول «' . $tb . '» ناموفق بود.'); }
    out(['ok' => true, 'table' => $tb, 'tables' => table_list($m)]);
}

/* ── push_history: آپلود رکوردها ─────────────────────── */
if ($action === 'push_history') {
    $rows = $body['rows'] ?? [];
    if (!is_array($rows) || !count($rows)) fa_err('رکوردی برای ذخیره نیست.');
    $rows = array_slice($rows, 0, 200);
    $m = db();
    $st = $m->prepare('INSERT INTO `price_history` (`t`,`title`,`brand`,`digi`,`torob`,`chosen`,`retail`,`whole`) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE `brand`=VALUES(`brand`),`digi`=VALUES(`digi`),`torob`=VALUES(`torob`),`chosen`=VALUES(`chosen`),`retail`=VALUES(`retail`),`whole`=VALUES(`whole`)');
    $n = 0;
    foreach ($rows as $e) {
        if (!is_array($e) || empty($e['t']) || empty($e['title'])) continue;
        $t = (int)$e['t']; $title = mb_substr((string)$e['title'], 0, 255); $brand = mb_substr((string)($e['brand'] ?? ''), 0, 150);
        $d = (int)($e['digi'] ?? 0); $tr = (int)($e['torob'] ?? 0); $ch = (int)($e['chosen'] ?? 0);
        $rt = (int)($e['retail'] ?? 0); $wh = (int)($e['whole'] ?? 0);
        $st->bind_param('issiiiii', $t, $title, $brand, $d, $tr, $ch, $rt, $wh);
        try { $st->execute(); $n++; } catch (Throwable $ignored) {}
    }
    out(['ok' => true, 'saved' => $n]);
}

/* ── pull_history: دانلود رکوردها ────────────────────── */
if ($action === 'pull_history') {
    $since = (int)($body['since'] ?? 0);
    $m = db();
    $st = $m->prepare('SELECT `t`,`title`,`brand`,`digi`,`torob`,`chosen`,`retail`,`whole` FROM `price_history` WHERE `t`>=? ORDER BY `t` DESC LIMIT 200');
    $st->bind_param('i', $since);
    $st->execute();
    $rs = $st->get_result();
    $rows = [];
    while ($r = $rs->fetch_assoc()) {
        $rows[] = ['t' => (int)$r['t'], 'title' => $r['title'], 'brand' => $r['brand'], 'digi' => (int)$r['digi'],
                   'torob' => (int)$r['torob'], 'chosen' => (int)$r['chosen'], 'retail' => (int)$r['retail'], 'whole' => (int)$r['whole']];
    }
    out(['ok' => true, 'rows' => $rows, 'count' => count($rows)]);
}

/* ── save_product: ثبت محصول ─────────────────────────── */
if ($action === 'save_product') {
    $p = $body['product'] ?? [];
    if (!is_array($p) || trim($p['title'] ?? '') === '') fa_err('نام محصول خالی است.');
    $m = db();
    $img = (string)($p['image_webp'] ?? '');
    if (strlen($img) > 1500000) $img = ''; /* احتیاط حجم POST */
    $st = $m->prepare('INSERT INTO `products` (`title`,`brand`,`barcode`,`color`,`category`,`purchase_price`,`min_sale_price`,`retail_price`,`wholesale_price`,`stock`,`weight`,`length`,`width`,`height`,`tax_percent`,`description`,`brief_json`,`image_webp`) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $title = mb_substr(trim($p['title']), 0, 255); $brand = mb_substr((string)($p['brand'] ?? ''), 0, 150);
    $barcode = mb_substr((string)($p['barcode'] ?? ''), 0, 64); $color = mb_substr((string)($p['color'] ?? ''), 0, 255);
    $cat = mb_substr((string)($p['category'] ?? ''), 0, 100);
    $pp = (int)($p['purchase'] ?? 0); $mn = (int)($p['floor'] ?? 0); $rt = (int)($p['retail'] ?? 0); $wh = (int)($p['whole'] ?? 0);
    $st2 = (int)($p['stock'] ?? 0); $w = (float)($p['weight'] ?? 0); $l = (float)($p['length'] ?? 0);
    $wi = (float)($p['width'] ?? 0); $h = (float)($p['height'] ?? 0); $tx = (float)($p['tax'] ?? 9);
    $desc = (string)($p['desc'] ?? ''); $brief = mb_substr((string)($p['brief'] ?? ''), 0, 20000);
    $st->bind_param('sssssiiiiidddddsss', $title, $brand, $barcode, $color, $cat, $pp, $mn, $rt, $wh, $st2, $w, $l, $wi, $h, $tx, $desc, $brief, $img);
    try { $st->execute(); } catch (Throwable $e) { fa_err('ثبت محصول در دیتابیس ناموفق بود.'); }
    out(['ok' => true, 'id' => $m->insert_id]);
}

/* ── list_products: فهرست سبک ────────────────────────── */
if ($action === 'list_products') {
    $q = trim($body['q'] ?? '');
    $m = db();
    if ($q !== '') {
        $like = '%' . $q . '%';
        $st = $m->prepare('SELECT `id`,`title`,`brand`,`barcode`,`retail_price`,`wholesale_price`,`stock`,`updated_at` FROM `products` WHERE `title` LIKE ? OR `brand` LIKE ? OR `barcode` LIKE ? ORDER BY `id` DESC LIMIT 50');
        $st->bind_param('sss', $like, $like, $like);
    } else {
        $st = $m->prepare('SELECT `id`,`title`,`brand`,`barcode`,`retail_price`,`wholesale_price`,`stock`,`updated_at` FROM `products` ORDER BY `id` DESC LIMIT 50');
    }
    $st->execute();
    $rs = $st->get_result();
    $rows = [];
    while ($r = $rs->fetch_assoc()) $rows[] = $r;
    out(['ok' => true, 'rows' => $rows, 'count' => count($rows)]);
}

fa_err('درخواست نامعتبر است.');
