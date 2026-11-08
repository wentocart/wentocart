<?php

namespace App\Domain\Theme;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;

class ThemeRegistry
{
    public const CLASSIC = 'classic';

    public const DEFAULT_ALIAS = 'default';

    public function bundledRoot(): string
    {
        return base_path('themes');
    }

    public function installedRoot(): string
    {
        return storage_path('app/themes');
    }

    public function publicRoot(): string
    {
        return public_path('themes');
    }

    public function canonical(?string $code): string
    {
        $code = strtolower(trim((string) $code));
        if ($code === '' || $code === self::DEFAULT_ALIAS) {
            return self::CLASSIC;
        }

        return $code;
    }

    public function path(string $code): ?string
    {
        $code = $this->canonical($code);
        $bundled = $this->bundledRoot().DIRECTORY_SEPARATOR.$code;
        if (is_dir($bundled) && is_file($bundled.DIRECTORY_SEPARATOR.'theme.json')) {
            return $bundled;
        }
        $installed = $this->installedRoot().DIRECTORY_SEPARATOR.$code;
        if (is_dir($installed) && is_file($installed.DIRECTORY_SEPARATOR.'theme.json')) {
            return $installed;
        }

        return null;
    }

    public function get(string $code): ?ThemeManifest
    {
        $code = $this->canonical($code);
        $path = $this->path($code);
        if (! $path) {
            return null;
        }

        return $this->read($path, $this->isBundled($code));
    }

    public function getOrClassic(?string $code): ThemeManifest
    {
        return $this->get((string) $code) ?: $this->classic();
    }

    public function classic(): ThemeManifest
    {
        $theme = $this->get(self::CLASSIC);
        if (! $theme) {
            throw new InvalidArgumentException('Bundled Classic theme is missing.');
        }

        return $theme;
    }

    public function isBundled(string $code): bool
    {
        $code = $this->canonical($code);
        $bundled = $this->bundledRoot().DIRECTORY_SEPARATOR.$code;

        return is_dir($bundled) && is_file($bundled.DIRECTORY_SEPARATOR.'theme.json');
    }

    /**
     * @return list<ThemeManifest>
     */
    public function all(): array
    {
        $found = [];
        foreach ([$this->bundledRoot(), $this->installedRoot()] as $root) {
            if (! is_dir($root)) {
                continue;
            }
            foreach (File::directories($root) as $dir) {
                $code = basename($dir);
                if (isset($found[$code]) || ! is_file($dir.DIRECTORY_SEPARATOR.'theme.json')) {
                    continue;
                }
                try {
                    $found[$code] = $this->read($dir, $root === $this->bundledRoot());
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        $list = array_values($found);
        usort($list, function (ThemeManifest $a, ThemeManifest $b) {
            if ($a->name === self::CLASSIC) {
                return -1;
            }
            if ($b->name === self::CLASSIC) {
                return 1;
            }

            return strcasecmp($a->displayName, $b->displayName);
        });

        return $list;
    }

    public function exists(string $code): bool
    {
        return $this->path($code) !== null;
    }

    public function read(string $path, bool $bundled = false): ThemeManifest
    {
        $file = $path.DIRECTORY_SEPARATOR.'theme.json';
        if (! is_file($file)) {
            throw new InvalidArgumentException('theme.json is missing.');
        }
        $raw = json_decode((string) File::get($file), true);
        if (! is_array($raw)) {
            throw new InvalidArgumentException('theme.json is not valid JSON.');
        }

        $schemaFile = $path.DIRECTORY_SEPARATOR.'settings_schema.json';
        if (! isset($raw['settings_schema']) && is_file($schemaFile)) {
            $schema = json_decode((string) File::get($schemaFile), true);
            if (is_array($schema)) {
                $raw['settings_schema'] = $schema;
            }
        }

        $manifest = ThemeManifest::fromArray($raw, $path, $bundled);
        if ($bundled === false && $manifest->name === self::CLASSIC) {
            throw new InvalidArgumentException('Classic cannot be replaced by an uploaded zip. Use a child theme or a different name.');
        }

        return $manifest;
    }
}
