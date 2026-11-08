<?php

namespace App\Domain\Plugin;

use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

/**
 * Enforces the Extension Developer Standard tree and naming.
 * Runs on install/update alongside SecurityScanner.
 *
 * @see docs/extensions/STRUCTURE.md
 */
class StructureValidator
{
    public function assertStandardTree(string $root, PluginManifest $manifest): void
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if (! is_file($root.'/app.json')) {
            throw ValidationException::withMessages(['zip' => 'app.json is required at the extension root.']);
        }
        if (! is_file($root.'/README.md')) {
            throw ValidationException::withMessages(['zip' => 'README.md is required at the extension root. See docs/extensions/STRUCTURE.md.']);
        }
        if (! is_file($root.'/src/Plugin.php')) {
            throw ValidationException::withMessages(['zip' => 'src/Plugin.php is required.']);
        }

        $this->assertTopLevel($root, $manifest);
        $this->assertSrcTree($root, $manifest);
        $this->assertPluginEntry($root, $manifest);
        $this->assertControllers($root, $manifest);
    }

    protected function assertTopLevel(string $root, PluginManifest $manifest): void
    {
        $preview = is_string($manifest->preview) ? str_replace('\\', '/', $manifest->preview) : null;
        if (is_string($preview) && ($preview === '' || str_contains($preview, '..') || str_contains($preview, '/'))) {
            throw ValidationException::withMessages(['zip' => 'app.json preview must be a file name in the extension root (for example preview.svg).']);
        }

        foreach (File::files($root) as $file) {
            $name = $file->getFilename();
            $lower = strtolower($name);
            if ($name === 'app.json' || $lower === 'readme.md' || $lower === 'changelog.txt' || $lower === 'instruction.md') {
                continue;
            }
            if ($preview !== null && $name === $preview) {
                continue;
            }
            if (preg_match('/^preview\.(svg|png|jpe?g|webp|gif)$/i', $name)) {
                continue;
            }
            throw ValidationException::withMessages([
                'zip' => 'Unexpected file at extension root: '.$name.'. Allowed: app.json, README.md, changelog.txt, instruction.md, preview image, plus src/ and assets/.',
            ]);
        }

        foreach (File::directories($root) as $dir) {
            $name = basename($dir);
            if (! in_array($name, ['src', 'assets'], true)) {
                throw ValidationException::withMessages([
                    'zip' => 'Unexpected folder at extension root: '.$name.'/. Only src/ and assets/ are allowed.',
                ]);
            }
        }
    }

    protected function assertSrcTree(string $root, PluginManifest $manifest): void
    {
        $src = $root.'/src';
        foreach (File::files($src) as $file) {
            if ($file->getFilename() !== 'Plugin.php') {
                throw ValidationException::withMessages([
                    'zip' => 'Only Plugin.php may sit directly under src/. Move other PHP into Http/ or Support/.',
                ]);
            }
        }

        foreach (File::directories($src) as $dir) {
            $name = basename($dir);
            if (! in_array($name, ['Http', 'Support'], true)) {
                throw ValidationException::withMessages([
                    'zip' => 'Unexpected folder under src/: '.$name.'/. Only Http/ and Support/ are allowed.',
                ]);
            }
            if (File::directories($dir) !== []) {
                throw ValidationException::withMessages([
                    'zip' => 'src/'.$name.'/ must be a single level (no nested folders).',
                ]);
            }
            foreach (File::files($dir) as $file) {
                if (strtolower($file->getExtension()) !== 'php') {
                    throw ValidationException::withMessages([
                        'zip' => 'src/'.$name.'/ may only contain PHP files.',
                    ]);
                }
                if ($name === 'Http' && ! str_ends_with($file->getFilename(), 'Controller.php')) {
                    throw ValidationException::withMessages([
                        'zip' => 'src/Http/ files must be named *Controller.php (found '.$file->getFilename().').',
                    ]);
                }
            }
        }

        if (is_dir($root.'/assets') && $manifest->type === 'safe'
            && ! $manifest->can('admin.assets') && ! $manifest->can('storefront.pages')) {
            throw ValidationException::withMessages([
                'zip' => 'assets/ requires admin.assets or storefront.pages in app.json.',
            ]);
        }
    }

    protected function assertPluginEntry(string $root, PluginManifest $manifest): void
    {
        $code = (string) File::get($root.'/src/Plugin.php');
        $expectedNs = 'Modules\\'.PluginManifest::studly($manifest->vendor).'\\'.PluginManifest::studly($manifest->name);
        if (! preg_match('/namespace\s+'.preg_quote($expectedNs, '/').'\s*;/', $code)) {
            throw ValidationException::withMessages([
                'zip' => 'src/Plugin.php must use namespace '.$expectedNs.'.',
            ]);
        }
        if (! preg_match('/\bclass\s+Plugin\b/', $code)) {
            throw ValidationException::withMessages([
                'zip' => 'src/Plugin.php must declare class Plugin.',
            ]);
        }
        if (! preg_match('/\bextends\s+BasePlugin\b/', $code) && ! preg_match('/\bextends\s+\\\\?App\\\\Domain\\\\Plugin\\\\Support\\\\BasePlugin\b/', $code)) {
            throw ValidationException::withMessages([
                'zip' => 'src/Plugin.php must extend BasePlugin.',
            ]);
        }
    }

    protected function assertControllers(string $root, PluginManifest $manifest): void
    {
        $http = $root.'/src/Http';
        if (! is_dir($http)) {
            return;
        }
        $expectedNs = 'Modules\\'.PluginManifest::studly($manifest->vendor).'\\'.PluginManifest::studly($manifest->name).'\\Http';
        foreach (File::files($http) as $file) {
            $code = (string) File::get($file->getPathname());
            $class = pathinfo($file->getFilename(), PATHINFO_FILENAME);
            if (! preg_match('/namespace\s+'.preg_quote($expectedNs, '/').'\s*;/', $code)) {
                throw ValidationException::withMessages([
                    'zip' => $file->getFilename().' must use namespace '.$expectedNs.'.',
                ]);
            }
            if (! preg_match('/\bclass\s+'.preg_quote($class, '/').'\b/', $code)) {
                throw ValidationException::withMessages([
                    'zip' => $file->getFilename().' must declare class '.$class.'.',
                ]);
            }
        }
    }
}
