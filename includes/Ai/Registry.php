<?php
namespace Meelano\Ai;

/**
 * رجیستری توانمندی ارائه‌دهندگان هوش مصنوعی + تعریف وظایف برنامه.
 *
 * این ماتریس مغز «مسیریابی خودکار» است: برای هر وظیفه مشخص می‌کند کدام
 * ارائه‌دهنده چه امتیاز تخصصی دارد، چه نوع قابلیتی لازم است و چه کلاس
 * تأخیر/هزینه‌ای دارد.
 *
 * @author Milad Yaghoobi — Meelano Studio Design
 */
final class Registry
{
    /* ── قابلیتها ────────────────────────────────────────────────────── */
    public const CAP_CHAT = 'chat';
    public const CAP_JSON = 'json_mode';
    public const CAP_VISION = 'vision';
    public const CAP_IMAGE = 'image';
    public const CAP_VIDEO = 'video';
    public const CAP_EMBED = 'embeddings';

    /**
     * تعریف وظایف برنامه.
     * weight: اهمیت کیفیت در برابر سرعت (۰..۱).
     *
     * @return array<string,array<string,mixed>>
     */
    public static function tasks(): array
    {
        return [
            'product.normalize' => [
                'label' => 'استانداردسازی نام و برند کالا',
                'section' => 'فرم کالا',
                'icon' => 'fa-tag',
                'requires' => [self::CAP_CHAT, self::CAP_JSON],
                'quality_weight' => 0.45,
                'speed_weight' => 0.55,
                'max_tokens' => 400,
            ],
            'product.extract' => [
                'label' => 'استخراج مشخصات، بارکد و دسته‌بندی',
                'section' => 'فرم کالا',
                'icon' => 'fa-barcode',
                'requires' => [self::CAP_CHAT, self::CAP_JSON],
                'quality_weight' => 0.6,
                'speed_weight' => 0.4,
                'max_tokens' => 900,
            ],
            'vision.analyze' => [
                'label' => 'تحلیل تصویر کالا و استخراج پالت رنگ',
                'section' => 'استودیو تصویر',
                'icon' => 'fa-eye',
                'requires' => [self::CAP_VISION, self::CAP_JSON],
                'quality_weight' => 0.7,
                'speed_weight' => 0.3,
                'max_tokens' => 700,
            ],
            'market.price' => [
                'label' => 'برآورد هوشمند قیمت بازار (ترب/دیجی‌کالا)',
                'section' => 'استعلام بازار',
                'icon' => 'fa-store',
                'requires' => [self::CAP_CHAT, self::CAP_JSON],
                'quality_weight' => 0.65,
                'speed_weight' => 0.35,
                'max_tokens' => 800,
            ],
            'pricing.strategy' => [
                'label' => 'استراتژی قیمت‌گذاری و حاشیه سود',
                'section' => 'جدول قیمت‌گذاری',
                'icon' => 'fa-calculator',
                'requires' => [self::CAP_CHAT, self::CAP_JSON],
                'quality_weight' => 0.85,
                'speed_weight' => 0.15,
                'max_tokens' => 900,
            ],
            'seo.copy' => [
                'label' => 'تولید توضیحات سئوی فارسی',
                'section' => 'سئو',
                'icon' => 'fa-pen-nib',
                'requires' => [self::CAP_CHAT],
                'quality_weight' => 0.7,
                'speed_weight' => 0.3,
                'max_tokens' => 700,
            ],
            'seo.keywords' => [
                'label' => 'کلیدواژه‌های سئو و تگ محصول',
                'section' => 'سئو',
                'icon' => 'fa-key',
                'requires' => [self::CAP_CHAT, self::CAP_JSON],
                'quality_weight' => 0.6,
                'speed_weight' => 0.4,
                'max_tokens' => 400,
            ],
            'image.product' => [
                'label' => 'رندر تصویر محصول (WebP 768×768)',
                'section' => 'استودیو تصویر',
                'icon' => 'fa-image',
                'requires' => [self::CAP_IMAGE],
                'quality_weight' => 0.8,
                'speed_weight' => 0.2,
                'max_tokens' => 0,
            ],
            'image.upscale' => [
                'label' => 'بزرگ‌نمایی و شفاف‌سازی تصویر',
                'section' => 'استودیو تصویر',
                'icon' => 'fa-expand',
                'requires' => [self::CAP_IMAGE],
                'quality_weight' => 0.75,
                'speed_weight' => 0.25,
                'max_tokens' => 0,
            ],
            'video.product' => [
                'label' => 'ساخت ویدیوی کوتاه محصول',
                'section' => 'استودیو ویدیو',
                'icon' => 'fa-film',
                'requires' => [self::CAP_VIDEO],
                'quality_weight' => 0.9,
                'speed_weight' => 0.1,
                'max_tokens' => 0,
            ],
            'embed.similarity' => [
                'label' => 'بردار معنایی برای تشخیص کالای تکراری',
                'section' => 'کنترل کیفیت داده',
                'icon' => 'fa-vector-square',
                'requires' => [self::CAP_EMBED],
                'quality_weight' => 0.5,
                'speed_weight' => 0.5,
                'max_tokens' => 0,
            ],
            'anomaly.audit' => [
                'label' => 'ممیزی ناهنجاری قیمت و ریسک',
                'section' => 'کنترل کیفیت داده',
                'icon' => 'fa-shield-halved',
                'requires' => [self::CAP_CHAT, self::CAP_JSON],
                'quality_weight' => 0.8,
                'speed_weight' => 0.2,
                'max_tokens' => 600,
            ],
            'translate.i18n' => [
                'label' => 'ترجمه چندزبانه عنوان و توضیحات',
                'section' => 'چندزبانه',
                'icon' => 'fa-language',
                'requires' => [self::CAP_CHAT],
                'quality_weight' => 0.55,
                'speed_weight' => 0.45,
                'max_tokens' => 800,
            ],
            'crypto.signal' => [
                'label' => 'قضاوت مستقل سیگنال کریپتو (اجماع)',
                'section' => 'موتور سیگنال کریپتو',
                'icon' => 'fa-chart-line',
                'requires' => [self::CAP_CHAT, self::CAP_JSON],
                'quality_weight' => 0.9,
                'speed_weight' => 0.1,
                'max_tokens' => 350,
            ],
            'crypto.review' => [
                'label' => 'بازبینی ریسک و سناریوی بازار کریپتو',
                'section' => 'موتور سیگنال کریپتو',
                'icon' => 'fa-magnifying-glass-chart',
                'requires' => [self::CAP_CHAT, self::CAP_JSON],
                'quality_weight' => 0.8,
                'speed_weight' => 0.2,
                'max_tokens' => 600,
            ],
        ];
    }

