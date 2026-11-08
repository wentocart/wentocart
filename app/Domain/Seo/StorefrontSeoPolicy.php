<?php

namespace App\Domain\Seo;

/**
 * Storefront path classification for robots / 404 / crawl policy.
 */
final class StorefrontSeoPolicy
{
    /** @var list<string> */
    public const UTILITY_ROOTS = [
        'cart',
        'checkout',
        'login',
        'register',
        'forgot-password',
        'reset-password',
        'search',
        'account',
        'compare',
        'order',
        'order-status',
        'wishlist',
        '404',
    ];

    /** Fixed React routes that are not URL-rewrites but still valid 200 pages. */
    /** @var list<string> */
    public const SHELL_ROOTS = [
        'catalog',
        'deal',
        'brands',
        'brand',
        'shop',
        'supplier',
        'blog',
        'help',
        'about',
        'shipping',
        'returns',
        'privacy',
        'terms',
        'faq',
        'contact',
        'payment-security',
        'order-tracking',
        'warranty',
        'm',
    ];

    /** Thin placeholder shells — indexable only when a rewrite/CMS supplies real SEO. */
    /** @var list<string> */
    public const THIN_SHELL_ROOTS = [
        'catalog',
        'deal',
        'brands',
        'blog',
        'm',
    ];

    /** Shell paths that map to CMS slugs when no rewrite is present. */
    /** @var list<string> */
    public const CMS_ALIAS_ROOTS = [
        'help',
        'about',
        'shipping',
        'returns',
        'privacy',
        'terms',
        'faq',
        'contact',
        'payment-security',
        'order-tracking',
        'warranty',
    ];

    /** Query keys that make a listing/PDP URL a faceted/duplicate variant. */
    /** @var list<string> */
    public const FACET_QUERY_KEYS = [
        'brand',
        'price',
        'rating',
        'discount',
        'color',
        'availability',
        'values',
        'sort',
        'page',
        'nc',
        'q',
        'ipa',
        'combination',
    ];

    public static function root(string $path): string
    {
        $path = trim($path, '/');
        if ($path === '') {
            return '';
        }

        return explode('/', $path)[0];
    }

    public static function isUtilityPath(string $path): bool
    {
        return in_array(self::root($path), self::UTILITY_ROOTS, true);
    }

    public static function isShellPath(string $path): bool
    {
        $root = self::root($path);

        return $root !== '' && (
            in_array($root, self::SHELL_ROOTS, true)
            || in_array($root, self::UTILITY_ROOTS, true)
        );
    }

    public static function isThinShellPath(string $path): bool
    {
        return in_array(self::root($path), self::THIN_SHELL_ROOTS, true);
    }

    /**
     * Exact React-owned paths that must not take catalog rewrite SEO
     * (e.g. /catalog must not inherit a category slug "catalog").
     */
    public static function isReservedShellPath(string $path): bool
    {
        $parts = array_values(array_filter(explode('/', trim($path, '/')), fn ($p) => $p !== ''));
        if ($parts === []) {
            return false;
        }
        if ($parts[0] === 'm') {
            return true;
        }

        return count($parts) === 1 && in_array($parts[0], self::THIN_SHELL_ROOTS, true);
    }

    public static function isCmsAliasPath(string $path): bool
    {
        $parts = explode('/', trim($path, '/'));

        return count($parts) === 1 && in_array($parts[0], self::CMS_ALIAS_ROOTS, true);
    }

    /**
     * True when the request carries listing/PDP noise that should not be indexed.
     */
    public static function hasFacetOrPagingQuery(\Illuminate\Http\Request $request): bool
    {
        foreach (self::FACET_QUERY_KEYS as $key) {
            if (! $request->query->has($key)) {
                continue;
            }
            $value = $request->query($key);
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            if ($key === 'page' && (int) $value <= 1) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Detect whether the incoming request user-agent is a search engine crawler or social unfurler.
     */
    public static function isCrawler(?string $userAgent = null): bool
    {
        $userAgent = $userAgent ?? request()?->userAgent() ?? '';
        if ($userAgent === '') {
            return false;
        }

        $crawlerPatterns = [
            'googlebot',
            'bingbot',
            'slurp',
            'duckduckbot',
            'baiduspider',
            'yandexbot',
            'sogou',
            'exabot',
            'facebot',
            'facebookexternalhit',
            'ia_archiver',
            'twitterbot',
            'linkedinbot',
            'pinterest',
            'slackbot',
            'whatsapp',
            'telegrambot',
            'applebot',
            'rogerbot',
            'embedly',
            'quora link preview',
            'showyoubot',
            'outbrain',
            'vkshare',
            'w3c_validator',
        ];

        $ua = strtolower($userAgent);
        foreach ($crawlerPatterns as $pattern) {
            if (str_contains($ua, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
