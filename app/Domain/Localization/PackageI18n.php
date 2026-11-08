<?php

namespace App\Domain\Localization;

use App\Domain\Localization\Models\Language;
use App\Domain\Plugin\Models\InstalledPlugin;
use App\Domain\Plugin\PluginRegistry;
use Illuminate\Support\Facades\File;

/**
 * Extension and theme UI chrome packs.
 *
 * Each package may ship `i18n/ui.json`:
 * {
 *   "module.weblytic.mainmenu.configure.title": "Configure menu",
 *   "module.weblytic.mainmenu.column.label": "Label"
 * }
 *
 * Keys must start with `module.{vendor}.{name}.` or `theme.{code}.`.
 * Merchants translate them under International → Translations → Modules / Themes.
 */
final class PackageI18n
{
    /**
     * @return list<array{surface: string, page: string, key: string, source: string}>
     */
    public static function catalogHits(): array
    {
        $hits = [];
        foreach (self::loadPacks() as $key => $source) {
            $page = self::pageIdForKey($key);
            if ($page === null) {
                continue;
            }
            $hits[] = [
                'surface' => 'admin',
                'page' => $page,
                'key' => $key,
                'source' => $source,
            ];
        }

        return $hits;
    }

    /**
     * Packages that have (or may have) translation pages, for the Translations UI.
     *
     * @return list<array{type: string, id: string, name: string, page: string}>
     */
    public static function packages(): array
    {
        $byPage = [];

        try {
            $registry = app(PluginRegistry::class);
            foreach ($registry->discover() as $manifest) {
                $page = 'module.'.$manifest->vendor.'.'.$manifest->name;
                $byPage[$page] = [
                    'type' => 'module',
                    'id' => $manifest->vendor.'/'.$manifest->name,
                    'name' => (string) ($manifest->displayName ?: $manifest->name),
                    'page' => $page,
                ];
            }
        } catch (\Throwable) {
            // ignore
        }

        try {
            foreach (InstalledPlugin::query()->orderBy('display_name')->get() as $plugin) {
                $page = 'module.'.$plugin->vendor.'.'.$plugin->name;
                $byPage[$page] = [
                    'type' => 'module',
                    'id' => $plugin->vendor.'/'.$plugin->name,
                    'name' => (string) ($plugin->display_name ?: $plugin->name),
                    'page' => $page,
                ];
            }
        } catch (\Throwable) {
            // DB unavailable during install.
        }

        foreach (self::themeRoots() as $code => $root) {
            $name = ucfirst(str_replace(['-', '_'], ' ', $code));
            $themeJson = $root.DIRECTORY_SEPARATOR.'theme.json';
            if (is_file($themeJson)) {
                $decoded = json_decode((string) file_get_contents($themeJson), true);
                if (is_array($decoded) && is_string($decoded['name'] ?? null) && trim($decoded['name']) !== '') {
                    $name = trim($decoded['name']);
                }
            }
            $page = 'theme.'.$code;
            $byPage[$page] = [
                'type' => 'theme',
                'id' => $code,
                'name' => $name,
                'page' => $page,
            ];
        }

        $out = array_values($byPage);
        usort($out, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

        return $out;
    }

    public static function translate(string $key, string $fallback, ?string $locale = null): string
    {
        $locale = $locale ?: (string) (Language::currentCode() ?: 'en');
        $messages = UiI18n::messages($locale, 'admin');
        $value = $messages[$key] ?? '';

        return ($value !== '') ? $value : $fallback;
    }

    /**
     * @return array<string, string> key => English source
     */
    protected static function loadPacks(): array
    {
        $pack = [];

        try {
            $registry = app(PluginRegistry::class);
            foreach ($registry->discover() as $manifest) {
                $root = $manifest->path;
                if ($root === '' || ! is_dir($root)) {
                    continue;
                }
                $prefix = 'module.'.$manifest->vendor.'.'.$manifest->name.'.';
                foreach (self::readUiJson($root.DIRECTORY_SEPARATOR.'i18n'.DIRECTORY_SEPARATOR.'ui.json', $prefix) as $key => $source) {
                    $pack[$key] = $source;
                }
            }
        } catch (\Throwable) {
            // ignore
        }

        foreach (self::themeRoots() as $code => $root) {
            $prefix = 'theme.'.$code.'.';
            foreach (self::readUiJson($root.DIRECTORY_SEPARATOR.'i18n'.DIRECTORY_SEPARATOR.'ui.json', $prefix) as $key => $source) {
                $pack[$key] = $source;
            }
        }

        return $pack;
    }

    /**
     * @return array<string, string>
     */
    protected static function readUiJson(string $path, string $requiredPrefix): array
    {
        if (! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $key => $source) {
            if (! is_string($key) || ! is_string($source)) {
                continue;
            }
            $key = trim($key);
            $source = trim($source);
            if ($key === '' || $source === '' || ! str_starts_with($key, $requiredPrefix)) {
                continue;
            }
            $out[$key] = $source;
        }

        return $out;
    }

    protected static function pageIdForKey(string $key): ?string
    {
        if (preg_match('/^(module\.[a-z0-9_-]+\.[a-z0-9_-]+)\./', $key, $m)) {
            return $m[1];
        }
        if (preg_match('/^(theme\.[a-z0-9_-]+)\./', $key, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * @return array<string, string> theme code => absolute root (prefer folder that ships i18n/ui.json)
     */
    protected static function themeRoots(): array
    {
        $candidates = [];
        $dirs = [
            base_path('storefront/themes'),
            base_path('themes'),
            public_path('themes'),
            resource_path('themes'),
        ];
        foreach ($dirs as $base) {
            if (! is_dir($base)) {
                continue;
            }
            foreach (File::directories($base) as $dir) {
                $code = strtolower(basename($dir));
                if ($code === '') {
                    continue;
                }
                $candidates[$code][] = $dir;
            }
        }

        $roots = [];
        foreach ($candidates as $code => $paths) {
            $withPack = null;
            foreach ($paths as $path) {
                if (is_file($path.DIRECTORY_SEPARATOR.'i18n'.DIRECTORY_SEPARATOR.'ui.json')) {
                    $withPack = $path;
                    break;
                }
            }
            $roots[$code] = $withPack ?? $paths[0];
        }

        return $roots;
    }
}
