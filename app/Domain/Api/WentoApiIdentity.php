<?php

namespace App\Domain\Api;

/**
 * Canonical public API vocabulary for WentoCart.
 *
 * Surfaces: store `/api/v1/store/*`, native catalog/cart/checkout, console `/api/v1/console/*`.
 * Resource nouns: stores, warehouses, discount-rules, home-modules, product-templates,
 * store-settings, order-composer, metrics. Bulk bodies use `ids` (not `ids`).
 */
final class WentoApiIdentity
{
    public const VERSION = 'v1';

    public const STORE_PREFIX = '/api/v1/store';

    public const CONSOLE_PREFIX = '/api/v1/console';

    public const NATIVE_CATALOG_PREFIX = '/api/v1/catalog';

    /** @var list<string> */
    public const APPEARANCE_MODULE_TYPES = [
        'hero',
        'promos',
        'promos_mid',
        'promos_bottom',
        'banner',
        'featured',
        'new_arrivals',
        'bestsellers',
        'categories',
        'category_shelves',
        'content',
        'trust',
        'footer',
        'testimonials',
        'brands',
        'newsletter',
        'html',
        'shelf',
    ];

    public static function storePath(string $suffix = ''): string
    {
        $suffix = ltrim($suffix, '/');

        return $suffix === '' ? self::STORE_PREFIX : self::STORE_PREFIX.'/'.$suffix;
    }

    public static function consolePath(string $suffix = ''): string
    {
        $suffix = ltrim($suffix, '/');

        return $suffix === '' ? self::CONSOLE_PREFIX : self::CONSOLE_PREFIX.'/'.$suffix;
    }

    public static function normalizeAppearanceType(string $type): string
    {
        return strtolower(trim($type));
    }

    public static function isAppearanceType(string $type): bool
    {
        $normalized = self::normalizeAppearanceType($type);

        return in_array($normalized, self::APPEARANCE_MODULE_TYPES, true)
            || str_starts_with($normalized, 'extra_');
    }
}
