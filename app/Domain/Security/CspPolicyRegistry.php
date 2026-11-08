<?php

namespace App\Domain\Security;

use App\Domain\Plugin\Capabilities;
use App\Domain\Plugin\Models\InstalledPlugin;
use App\Domain\Plugin\PluginRegistry;

/**
 * Merges browser CSP directives contributed by enabled extensions.
 */
final class CspPolicyRegistry
{
    /** @var list<string> */
    public const ALLOWED_DIRECTIVES = [
        'script-src',
        'style-src',
        'style-src-elem',
        'connect-src',
        'frame-src',
        'child-src',
        'img-src',
        'font-src',
    ];

    /**
     * @return array<string, list<string>>
     */
    public static function collect(): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('plugins')) {
            return [];
        }

        $registry = app(PluginRegistry::class);
        $merged = [];

        foreach (InstalledPlugin::query()->where('is_enabled', true)->get() as $row) {
            if (! self::enabledForCurrentShop($row)) {
                continue;
            }
            $manifest = $registry->find($row->vendor, $row->name);
            if (! $manifest || ! $manifest->can(Capabilities::SECURITY_CSP)) {
                continue;
            }
            foreach ($manifest->browserCsp as $directive => $sources) {
                if (! in_array($directive, self::ALLOWED_DIRECTIVES, true)) {
                    continue;
                }
                foreach ($sources as $source) {
                    if (! self::isAllowedSource($source)) {
                        continue;
                    }
                    $merged[$directive][] = $source;
                }
            }
        }

        foreach ($merged as $directive => $sources) {
            $merged[$directive] = array_values(array_unique($sources));
        }

        return $merged;
    }

    /**
     * @param  array<string, list<string>>  $base
     * @param  array<string, list<string>>  $extension
     * @return list<string>
     */
    public static function mergePolicy(array $base, array $extension): array
    {
        $directives = [];
        foreach ($base as $directive => $sources) {
            $directives[$directive] = $sources;
        }
        foreach ($extension as $directive => $sources) {
            $existing = $directives[$directive] ?? [];
            $directives[$directive] = array_values(array_unique(array_merge($existing, $sources)));
        }

        $parts = [];
        foreach ($directives as $directive => $sources) {
            if ($sources === []) {
                continue;
            }
            // 'none' is valid only as the sole source. Browsers ignore it when
            // an extension adds real hosts, and they warn on the contradiction.
            if (in_array("'none'", $sources, true) && count($sources) > 1) {
                $sources = array_values(array_filter(
                    $sources,
                    static fn (string $source): bool => $source !== "'none'"
                ));
            }
            if ($sources === []) {
                continue;
            }
            $parts[] = $directive.' '.implode(' ', $sources);
        }

        return $parts;
    }

    public static function isAllowedSource(string $source): bool
    {
        $source = trim($source);
        if ($source === '') {
            return false;
        }
        if (in_array($source, ["'self'", "'unsafe-inline'", "'unsafe-eval'", 'data:', 'blob:'], true)) {
            return true;
        }
        if (! str_starts_with($source, 'https://')) {
            return false;
        }
        $host = parse_url($source, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }
        if (str_contains($host, '*')) {
            return false;
        }

        return (bool) preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $host);
    }

    protected static function enabledForCurrentShop(InstalledPlugin $row): bool
    {
        try {
            $storeId = app(\App\Domain\Store\StoreContext::class)->id();
            if (! $storeId || ! class_exists(\App\Domain\Store\Models\PluginStore::class)) {
                return true;
            }
            $pivot = \App\Domain\Store\Models\PluginStore::query()
                ->where('store_id', $storeId)
                ->where('plugin', $row->idKey())
                ->first();

            return $pivot ? (bool) $pivot->is_enabled : true;
        } catch (\Throwable) {
            return true;
        }
    }
}
