<?php

namespace App\Domain\Settings;

use App\Domain\Catalog\Services\ImageOptimizer;
use App\Domain\Catalog\Services\StorefrontCatalogCache;
use App\Domain\Search\SearchEngineManager;
use App\Domain\Theme\ThemeResolver;

/**
 * Facts the performance screen can show. Every value comes from this shop, not a checklist copied from another platform.
 */
class PerformanceReport
{
    /**
     * @return array<string, mixed>
     */
    public static function payload(): array
    {
        return [
            'debug' => (bool) config('app.debug'),
            'is_production' => app()->isProduction(),
            'cache_driver' => (string) config('cache.default'),
            'api_cache' => ShopSetting::bool('performance.api_cache', true),
            'catalog_ttl' => self::catalogTtl(),
            'catalog_version' => StorefrontCatalogCache::version(),
            'benchmark' => ShopSetting::get('performance.benchmark'),
            'images_missing' => self::imagesMissing(),
            'search' => app(SearchEngineManager::class)->preferred(),
            'search_engines' => app(SearchEngineManager::class)->describe(),
            'profiler' => ShopSetting::bool('advanced.profiler', false),
            'webp' => ShopTools::webpEnabled(),
            'opcache' => self::opcache(),
            'octane' => self::octane(),
            'stack' => self::stack(),
            'object_storage' => ObjectStorageSettings::payload(),
            'assets' => self::assets(),
            'slow' => self::slowest(),
        ];
    }

    /**
     * Production runtime checklist (Laravel / PHP / Octane / Redis / S3).
     *
     * @return array{
     *   php: string,
     *   laravel: string,
     *   redis_client: string,
     *   cache_store: string,
     *   queue_connection: string,
     *   session_driver: string,
     *   octane_server: string|null,
     *   octane_binary: bool,
     *   object_storage_driver: string,
     *   object_storage_ready: bool,
     *   catalog_media_disk: string,
     *   recommended: bool
     * }
     */
    protected static function stack(): array
    {
        $octane = self::octane();
        $storage = ObjectStorageSettings::payload();
        $cache = (string) config('cache.default');
        $queue = (string) config('queue.default');
        $session = (string) config('session.driver');
        $redisClient = (string) config('database.redis.client', 'predis');
        $mediaDisk = ObjectStorageSettings::diskName();

        $recommended = $octane['installed']
            && ($octane['server'] === 'frankenphp' || $octane['server'] === 'roadrunner' || $octane['server'] === 'swoole')
            && $redisClient === 'predis'
            && in_array($cache, ['redis', 'array'], true) // array allowed in tests
            && class_exists(\Aws\S3\S3Client::class);

        return [
            'php' => PHP_VERSION,
            'laravel' => (string) app()->version(),
            'redis_client' => $redisClient,
            'cache_store' => $cache,
            'queue_connection' => $queue,
            'session_driver' => $session,
            'octane_server' => $octane['server'],
            'octane_binary' => (bool) $octane['binary'],
            'object_storage_driver' => (string) ($storage['driver'] ?? 'local'),
            'object_storage_ready' => (bool) ($storage['package_ready'] ?? false),
            'catalog_media_disk' => $mediaDisk,
            'recommended' => $recommended,
        ];
    }

    /**
     * @return array{installed: bool, server: string|null, binary: bool}
     */
    protected static function octane(): array
    {
        $server = env('OCTANE_SERVER') ?: (is_file(config_path('octane.php')) ? (string) config('octane.server', 'frankenphp') : null);

        return [
            'installed' => class_exists(\Laravel\Octane\Octane::class) && is_file(config_path('octane.php')),
            'server' => $server !== '' ? $server : null,
            'binary' => is_file(base_path('frankenphp')) || is_file(base_path('rr')),
        ];
    }

    protected static function imagesMissing(): int
    {
        try {
            return app(ImageOptimizer::class)->missingCount();
        } catch (\Throwable) {
            return 0;
        }
    }

