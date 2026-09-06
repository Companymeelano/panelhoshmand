<?php
namespace Meelano\Ai;

use Meelano\Config;
use Meelano\Db;
use Meelano\Logger;
use Throwable;

/**
 * کلاینت یکپارچه هوش مصنوعی — ۱۲ ارائه‌دهنده، ۵ پروتکل، مسیریابی خودکار،
 * تلاش مجدد، زنجیره پشتیبان و کارنامه‌نویسی در دیتابیس.
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
final class Client
{
    /** @var Transport */
    private $transport;
    /** @var Router */
    private $router;
    /** @var Db|null */
    private $db;
    /** @var array */
    private $config;

    public function __construct(?Transport $transport = null, ?Router $router = null, ?Db $db = null, ?array $config = null)
    {
        $this->config = $config ?: Config::all();
        $this->transport = $transport ?: new CurlTransport();
        $this->router = $router ?: new Router($this->config);
        $this->db = $db;
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function setHealth(array $health): void
    {
        $this->router = new Router($this->config, $health);
    }

    /* ══════════════════════════════════════════════════════════════════
     *  ۱) متن / JSON / بینایی
     * ══════════════════════════════════════════════════════════════════ */

    /**
     * فراخوانی متنی با مسیریابی و زنجیره پشتیبان.
     *
     * @return array{ok:bool,text:string,provider:?string,model:?string,latency_ms:float,error:?string,attempts:array}
     */
    public function complete(string $task, string $prompt, array $opts = []): array
    {
        $taskDef = Registry::task($task) ?: ['max_tokens' => 800, 'requires' => [Registry::CAP_CHAT]];
        $system = $opts['system'] ?? $this->defaultSystem($task);
        $jsonMode = $opts['json'] ?? in_array(Registry::CAP_JSON, $taskDef['requires'], true);
        $maxTokens = (int)($opts['max_tokens'] ?? $taskDef['max_tokens'] ?? 800);
        $temperature = isset($opts['temperature']) ? (float)$opts['temperature'] : (float)$this->config['ai']['temperature'];
        $image = $opts['image'] ?? null; // base64 برای بینایی ماشین

        $chain = [];
        if (!empty($opts['provider'])) {
            $chain = [(string)$opts['provider']];
        } else {
            $chain = $this->router->fallbackChain($task, 3);
        }
        if (!$chain) {
            return $this->fail('هیچ ارائه‌دهنده فعالی برای وظیفه «' . $task . '» پیدا نشد. کلیدها را در تنظیمات بررسی کنید.', $task);
        }

        $attempts = [];
        foreach ($chain as $providerId) {
            $meta = Registry::provider($providerId);
            $cfg = $this->config['ai']['providers'][$providerId] ?? [];
            $model = $this->modelFor($providerId, $image !== null);

            $started = m_microtime();
            try {
                $res = $this->dispatchText($meta['protocol'], $providerId, $cfg, $model, $system, $prompt, $maxTokens, $temperature, $jsonMode, $image);
            } catch (Throwable $e) {
                $res = ['ok' => false, 'text' => '', 'error' => $e->getMessage(), 'http_code' => 0, 'usage' => []];
            }
            $latency = round((m_microtime() - $started) * 1000, 1);

            $attempts[] = ['provider' => $providerId, 'ok' => $res['ok'], 'ms' => $latency, 'error' => $res['error'] ?? null];
            $this->log($task, $providerId, $model, $res, $latency, $prompt);

            if ($res['ok'] && trim((string)$res['text']) !== '') {
                return [
                    'ok' => true,
                    'text' => (string)$res['text'],
                    'provider' => $providerId,
                    'provider_label' => $meta['label'],
                    'model' => $model,
                    'latency_ms' => $latency,
                    'usage' => $res['usage'] ?? [],
                    'error' => null,
                    'attempts' => $attempts,
                ];
            }
            Logger::write('ai', sprintf('وظیفه %s روی %s ناموفق: %s', $task, $providerId, $res['error'] ?? 'پاسخ خالی'), 'warning');
        }

        return $this->fail('همه ارائه‌دهندگان برای «' . $task . '» پاسخ ناموفق دادند.', $task, $attempts);
    }

    /** فراخوانی با خروجی JSON ساخت‌یافته + پارس مقاوم. */
    public function json(string $task, string $prompt, array $opts = []): array
    {
        $res = $this->complete($task, $prompt, array_merge($opts, ['json' => true]));
        if (!$res['ok']) {
            return $res + ['data' => null];
        }
        $data = self::extractJson($res['text']);
        if ($data === null) {
            $res['ok'] = false;
            $res['error'] = 'پاسخ مدل JSON معتبر نبود.';
            $res['data'] = null;
            return $res;
        }
        $res['data'] = $data;
        return $res;
    }

    /* ══════════════════════════════════════════════════════════════════
     *  ۲) تصویر
     * ══════════════════════════════════════════════════════════════════ */

    /**
     * تولید تصویر. خروجی: بایت‌های خام یا URL.
     *
     * @return array{ok:bool,binary:?string,url:?string,mime:string,provider:?string,model:?string,latency_ms:float,error:?string,attempts:array}
     */
    public function image(string $prompt, array $opts = []): array
    {
        $task = $opts['task'] ?? 'image.product';
        $chain = !empty($opts['provider']) ? [(string)$opts['provider']] : $this->router->fallbackChain($task, 4);
        if (!$chain) {
            return ['ok' => false, 'binary' => null, 'url' => null, 'mime' => '', 'provider' => null, 'model' => null, 'latency_ms' => 0, 'error' => 'موتور تصویر فعالی تنظیم نشده است.', 'attempts' => []];
        }

        $attempts = [];
        foreach ($chain as $providerId) {
            $meta = Registry::provider($providerId);
            $cfg = $this->config['ai']['providers'][$providerId] ?? [];
            $model = (string)($cfg['image_model'] ?? '');
            $started = m_microtime();
            try {
                $res = $this->dispatchImage($meta['protocol'], $providerId, $cfg, $model, $prompt, $opts);
            } catch (Throwable $e) {
                $res = ['ok' => false, 'error' => $e->getMessage(), 'binary' => null, 'url' => null, 'mime' => ''];
            }
            $latency = round((m_microtime() - $started) * 1000, 1);
            $attempts[] = ['provider' => $providerId, 'ok' => (bool)$res['ok'], 'ms' => $latency, 'error' => $res['error'] ?? null];
            $this->log($task, $providerId, $model, ['ok' => $res['ok'], 'error' => $res['error'] ?? null, 'http_code' => $res['http_code'] ?? 0, 'usage' => []], $latency, $prompt);

            if (!empty($res['ok'])) {
                return [
                    'ok' => true,
                    'binary' => $res['binary'] ?? null,
                    'url' => $res['url'] ?? null,
                    'mime' => $res['mime'] ?? 'image/png',
                    'provider' => $providerId,
                    'provider_label' => $meta['label'],
                    'model' => $model,
                    'latency_ms' => $latency,
                    'error' => null,
                    'attempts' => $attempts,
                ];
            }
        }
        return ['ok' => false, 'binary' => null, 'url' => null, 'mime' => '', 'provider' => null, 'model' => null, 'latency_ms' => 0, 'error' => 'همه موتورهای تصویر ناموفق بودند.', 'attempts' => $attempts];
    }

    /* ══════════════════════════════════════════════════════════════════
     *  ۳) بردار معنایی (Embedding)
     * ══════════════════════════════════════════════════════════════════ */

    public function embed(string $text, array $opts = []): array
    {
        $task = 'embed.similarity';
        $chain = !empty($opts['provider']) ? [(string)$opts['provider']] : $this->router->fallbackChain($task, 3);
        foreach ($chain as $providerId) {
            $meta = Registry::provider($providerId);
            $cfg = $this->config['ai']['providers'][$providerId] ?? [];
            $model = (string)($cfg['embedding_model'] ?? '');
            if ($model === '') {
                continue;
            }
            try {
                $res = $this->dispatchEmbed($meta['protocol'], $providerId, $cfg, $model, $text);
                if (!empty($res['ok']) && !empty($res['vector'])) {
                    return ['ok' => true, 'vector' => $res['vector'], 'provider' => $providerId, 'model' => $model, 'dimensions' => count($res['vector'])];
                }
            } catch (Throwable $e) {
                continue;
            }
        }
        return ['ok' => false, 'vector' => [], 'provider' => null, 'model' => null, 'dimensions' => 0, 'error' => 'هیچ موتور embedding فعالی پاسخ نداد.'];
    }

    /* ══════════════════════════════════════════════════════════════════
     *  ۴) ویدیو (ناهمگام)
     * ══════════════════════════════════════════════════════════════════ */

    public function videoCreate(string $prompt, array $opts = []): array
    {
        $task = 'video.product';
        $chain = !empty($opts['provider']) ? [(string)$opts['provider']] : $this->router->fallbackChain($task, 3);
        if (!$chain) {
            return ['ok' => false, 'error' => 'موتور ویدیویی فعالی تنظیم نشده است.'];
        }
        $providerId = $chain[0];
        $meta = Registry::provider($providerId);
        $cfg = $this->config['ai']['providers'][$providerId] ?? [];
        try {
            $res = $this->dispatchVideo($meta['protocol'], $providerId, $cfg, $prompt, $opts);
            $res['provider'] = $providerId;
            $res['provider_label'] = $meta['label'];
            $this->log($task, $providerId, (string)($cfg['video_model'] ?? ''), ['ok' => $res['ok'], 'error' => $res['error'] ?? null, 'http_code' => 0, 'usage' => []], 0, $prompt);
            return $res;
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'provider' => $providerId];
        }
    }

    /* ══════════════════════════════════════════════════════════════════
     *  پروتکل‌ها — متن
     * ══════════════════════════════════════════════════════════════════ */

    private function dispatchText(string $protocol, string $id, array $cfg, string $model, string $system, string $prompt, int $maxTokens, float $temp, bool $jsonMode, ?string $imageBase64): array
    {
        switch ($protocol) {
            case 'openai':
                return $this->textOpenAi($cfg, $model, $system, $prompt, $maxTokens, $temp, $jsonMode, $imageBase64);
            case 'gemini':
                return $this->textGemini($cfg, $model, $system, $prompt, $maxTokens, $temp, $jsonMode, $imageBase64);
            case 'cloudflare':
                return $this->textCloudflare($cfg, $model, $system, $prompt, $maxTokens, $temp);
            default:
                return ['ok' => false, 'text' => '', 'error' => 'پروتکل متنی پشتیبانی‌نشده: ' . $protocol, 'http_code' => 0, 'usage' => []];
        }
    }

    private function textOpenAi(array $cfg, string $model, string $system, string $prompt, int $maxTokens, float $temp, bool $jsonMode, ?string $imageBase64): array
    {
        if ($imageBase64 !== null) {
            $userContent = [
                ['type' => 'text', 'text' => $prompt],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . $imageBase64]],
            ];
        } else {
            $userContent = $prompt;
        }
        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $userContent],
            ],
            'max_tokens' => $maxTokens,
            'temperature' => $temp,
        ];
        if ($jsonMode) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $url = rtrim((string)($cfg['base_url'] ?? 'https://api.openai.com/v1'), '/') . '/chat/completions';
        $res = $this->transport->request('POST', $url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . ($cfg['api_key'] ?? ''),
            ],
            'body' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'timeout' => (int)($this->config['ai']['default_timeout'] ?? 45),
        ]);

        $data = json_decode($res['body'], true);
        if ($res['status'] >= 200 && $res['status'] < 300 && isset($data['choices'][0]['message']['content'])) {
            return [
                'ok' => true,
                'text' => (string)$data['choices'][0]['message']['content'],
                'error' => null,
                'http_code' => $res['status'],
                'usage' => [
                    'prompt_tokens' => (int)($data['usage']['prompt_tokens'] ?? 0),
                    'completion_tokens' => (int)($data['usage']['completion_tokens'] ?? 0),
                ],
            ];
        }
        return [
            'ok' => false, 'text' => '',
            'error' => $this->extractError($data, $res),
            'http_code' => $res['status'], 'usage' => [],
        ];
    }

    private function textGemini(array $cfg, string $model, string $system, string $prompt, int $maxTokens, float $temp, bool $jsonMode, ?string $imageBase64): array
    {
        $parts = [];
        if ($imageBase64 !== null) {
            $parts[] = ['inline_data' => ['mime_type' => 'image/jpeg', 'data' => $imageBase64]];
        }
        $parts[] = ['text' => $prompt];

        $payload = [
            'system_instruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => [
                'temperature' => $temp,
                'maxOutputTokens' => $maxTokens,
            ],
        ];
        if ($jsonMode) {
            $payload['generationConfig']['responseMimeType'] = 'application/json';
        }

        $base = rtrim((string)($cfg['base_url'] ?? 'https://generativelanguage.googleapis.com/v1beta'), '/');
        $url = $base . '/models/' . rawurlencode($model) . ':generateContent?key=' . urlencode((string)($cfg['api_key'] ?? ''));

        $res = $this->transport->request('POST', $url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'timeout' => (int)($this->config['ai']['default_timeout'] ?? 45),
        ]);
        $data = json_decode($res['body'], true);
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if ($res['status'] >= 200 && $res['status'] < 300 && $text !== null) {
            return [
                'ok' => true,
                'text' => (string)$text,
                'error' => null,
                'http_code' => $res['status'],
                'usage' => [
                    'prompt_tokens' => (int)($data['usageMetadata']['promptTokenCount'] ?? 0),
                    'completion_tokens' => (int)($data['usageMetadata']['candidatesTokenCount'] ?? 0),
                ],
            ];
        }
        return ['ok' => false, 'text' => '', 'error' => $this->extractError($data, $res), 'http_code' => $res['status'], 'usage' => []];
    }

    private function textCloudflare(array $cfg, string $model, string $system, string $prompt, int $maxTokens, float $temp): array
    {
        $account = (string)($cfg['account_id'] ?? '');
        $url = rtrim((string)($cfg['base_url'] ?? 'https://api.cloudflare.com/client/v4/accounts'), '/')
            . '/' . rawurlencode($account) . '/ai/run/' . $model;
        $payload = [
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $prompt],
            ],
            'max_tokens' => $maxTokens,
            'temperature' => $temp,
        ];
        $res = $this->transport->request('POST', $url, [
            'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . ($cfg['api_token'] ?? '')],
            'body' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'timeout' => (int)($this->config['ai']['default_timeout'] ?? 45),
        ]);
        $data = json_decode($res['body'], true);
        if ($res['status'] >= 200 && $res['status'] < 300 && isset($data['result']['response'])) {
            return ['ok' => true, 'text' => (string)$data['result']['response'], 'error' => null, 'http_code' => $res['status'], 'usage' => []];
        }
        return ['ok' => false, 'text' => '', 'error' => $this->extractError($data, $res), 'http_code' => $res['status'], 'usage' => []];
    }

    /* ══════════════════════════════════════════════════════════════════
     *  پروتکل‌ها — تصویر
     * ══════════════════════════════════════════════════════════════════ */

    private function dispatchImage(string $protocol, string $id, array $cfg, string $model, string $prompt, array $opts): array
    {
        $size = (int)($opts['size'] ?? 768);
        switch ($protocol) {
            case 'stability':
                return $this->imageStability($cfg, $model, $prompt, $size);
            case 'deepai':
                return $this->imageDeepAi($cfg, $model, $prompt);
            case 'fal':
                return $this->imageFal($cfg, $model, $prompt, $size);
            case 'cloudflare':
                return $this->imageCloudflare($cfg, $model, $prompt, $size);
            case 'openai':
                return $this->imageOpenAi($cfg, $model, $prompt, $size);
            case 'piapi':
                return $this->imagePiApi($cfg, $prompt);
            default:
                return ['ok' => false, 'error' => 'پروتکل تصویری پشتیبانی‌نشده: ' . $protocol, 'binary' => null, 'url' => null, 'mime' => ''];
        }
    }

    private function imageStability(array $cfg, string $model, string $prompt, int $size): array
    {
        $engine = $model !== '' ? $model : 'sd3-core';
        $boundary = 'meelano' . bin2hex(random_bytes(8));
        $fields = [
            'prompt' => $prompt,
            'output_format' => 'png',
            'aspect_ratio' => '1:1',
            'style_preset' => 'photographic',
        ];
        $body = '';
        foreach ($fields as $k => $v) {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$k}\"\r\n\r\n{$v}\r\n";
        }
        $body .= "--{$boundary}--\r\n";

        $res = $this->transport->request('POST', rtrim((string)($cfg['base_url'] ?? 'https://api.stability.ai'), '/') . '/v2beta/stable-image/generate/' . $engine, [
            'headers' => [
                'Authorization' => 'Bearer ' . ($cfg['api_key'] ?? ''),
                'Accept' => 'image/*',
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            ],
            'body' => $body,
            'timeout' => 120,
        ]);
        if ($res['status'] >= 200 && $res['status'] < 300 && $res['body'] !== '') {
            $isImage = strncmp($res['body'], '{"', 2) !== 0;
            if ($isImage) {
                return ['ok' => true, 'binary' => $res['body'], 'url' => null, 'mime' => 'image/png', 'http_code' => $res['status'], 'error' => null];
            }
            $data = json_decode($res['body'], true);
            if (!empty($data['image'])) {
                $bin = base64_decode((string)$data['image'], true);
                if ($bin !== false) {
                    return ['ok' => true, 'binary' => $bin, 'url' => null, 'mime' => 'image/png', 'http_code' => $res['status'], 'error' => null];
                }
            }
        }
        return ['ok' => false, 'binary' => null, 'url' => null, 'mime' => '', 'http_code' => $res['status'], 'error' => $this->extractError(json_decode($res['body'], true), $res)];
    }

    private function imageDeepAi(array $cfg, string $model, string $prompt): array
    {
        $endpoint = $model !== '' ? $model : 'torbo-diffusion';
        $res = $this->transport->request('POST', rtrim((string)($cfg['base_url'] ?? 'https://api.deepai.org/api'), '/') . '/' . $endpoint, [
            'headers' => ['api-key' => (string)($cfg['api_key'] ?? ''), 'Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => http_build_query(['text' => $prompt]),
            'timeout' => 120,
        ]);
        $data = json_decode($res['body'], true);
        if ($res['status'] >= 200 && $res['status'] < 300 && !empty($data['output_url'])) {
            return ['ok' => true, 'binary' => null, 'url' => (string)$data['output_url'], 'mime' => 'image/png', 'http_code' => $res['status'], 'error' => null];
        }
        return ['ok' => false, 'binary' => null, 'url' => null, 'mime' => '', 'http_code' => $res['status'], 'error' => $this->extractError($data, $res)];
    }

    private function imageFal(array $cfg, string $model, string $prompt, int $size): array
    {
        $model = $model !== '' ? $model : 'fal-ai/flux/dev';
        $payload = [
            'prompt' => $prompt,
            'image_size' => ['width' => $size, 'height' => $size],
            'num_images' => 1,
            'output_format' => 'png',
        ];
        $res = $this->transport->request('POST', rtrim((string)($cfg['base_url'] ?? 'https://fal.run'), '/') . '/' . $model, [
            'headers' => ['Authorization' => 'Key ' . ($cfg['api_key'] ?? ''), 'Content-Type' => 'application/json'],
            'body' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'timeout' => 180,
        ]);
        $data = json_decode($res['body'], true);
        if ($res['status'] >= 200 && $res['status'] < 300 && isset($data['images'][0]['url'])) {
            return ['ok' => true, 'binary' => null, 'url' => (string)$data['images'][0]['url'], 'mime' => 'image/png', 'http_code' => $res['status'], 'error' => null];
        }
        return ['ok' => false, 'binary' => null, 'url' => null, 'mime' => '', 'http_code' => $res['status'], 'error' => $this->extractError($data, $res)];
    }

    private function imageCloudflare(array $cfg, string $model, string $prompt, int $size): array
    {
        $url = rtrim((string)($cfg['base_url'] ?? 'https://api.cloudflare.com/client/v4/accounts'), '/')
            . '/' . rawurlencode((string)($cfg['account_id'] ?? '')) . '/ai/run/' . ($model !== '' ? $model : '@cf/bytedance/stable-diffusion-xl-lightning');
        $res = $this->transport->request('POST', $url, [
            'headers' => ['Authorization' => 'Bearer ' . ($cfg['api_token'] ?? ''), 'Content-Type' => 'application/json'],
            'body' => json_encode(['prompt' => $prompt, 'width' => $size, 'height' => $size, 'steps' => 20], JSON_UNESCAPED_UNICODE),
            'timeout' => 150,
        ]);
        $data = json_decode($res['body'], true);
        if ($res['status'] >= 200 && $res['status'] < 300 && !empty($data['result']['image'])) {
            $bin = base64_decode((string)$data['result']['image'], true);
            if ($bin !== false) {
                return ['ok' => true, 'binary' => $bin, 'url' => null, 'mime' => 'image/png', 'http_code' => $res['status'], 'error' => null];
            }
        }
        return ['ok' => false, 'binary' => null, 'url' => null, 'mime' => '', 'http_code' => $res['status'], 'error' => $this->extractError($data, $res)];
    }

    private function imageOpenAi(array $cfg, string $model, string $prompt, int $size): array
    {
        $side = $size <= 512 ? 512 : ($size <= 1024 ? 1024 : 1792);
        $payload = [
            'model' => $model !== '' ? $model : 'dall-e-3',
            'prompt' => $prompt,
            'n' => 1,
            'size' => $side . 'x' . $side,
            'response_format' => 'b64_json',
        ];
        $res = $this->transport->request('POST', rtrim((string)($cfg['base_url'] ?? 'https://api.openai.com/v1'), '/') . '/images/generations', [
            'headers' => ['Authorization' => 'Bearer ' . ($cfg['api_key'] ?? ''), 'Content-Type' => 'application/json'],
            'body' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'timeout' => 180,
        ]);
        $data = json_decode($res['body'], true);
        if ($res['status'] >= 200 && $res['status'] < 300 && !empty($data['data'][0])) {
            if (!empty($data['data'][0]['b64_json'])) {
                $bin = base64_decode((string)$data['data'][0]['b64_json'], true);
                if ($bin !== false) {
                    return ['ok' => true, 'binary' => $bin, 'url' => null, 'mime' => 'image/png', 'http_code' => $res['status'], 'error' => null];
                }
            }
            if (!empty($data['data'][0]['url'])) {
                return ['ok' => true, 'binary' => null, 'url' => (string)$data['data'][0]['url'], 'mime' => 'image/png', 'http_code' => $res['status'], 'error' => null];
            }
        }
        return ['ok' => false, 'binary' => null, 'url' => null, 'mime' => '', 'http_code' => $res['status'], 'error' => $this->extractError($data, $res)];
    }

    private function imagePiApi(array $cfg, string $prompt): array
    {
        $res = $this->transport->request('POST', rtrim((string)($cfg['base_url'] ?? 'https://api.piapi.ai/api'), '/') . '/v1/mj/submit/imagine', [
            'headers' => ['X-API-KEY' => (string)($cfg['api_key'] ?? ''), 'Content-Type' => 'application/json'],
            'body' => json_encode(['prompt' => $prompt, 'mode' => 'relax'], JSON_UNESCAPED_UNICODE),
            'timeout' => 60,
        ]);
        $data = json_decode($res['body'], true);
        if ($res['status'] >= 200 && $res['status'] < 300 && !empty($data['data']['task_id'])) {
            return ['ok' => true, 'binary' => null, 'url' => null, 'mime' => '', 'http_code' => $res['status'], 'error' => null, 'task_id' => (string)$data['data']['task_id']];
        }
        return ['ok' => false, 'binary' => null, 'url' => null, 'mime' => '', 'http_code' => $res['status'], 'error' => $this->extractError($data, $res)];
    }

    /* ══════════════════════════════════════════════════════════════════
     *  پروتکل‌ها — embedding و ویدیو
     * ══════════════════════════════════════════════════════════════════ */

    private function dispatchEmbed(string $protocol, string $id, array $cfg, string $model, string $text): array
    {
        if ($protocol === 'openai') {
            $res = $this->transport->request('POST', rtrim((string)$cfg['base_url'], '/') . '/embeddings', [
                'headers' => ['Authorization' => 'Bearer ' . ($cfg['api_key'] ?? ''), 'Content-Type' => 'application/json'],
                'body' => json_encode(['model' => $model, 'input' => $text], JSON_UNESCAPED_UNICODE),
                'timeout' => 30,
            ]);
            $data = json_decode($res['body'], true);
            if (isset($data['data'][0]['embedding'])) {
                return ['ok' => true, 'vector' => $data['data'][0]['embedding']];
            }
            return ['ok' => false, 'error' => $this->extractError($data, $res)];
        }
        if ($protocol === 'gemini') {
            $url = rtrim((string)$cfg['base_url'], '/') . '/models/' . rawurlencode($model) . ':embedContent?key=' . urlencode((string)($cfg['api_key'] ?? ''));
            $res = $this->transport->request('POST', $url, [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode(['model' => 'models/' . $model, 'content' => ['parts' => [['text' => $text]]]], JSON_UNESCAPED_UNICODE),
                'timeout' => 30,
            ]);
            $data = json_decode($res['body'], true);
            if (isset($data['embedding']['values'])) {
                return ['ok' => true, 'vector' => $data['embedding']['values']];
            }
            return ['ok' => false, 'error' => $this->extractError($data, $res)];
        }
        if ($protocol === 'cloudflare') {
            $url = rtrim((string)$cfg['base_url'], '/') . '/' . rawurlencode((string)($cfg['account_id'] ?? '')) . '/ai/run/' . $model;
            $res = $this->transport->request('POST', $url, [
                'headers' => ['Authorization' => 'Bearer ' . ($cfg['api_token'] ?? ''), 'Content-Type' => 'application/json'],
                'body' => json_encode(['text' => [$text]], JSON_UNESCAPED_UNICODE),
                'timeout' => 30,
            ]);
            $data = json_decode($res['body'], true);
            if (isset($data['result']['data'][0])) {
                return ['ok' => true, 'vector' => $data['result']['data'][0]];
            }
            return ['ok' => false, 'error' => $this->extractError($data, $res)];
        }
        return ['ok' => false, 'error' => 'پروتکل embedding پشتیبانی‌نشده'];
    }

    private function dispatchVideo(string $protocol, string $id, array $cfg, string $prompt, array $opts): array
    {
        $model = (string)($cfg['video_model'] ?? '');
        if ($protocol === 'fal') {
            $res = $this->transport->request('POST', 'https://queue.fal.run/' . ($model !== '' ? $model : 'fal-ai/kling-video/v2/master/text-to-video'), [
                'headers' => ['Authorization' => 'Key ' . ($cfg['api_key'] ?? ''), 'Content-Type' => 'application/json'],
                'body' => json_encode(['prompt' => $prompt, 'duration' => '5', 'aspect_ratio' => '16:9'], JSON_UNESCAPED_UNICODE),
                'timeout' => 60,
            ]);
            $data = json_decode($res['body'], true);
            if (!empty($data['request_id'])) {
                return ['ok' => true, 'task_id' => (string)$data['request_id'], 'status_url' => (string)($data['status_url'] ?? ''), 'error' => null];
            }
            return ['ok' => false, 'error' => $this->extractError($data, $res)];
        }
        if ($protocol === 'kling') {
            $res = $this->transport->request('POST', rtrim((string)$cfg['base_url'], '/') . '/v1/videos/text2video', [
                'headers' => ['Authorization' => 'Bearer ' . ($cfg['api_key'] ?? ''), 'Content-Type' => 'application/json'],
                'body' => json_encode([
                    'model_name' => $model !== '' ? $model : 'kling-v2-master',
                    'prompt' => $prompt,
                    'negative_prompt' => 'blurry, low quality, watermark, text',
                    'cfg_scale' => 0.5,
                    'mode' => 'std',
                    'duration' => '5',
                    'aspect_ratio' => '16:9',
                ], JSON_UNESCAPED_UNICODE),
                'timeout' => 60,
            ]);
            $data = json_decode($res['body'], true);
            if (isset($data['data']['task_id'])) {
                return ['ok' => true, 'task_id' => (string)$data['data']['task_id'], 'error' => null];
            }
            return ['ok' => false, 'error' => $this->extractError($data, $res)];
        }
        if ($protocol === 'piapi') {
            $res = $this->transport->request('POST', rtrim((string)$cfg['base_url'], '/') . '/v1/kling/submit/text2video', [
                'headers' => ['X-API-KEY' => (string)($cfg['api_key'] ?? ''), 'Content-Type' => 'application/json'],
                'body' => json_encode(['prompt' => $prompt, 'model' => $model !== '' ? $model : 'kling', 'duration' => 5], JSON_UNESCAPED_UNICODE),
                'timeout' => 60,
            ]);
            $data = json_decode($res['body'], true);
            if (!empty($data['data']['task_id'])) {
                return ['ok' => true, 'task_id' => (string)$data['data']['task_id'], 'error' => null];
            }
            return ['ok' => false, 'error' => $this->extractError($data, $res)];
        }
        if ($protocol === 'openai' && $id === 'byteplus') {
            // Ark از الگوی contents/response برای تولید ویدیو استفاده می‌کند
            $res = $this->transport->request('POST', rtrim((string)$cfg['base_url'], '/') . '/contents/generations/tasks', [
                'headers' => ['Authorization' => 'Bearer ' . ($cfg['api_key'] ?? ''), 'Content-Type' => 'application/json'],
                'body' => json_encode([
                    'model' => $model !== '' ? $model : 'doubao-seedance-1-0-lite-t2v-250428',
                    'content' => [['type' => 'text', 'text' => $prompt]],
                ], JSON_UNESCAPED_UNICODE),
                'timeout' => 60,
            ]);
            $data = json_decode($res['body'], true);
            if (!empty($data['id'])) {
                return ['ok' => true, 'task_id' => (string)$data['id'], 'error' => null];
            }
            return ['ok' => false, 'error' => $this->extractError($data, $res)];
        }
        return ['ok' => false, 'error' => 'این ارائه‌دهنده از ویدیو پشتیبانی نمی‌کند.'];
    }

    /* ══════════════════════════════════════════════════════════════════
     *  ابزارهای مشترک
     * ══════════════════════════════════════════════════════════════════ */

    private function modelFor(string $providerId, bool $vision): string
    {
        $cfg = $this->config['ai']['providers'][$providerId] ?? [];
        if ($vision && !empty($cfg['vision_model'])) {
            return (string)$cfg['vision_model'];
        }
        return (string)($cfg['model'] ?? '');
    }

    private function defaultSystem(string $task): string
    {
        $base = 'تو یک دستیار تخصصی برای یک پنل مدیریت انبار و قیمت‌گذاری بازار ایران هستی. '
            . 'همیشه فارسی سلیس و فنی بنویس. هرگز عدد یا قیمت ساختگی به‌عنوان قطعی ارائه نده؛ '
            . 'اگر مطمئن نیستی، در فیلد confidence امتیاز پایین بده و در notes دلیلش را بنویس.';
        $taskDef = Registry::task($task);
        if ($taskDef && in_array(Registry::CAP_JSON, $taskDef['requires'] ?? [], true)) {
            $base .= ' خروجی را فقط به‌صورت یک آبجکت JSON معتبر و بدون هیچ متن اضافه یا markdown برگردان.';
        }
        return $base;
    }

    /** استخراج JSON از پاسخ مدل (حتی اگر داخل ```json باشد). */
    public static function extractJson(string $text)
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        if (preg_match('/```(?:json)?\s*(\{.*?\}|\[.*?\])\s*```/s', $text, $m)) {
            $decoded = json_decode($m[1], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return null;
    }

    private function extractError($data, array $res): string
    {
        if (is_array($data)) {
            foreach (['error.message', 'error.msg', 'message', 'detail', 'error'] as $path) {
                $v = m_get($data, $path);
                if (is_string($v) && $v !== '') {
                    return $v;
                }
            }
        }
        if (!empty($res['error'])) {
            return (string)$res['error'];
        }
        return 'HTTP ' . (int)$res['status'] . ' — ' . substr(strip_tags((string)$res['body']), 0, 200);
    }

    private function fail(string $message, string $task, array $attempts = []): array
    {
        Logger::write('ai', $message, 'error', ['task' => $task]);
        return [
            'ok' => false, 'text' => '', 'provider' => null, 'model' => null,
            'latency_ms' => 0, 'error' => $message, 'attempts' => $attempts,
        ];
    }

    /** ثبت در کارنامه ai_tasks — اگر دیتابیس در دسترس باشد. */
    private function log(string $task, string $provider, string $model, array $res, float $latency, string $prompt): void
    {
        if ($this->db === null) {
            return;
        }
        try {
            if (!$this->db->tableExists('ai_tasks')) {
                return;
            }
            $this->db->insert('ai_tasks', [
                'task' => $task,
                'provider' => $provider,
                'model' => $model !== '' ? $model : null,
                'status' => !empty($res['ok']) ? 'ok' : 'error',
                'latency_ms' => (int)round($latency),
                'prompt_tokens' => (int)($res['usage']['prompt_tokens'] ?? 0),
                'completion_tokens' => (int)($res['usage']['completion_tokens'] ?? 0),
                'http_code' => (int)($res['http_code'] ?? 0),
                'error' => isset($res['error']) ? mb_substr((string)$res['error'], 0, 490) : null,
                'input_hash' => sha1($prompt),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            Logger::write('ai', 'ثبت کارنامه ناموفق: ' . $e->getMessage(), 'warning');
        }
    }
}
