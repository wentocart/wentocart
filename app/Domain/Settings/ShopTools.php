<?php

namespace App\Domain\Settings;

final class ShopTools
{
    public const URL_TYPES = ['product', 'category', 'cms', 'brand', 'supplier'];

    /** @var array<string, string> */
    public const URL_SCHEMAS = [
        'product' => '{id}-{rewrite}',
        'category' => '{id}-{rewrite}',
        'cms' => '{id}-{rewrite}',
        'brand' => 'brand/{rewrite}',
        'supplier' => 'supplier/{rewrite}',
    ];

    /** @var list<array{id: string, max_width: int, max_height: int}> */
    public const IMAGE_TYPES = [
        ['id' => 'cart', 'max_width' => 80, 'max_height' => 80],
        ['id' => 'home', 'max_width' => 250, 'max_height' => 250],
        ['id' => 'medium', 'max_width' => 452, 'max_height' => 452],
        ['id' => 'large', 'max_width' => 800, 'max_height' => 800],
        ['id' => 'category', 'max_width' => 141, 'max_height' => 180],
    ];

    public static function urlSchema(string $type): string
    {
        $defaults = self::URL_SCHEMAS;
        $fallback = $defaults[$type] ?? '{rewrite}';

        return (string) ShopSetting::get('url.schema.'.$type, $fallback);
    }

    public static function urlSuffix(): string
    {
        $suffix = (string) ShopSetting::get('url.suffix', '');

        return $suffix === '.html' ? '.html' : '';
    }

    /**
     * @return list<array{id: string, max_width: int, max_height: int}>
     */
    public static function imageTypes(): array
    {
        $rows = ShopSetting::json('images.types', self::IMAGE_TYPES);
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row) || empty($row['id'])) {
                continue;
            }
            $out[] = [
                'id' => (string) $row['id'],
                'max_width' => max(16, (int) ($row['max_width'] ?? 100)),
                'max_height' => max(16, (int) ($row['max_height'] ?? 100)),
            ];
        }

        return $out !== [] ? $out : self::IMAGE_TYPES;
    }

    public static function webpEnabled(): bool
    {
        return ShopSetting::bool('images.webp', true);
    }

    public static function titlePattern(): string
    {
        return (string) ShopSetting::get('seo.title_pattern', '{name} | {shop}');
    }

    public static function defaultRobots(): string
    {
        return (string) ShopSetting::get('seo.default_robots', 'index,follow');
    }

    public static function robotsTxt(): string
    {
        $stored = (string) ShopSetting::get('seo.robots_txt', '');
        if (trim($stored) !== '') {
            return $stored;
        }

        return implode("\n", [
            'User-agent: *',
            'Allow: /',
            'Disallow: /cart',
            'Disallow: /checkout',
            'Disallow: /account',
            'Disallow: /login',
            'Disallow: /register',
            'Disallow: /search',
            'Disallow: /compare',
            'Disallow: /wishlist',
            'Disallow: /order',
            'Disallow: /forgot-password',
            'Disallow: /reset-password',
            'Disallow: /*/cart',
            'Disallow: /*/checkout',
            'Disallow: /*/account',
            'Disallow: /*/login',
            'Disallow: /*/register',
            'Disallow: /*/search',
            'Disallow: /*/compare',
            'Disallow: /*/wishlist',
            'Disallow: /*/order',
            'Sitemap: {sitemap}',
            '',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function mailConfig(): array
    {
        return [
            'mailer' => (string) ShopSetting::get('mail.mailer', (string) config('mail.default', 'log')),
            'host' => (string) ShopSetting::get('mail.host', (string) config('mail.mailers.smtp.host', '')),
            'port' => (int) ShopSetting::get('mail.port', (int) config('mail.mailers.smtp.port', 587)),
            'encryption' => (string) ShopSetting::get('mail.encryption', (string) config('mail.mailers.smtp.encryption', 'tls')),
            'username' => (string) ShopSetting::get('mail.username', (string) config('mail.mailers.smtp.username', '')),
            'has_password' => filled(ShopSetting::get('mail.password', '')),
            'from_address' => (string) ShopSetting::get('mail.from_address', (string) config('mail.from.address', '')),
            'from_name' => (string) ShopSetting::get('mail.from_name', (string) config('mail.from.name', '')),
            'sendmail_ready' => SendmailPath::resolve() !== null,
        ];
    }
}