    public static function catalogTtl(): string
    {
        $choice = (string) ShopSetting::get('performance.catalog_ttl', 'auto');

        return in_array($choice, ['auto', '0', '600', '3600'], true) ? $choice : 'auto';
    }

    /**
     * @return array{enabled: bool, hit_rate: float|null}
     */
    protected static function opcache(): array
    {
        if (! function_exists('opcache_get_status')) {
            return ['enabled' => false, 'hit_rate' => null];
        }
        try {
            $status = opcache_get_status(false);
        } catch (\Throwable) {
            return ['enabled' => false, 'hit_rate' => null];
        }
        if (! is_array($status) || empty($status['opcache_enabled'])) {
            return ['enabled' => false, 'hit_rate' => null];
        }
        $rate = $status['opcache_statistics']['opcache_hit_rate'] ?? null;

        return [
            'enabled' => true,
            'hit_rate' => is_numeric($rate) ? round((float) $rate, 1) : null,
        ];
    }

    /**
     * @return array{ready: bool, built: list<array{name: string, file: string, size: string}>, theme: list<array{name: string, file: string, size: string}>}
     */
    protected static function assets(): array
    {
        $built = [];
        $manifestPath = public_path('build/manifest.json');
        $manifest = is_file($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true) : null;
        if (is_array($manifest)) {
            $style = $manifest['storefront/src/styles.css']['file'] ?? null;
            if (is_string($style)) {
                $built[] = self::builtFile('Storefront CSS', $style);
            }
            $entry = $manifest['storefront/src/main.jsx'] ?? null;
            if (is_array($entry)) {
                foreach ((array) ($entry['css'] ?? []) as $css) {
                    if (is_string($css) && $css !== $style) {
                        $built[] = self::builtFile('Storefront CSS', $css);
                    }
                }
                $script = $entry['file'] ?? null;
                if (is_string($script)) {
                    $built[] = self::builtFile('Storefront JavaScript', $script);
                }
            }
        }

        $theme = [];
        try {
            foreach (app(ThemeResolver::class)->cssUrls() as $url) {
                $path = parse_url($url, PHP_URL_PATH) ?: $url;
                if (! preg_match('#/themes/([^/]+)/(.+)$#', (string) $path, $match)) {
                    continue;
                }
                $relative = 'themes/'.$match[1].'/'.$match[2];
                $file = public_path($relative);
                if (! is_file($file)) {
                    $file = base_path($relative);
                }
                $theme[] = [
                    'name' => $match[1].' / '.$match[2],
                    'file' => $relative,
                    'size' => is_file($file) ? self::size((int) filesize($file)) : '—',
                ];
            }
        } catch (\Throwable) {
            $theme = [];
        }

        return [
            'ready' => $built !== [],
            'built' => $built,
            'theme' => $theme,
        ];
    }

    /**
     * @return array{name: string, file: string, size: string}
     */
    protected static function builtFile(string $name, string $relative): array
    {
        $file = public_path('build/'.$relative);

        return [
            'name' => $name,
            'file' => 'build/'.$relative,
            'size' => is_file($file) ? self::size((int) filesize($file)) : '—',
        ];
    }

    /**
     * @return list<array{method: string, url: string, time_ms: float|int, query_count: int, memory_kb: int}>
     */
    protected static function slowest(): array
    {
        $rows = RequestProfiler::history();
        usort($rows, function ($left, $right) {
            return ((float) ($right['time_ms'] ?? 0)) <=> ((float) ($left['time_ms'] ?? 0));
        });
        $slow = [];
        foreach (array_slice($rows, 0, 5) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $slow[] = [
                'method' => (string) ($row['method'] ?? ''),
                'url' => (string) ($row['url'] ?? ''),
                'time_ms' => $row['time_ms'] ?? 0,
                'query_count' => (int) ($row['query_count'] ?? 0),
                'memory_kb' => (int) ($row['memory_kb'] ?? 0),
            ];
        }

        return $slow;
    }

    protected static function size(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024).' KB';
        }

        return round($bytes / 1048576, 1).' MB';
    }
}
