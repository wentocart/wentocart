<?php

namespace App\Domain\Plugin;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;

class PluginRegistry
{
    /**
     * Single product home for every extension (defaults, upload, marketplace).
     */
    public function root(): string
    {
        return base_path('extensions');
    }

    /** @deprecated Use root() — kept for older callers during transition. */
    public function bundledRoot(): string
    {
        return $this->root();
    }

    /** @deprecated Use root() — kept for older callers during transition. */
    public function installedRoot(): string
    {
        return $this->root();
    }

    public function pathFor(string $vendor, string $name): string
    {
        $vendor = strtolower($vendor);
        $name = strtolower($name);

        return $this->root().DIRECTORY_SEPARATOR.$vendor.DIRECTORY_SEPARATOR.$name;
    }

    public function read(string $path, bool $bundled = false): PluginManifest
    {
        $file = $path.DIRECTORY_SEPARATOR.'app.json';
        if (! is_file($file)) {
            throw new InvalidArgumentException('app.json is missing.');
        }
        $raw = json_decode((string) File::get($file), true);
        if (! is_array($raw)) {
            throw new InvalidArgumentException('app.json is not valid JSON.');
        }

        return PluginManifest::fromArray($raw, $path, $bundled);
    }

    /** @return list<PluginManifest> */
    public function discover(): array
    {
        $out = [];
        $root = $this->root();
        if (! is_dir($root)) {
            return [];
        }

        foreach (File::directories($root) as $vendorDir) {
            $vendorSlug = strtolower(basename($vendorDir));
            if (! ExtensionSlug::isValid($vendorSlug)) {
                continue;
            }
            foreach (File::directories($vendorDir) as $appDir) {
                $nameSlug = strtolower(basename($appDir));
                if (! ExtensionSlug::isValid($nameSlug)) {
                    continue;
                }
                if (! is_file($appDir.DIRECTORY_SEPARATOR.'app.json')) {
                    continue;
                }
                try {
                    $manifest = $this->read($appDir, false);
                    if ($manifest->vendor !== $vendorSlug || $manifest->name !== $nameSlug) {
                        continue;
                    }
                    $out[] = $manifest;
                } catch (InvalidArgumentException) {
                    continue;
                }
            }
        }

        return $out;
    }

    public function find(string $vendor, string $name): ?PluginManifest
    {
        $vendor = strtolower($vendor);
        $name = strtolower($name);
        $path = $this->pathFor($vendor, $name);
        if (! is_file($path.DIRECTORY_SEPARATOR.'app.json')) {
            return null;
        }
        try {
            return $this->read($path, false);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @return array<string, PluginManifest>
     */
    public function discoverById(): array
    {
        $out = [];
        foreach ($this->discover() as $manifest) {
            $out[$manifest->id()] = $manifest;
        }

        return $out;
    }

    public function existsOnDisk(string $vendor, string $name): bool
    {
        return is_dir($this->pathFor($vendor, $name))
            && is_file($this->pathFor($vendor, $name).DIRECTORY_SEPARATOR.'app.json');
    }

    /** @deprecated Bundled undeleteable rule removed — always false. */
    public function isBundledOnDisk(string $vendor, string $name): bool
    {
        return false;
    }
}
