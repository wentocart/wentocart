<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Settings\ShopSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Versioned storefront catalog cache keys (works with file/database/redis — no Cache::tags required).
 * Bump the version on product/category changes so home/listing payloads expire together.
 */
class StorefrontCatalogCache
{
    public const VERSION_KEY = 'sf.catalog.ver';

    public static function version(): int
    {
        return max(1, (int) self::store()->get(self::VERSION_KEY, 1));
    }

    public static function bump(): void
    {
        $next = self::version() + 1;
        self::store()->forever(self::VERSION_KEY, $next);
    }

    public static function key(string $suffix): string
    {
        return 'sf.v'.self::version().'.'.$suffix;
    }

    /**
     * Large home/listing JSON is faster on the file store than the database cache driver.
     */
    protected static function store(): \Illuminate\Contracts\Cache\Repository
    {
        try {
            return Cache::store('file');
        } catch (\Throwable) {
            return Cache::store();
        }
    }

    /**
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public static function remember(string $suffix, int $ttlSeconds, callable $callback): mixed
    {
        $ttl = self::ttl($ttlSeconds);
        if ($ttl <= 0) {
            return $callback();
        }

        return self::store()->remember(self::key($suffix), $ttl, $callback);
    }

    /**
     * Shop choice overrides the caller's time. "auto" keeps each page's own time.
     * Product and category saves still bump the version, so a longer time does not serve stale catalog rows.
     */
    public static function ttl(int $fallback): int
    {
        $choice = (string) ShopSetting::get('performance.catalog_ttl', 'auto');
        if ($choice === '' || $choice === 'auto') {
            return max(0, $fallback);
        }
        if ($choice === '0') {
            return 0;
        }
        $seconds = (int) $choice;
        if (! in_array($seconds, [600, 3600], true)) {
            return max(0, $fallback);
        }

        return $seconds;
    }
}