    /**
     * مشخصات ارائه‌دهندگان.
     * speed_class: 1=فوق‌سریع … 5=کند. cost_class: 1=ارزان … 5=گران.
     * affinity: امتیاز تخصصی ۰..۳۰ برای هر وظیفه.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function providers(): array
    {
        return [
            'openai' => [
                'label' => 'OpenAI',
                'icon' => 'fa-brain',
                'protocol' => 'openai',
                'capabilities' => [self::CAP_CHAT, self::CAP_JSON, self::CAP_VISION, self::CAP_IMAGE, self::CAP_EMBED],
                'speed_class' => 3,
                'cost_class' => 3,
                'key_field' => 'api_key',
                'affinity' => [
                    'product.normalize' => 20, 'product.extract' => 26, 'vision.analyze' => 28,
                    'market.price' => 24, 'pricing.strategy' => 30, 'seo.copy' => 22,
                    'seo.keywords' => 20, 'image.product' => 20, 'anomaly.audit' => 27,
                    'embed.similarity' => 28, 'translate.i18n' => 24,
                ],
                'notes' => 'قوی‌ترین استدلال عددی و بینایی ماشین؛ مناسب قیمت‌گذاری و تحلیل تصویر.',
            ],
            'gemini' => [
                'label' => 'Google Gemini',
                'icon' => 'fa-gem',
                'protocol' => 'gemini',
                'capabilities' => [self::CAP_CHAT, self::CAP_JSON, self::CAP_VISION, self::CAP_EMBED],
                'speed_class' => 2,
                'cost_class' => 2,
                'key_field' => 'api_key',
                'affinity' => [
                    'product.normalize' => 26, 'product.extract' => 30, 'vision.analyze' => 30,
                    'market.price' => 22, 'pricing.strategy' => 20, 'seo.copy' => 28,
                    'seo.keywords' => 26, 'anomaly.audit' => 20, 'embed.similarity' => 24,
                    'translate.i18n' => 30,
                ],
                'notes' => 'فارسی بسیار قوی، JSON ساخت‌یافته عالی و پنجره زمینه بزرگ.',
            ],
            'groq' => [
                'label' => 'Groq (LPU)',
                'icon' => 'fa-bolt',
                'protocol' => 'openai',
                'capabilities' => [self::CAP_CHAT, self::CAP_JSON],
                'speed_class' => 1,
                'cost_class' => 1,
                'key_field' => 'api_key',
                'affinity' => [
                    'product.normalize' => 30, 'product.extract' => 20, 'seo.keywords' => 22,
                    'anomaly.audit' => 24, 'translate.i18n' => 26, 'seo.copy' => 18,
                ],
                'notes' => 'کمترین تأخیر جهان؛ ایده‌آل برای وظایف سبک و پرتکرار.',
            ],
            'gapgpt' => [
                'label' => 'GapGPT',
                'icon' => 'fa-plug',
                'protocol' => 'openai',
                'capabilities' => [self::CAP_CHAT, self::CAP_JSON, self::CAP_VISION, self::CAP_IMAGE],
                'speed_class' => 3,
                'cost_class' => 2,
                'key_field' => 'api_key',
                'affinity' => ['product.normalize' => 18, 'seo.copy' => 16, 'image.product' => 14, 'translate.i18n' => 14],
                'notes' => 'سازگار با OpenAI؛ مناسب دسترسی از ایران و پرداخت ریالی.',
            ],
            'maxrouter' => [
                'label' => 'MaxRouter',
                'icon' => 'fa-route',
                'protocol' => 'openai',
                'capabilities' => [self::CAP_CHAT, self::CAP_JSON],
                'speed_class' => 3,
                'cost_class' => 2,
                'key_field' => 'api_key',
                'affinity' => ['product.normalize' => 14, 'seo.copy' => 14, 'translate.i18n' => 12],
                'notes' => 'مسیریاب چندمدلی؛ نقش پشتیبان (fallback) را خوب بازی می‌کند.',
            ],
            'cloudflare' => [
                'label' => 'Cloudflare Workers AI',
                'icon' => 'fa-cloud',
                'protocol' => 'cloudflare',
                'capabilities' => [self::CAP_CHAT, self::CAP_IMAGE, self::CAP_EMBED],
                'speed_class' => 2,
                'cost_class' => 1,
                'key_field' => 'api_token',
                'requires_extra' => ['account_id'],
                'affinity' => ['image.product' => 22, 'embed.similarity' => 20, 'product.normalize' => 16],
                'notes' => 'رایگان/ارزان با CDN جهانی؛ SDXL Lightning برای تصویر سریع.',
            ],
            'byteplus' => [
                'label' => 'BytePlus Ark (Doubao)',
                'icon' => 'fa-cube',
                'protocol' => 'openai',
                'capabilities' => [self::CAP_CHAT, self::CAP_JSON, self::CAP_IMAGE, self::CAP_VIDEO],
                'speed_class' => 3,
                'cost_class' => 2,
                'key_field' => 'api_key',
                'affinity' => ['video.product' => 26, 'image.product' => 20, 'product.extract' => 16],
                'notes' => 'Seedance برای ویدیو و Seedream برای تصویر؛ به‌صرفه.',
            ],
            'stability' => [
                'label' => 'Stability AI',
                'icon' => 'fa-wand-sparkles',
                'protocol' => 'stability',
                'capabilities' => [self::CAP_IMAGE],
                'speed_class' => 3,
                'cost_class' => 3,
                'key_field' => 'api_key',
                'affinity' => ['image.product' => 30, 'image.upscale' => 30],
                'notes' => 'تخصصی تصویر؛ SD3 Core و کنترل دقیق رزولوشن.',
            ],
            'deepai' => [
                'label' => 'DeepAI',
                'icon' => 'fa-palette',
                'protocol' => 'deepai',
                'capabilities' => [self::CAP_IMAGE],
                'speed_class' => 2,
                'cost_class' => 1,
                'key_field' => 'api_key',
                'affinity' => ['image.product' => 14, 'image.upscale' => 16],
                'notes' => 'ارزان و سریع برای پیش‌نمایش تصویر.',
            ],
            'fal' => [
                'label' => 'fal.ai',
                'icon' => 'fa-rocket',
                'protocol' => 'fal',
                'capabilities' => [self::CAP_IMAGE, self::CAP_VIDEO],
                'speed_class' => 2,
                'cost_class' => 3,
                'key_field' => 'api_key',
                'affinity' => ['image.product' => 28, 'image.upscale' => 28, 'video.product' => 24],
                'notes' => 'FLUX برای تصویر باکیفیت و دسترسی به موتورهای ویدیویی متعدد.',
            ],
            'kling' => [
                'label' => 'Kling AI',
                'icon' => 'fa-video',
                'protocol' => 'kling',
                'capabilities' => [self::CAP_VIDEO],
                'speed_class' => 5,
                'cost_class' => 4,
                'key_field' => 'api_key',
                'affinity' => ['video.product' => 30],
                'notes' => 'بالاترین کیفیت ویدیوی سینمایی؛ زمان رندر طولانی.',
            ],
            'piapi' => [
                'label' => 'PiAPI',
                'icon' => 'fa-tower-broadcast',
                'protocol' => 'piapi',
                'capabilities' => [self::CAP_VIDEO, self::CAP_IMAGE],
                'speed_class' => 4,
                'cost_class' => 3,
                'key_field' => 'api_key',
                'affinity' => ['video.product' => 22, 'image.product' => 12],
                'notes' => 'پروکسی Kling/Midjourney/Luma با API ساده.',
            ],
        ];
    }

    public static function provider(string $id): ?array
    {
        $all = self::providers();
        return $all[$id] ?? null;
    }

    public static function task(string $id): ?array
    {
        $all = self::tasks();
        return $all[$id] ?? null;
    }

    /** ارائه‌دهندگانی که یک قابلیت خاص دارند. */
    public static function withCapability(string $capability): array
    {
        $out = [];
        foreach (self::providers() as $id => $p) {
            if (in_array($capability, $p['capabilities'], true)) {
                $out[] = $id;
            }
        }
        return $out;
    }
}
