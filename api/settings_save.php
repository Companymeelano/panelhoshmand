<?php
/**
 * POST /api/settings_save.php — ذخیره تنظیمات (دیتابیس، AI، مسیریابی، قیمت‌گذاری).
 *
 * نکته امنیتی: فیلدهای کلید اگر خالی ارسال شوند، مقدار قبلی حفظ می‌شود
 * تا مرورگر (که فقط نسخه پنهان‌شده را دارد) کلیدها را پاک نکند.
 */

require __DIR__ . '/bootstrap.php';

use Meelano\Ai\Registry;
use Meelano\Config;
use Meelano\Security;

m_guard(true, 'settings');
$in = m_input();
$changed = [];

/* ── دیتابیس ─────────────────────────────────────────────────────────── */
if (isset($in['db']) && is_array($in['db'])) {
    foreach (['host', 'name', 'user', 'socket', 'charset', 'prefix'] as $field) {
        if (isset($in['db'][$field]) && $in['db'][$field] !== '') {
            Config::set('db.' . $field, m_clean_string($in['db'][$field], 120));
            $changed[] = 'db.' . $field;
        }
    }
    if (isset($in['db']['port']) && $in['db']['port'] !== '') {
        Config::set('db.port', (int)$in['db']['port']);
        $changed[] = 'db.port';
    }
    if (isset($in['db']['driver']) && in_array($in['db']['driver'], ['mysql', 'sqlite'], true)) {
        Config::set('db.driver', $in['db']['driver']);
        $changed[] = 'db.driver';
    }
    if (!empty($in['db']['pass'])) {
        Config::set('db.pass', (string)$in['db']['pass']);
        $changed[] = 'db.pass';
    }
}

/* ── ارائه‌دهندگان هوش مصنوعی ────────────────────────────────────────── */
if (isset($in['ai']['providers']) && is_array($in['ai']['providers'])) {
    foreach ($in['ai']['providers'] as $id => $values) {
        if (!isset(Registry::providers()[$id]) || !is_array($values)) {
            continue;
        }
        $meta = Registry::provider($id);
        $keyField = $meta['key_field'] ?? 'api_key';

        if (isset($values['enabled'])) {
            Config::set("ai.providers.{$id}.enabled", (bool)$values['enabled']);
        }
        // فقط وقتی مقدار غیرخالی است جایگزین کن (حفظ کلید موجود)
        foreach (['api_key', 'api_token', 'account_id', 'base_url', 'model', 'vision_model', 'embedding_model', 'image_model', 'video_model'] as $field) {
            if (!empty($values[$field])) {
                Config::set("ai.providers.{$id}.{$field}", m_clean_string($values[$field], 300));
                $changed[] = 'ai.providers.' . $id . '.' . $field;
            }
        }
        if (!empty($values[$keyField . '_clear'])) {
            Config::set("ai.providers.{$id}.{$keyField}", '');
            $changed[] = 'ai.providers.' . $id . '.' . $keyField . ' (پاک شد)';
        }
    }
}

/* ── مسیریابی دستی ──────────────────────────────────────────────────── */
if (isset($in['routing']) && is_array($in['routing'])) {
    if (isset($in['routing']['mode']) && in_array($in['routing']['mode'], ['auto', 'manual'], true)) {
        Config::set('routing.mode', $in['routing']['mode']);
        $changed[] = 'routing.mode';
    }
    if (isset($in['routing']['map']) && is_array($in['routing']['map'])) {
        $map = [];
        foreach ($in['routing']['map'] as $task => $provider) {
            if (isset(Registry::tasks()[$task]) && Registry::provider((string)$provider) !== null) {
                $map[$task] = (string)$provider;
            }
        }
        Config::set('routing.map', $map);
        $changed[] = 'routing.map';
    }
}

/* ── پارامترهای معامله و ریسک ────────────────────────────────────────── */
if (isset($in['trading']) && is_array($in['trading'])) {
    $numKeys = ['scan_limit','min_quote_volume','min_filters_passed','min_tech_score','min_combined_score','tech_weight','ai_weight','risk_per_trade_percent','atr_stop_multiplier','max_position_percent','cache_ttl'];
    foreach ($numKeys as $key) {
        if (isset($in['trading'][$key])) {
            Config::set('trading.' . $key, m_numeric($in['trading'][$key]));
            $changed[] = 'trading.' . $key;
        }
    }
    if (isset($in['trading']['timeframe'])) {
        Config::set('trading.timeframe', m_clean_string($in['trading']['timeframe'], 8));
        $changed[] = 'trading.timeframe';
    }
    if (isset($in['trading']['require_ai_agreement'])) {
        Config::set('trading.require_ai_agreement', (bool)$in['trading']['require_ai_agreement']);
        $changed[] = 'trading.require_ai_agreement';
    }
}

/* ── بازار ──────────────────────────────────────────────────────────── */
if (isset($in['market']) && is_array($in['market'])) {
    if (isset($in['market']['cache_ttl'])) {
        Config::set('market.cache_ttl', max(0, (int)$in['market']['cache_ttl']));
    }
    if (isset($in['market']['timeout'])) {
        Config::set('market.timeout', max(3, min(60, (int)$in['market']['timeout'])));
    }
    if (isset($in['market']['sources']) && is_array($in['market']['sources'])) {
        Config::set('market.sources', array_values(array_intersect($in['market']['sources'], ['torob', 'digikala'])));
    }
}

if (!Config::save()) {
    m_json(['ok' => false, 'error' => 'ذخیره تنظیمات ناموفق بود — مجوز نوشتن پوشه config را بررسی کنید.'], 500);
}

Security::audit('settings', 'save', implode('، ', array_slice($changed, 0, 40)));
m_json([
    'ok' => true,
    'changed' => $changed,
    'message' => 'تنظیمات با موفقیت ذخیره شد.',
    'config' => Config::publicView(),
]);
