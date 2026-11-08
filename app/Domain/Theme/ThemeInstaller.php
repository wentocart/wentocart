<?php

namespace App\Domain\Theme;

use App\Domain\Settings\Models\FileChangeLog;
use App\Domain\Store\Models\Store;
use FilesystemIterator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ZipArchive;

class ThemeInstaller
{
    /** @var list<string> */
    protected array $allowed = [
        'json', 'css', 'js', 'mjs', 'map', 'html', 'htm', 'txt', 'md', 'svg',
        'png', 'jpg', 'jpeg', 'webp', 'gif', 'ico', 'woff', 'woff2', 'ttf', 'otf',
    ];

    public function __construct(protected ThemeRegistry $themes) {}

    public function install(UploadedFile $zip): ThemeManifest
    {
        if ($zip->getError() !== UPLOAD_ERR_OK) {
            throw ValidationException::withMessages(['zip' => 'The zip could not be uploaded.']);
        }
        if ($zip->getSize() && $zip->getSize() > 25 * 1024 * 1024) {
            throw ValidationException::withMessages(['zip' => 'Theme zip must be 25 MB or smaller.']);
        }

        $archive = new ZipArchive();
        $open = $archive->open($zip->getRealPath());
        if ($open !== true) {
            throw ValidationException::withMessages(['zip' => 'That file is not a readable zip.']);
        }

        $tmp = storage_path('app/theme-uploads/'.uniqid('theme_', true));
        File::ensureDirectoryExists($tmp);

        try {
            $this->extractSafe($archive, $tmp);
            $archive->close();
            $root = $this->locateRoot($tmp);
            $manifest = $this->themes->read($root, false);
            $this->assertParent($manifest);
            $this->assertEngine($manifest, $root);

            $dest = $this->themes->installedRoot().DIRECTORY_SEPARATOR.$manifest->name;
            if (is_dir($dest)) {
                File::deleteDirectory($dest);
            }
            File::ensureDirectoryExists(dirname($dest));
            File::moveDirectory($root, $dest, true);
            $this->publishPublic($manifest->name);
            FileChangeLog::record('theme.install', 'themes/'.$manifest->name);

            return $this->themes->get($manifest->name) ?? $manifest;
        } finally {
            if (isset($archive) && $archive->status === ZipArchive::ER_OK) {
                // already closed
            }
            if (is_dir($tmp)) {
                File::deleteDirectory($tmp);
            }
        }
    }

    public function uninstall(string $code): void
    {
        $code = $this->themes->canonical($code);
        if ($this->themes->isBundled($code)) {
            throw ValidationException::withMessages(['theme' => 'A bundled theme cannot be deleted.']);
        }
        $path = $this->themes->installedRoot().DIRECTORY_SEPARATOR.$code;
        if (! is_dir($path)) {
            throw ValidationException::withMessages(['theme' => 'That theme is not installed.']);
        }
        File::deleteDirectory($path);
        $public = $this->themes->publicRoot().DIRECTORY_SEPARATOR.$code;
        if (is_dir($public)) {
            File::deleteDirectory($public);
        }
        Store::query()
            ->where('theme', $code)
            ->update(['theme' => ThemeRegistry::CLASSIC, 'theme_settings' => null]);
        FileChangeLog::record('theme.uninstall', 'themes/'.$code);
    }

    /**
     * Copy bundled/installed theme files into gitignored public/themes.
     *
     * force=false is incremental: missing or newer source files are copied, existing
     * matching files are left alone (merchant zips skip the ~60MB recopy).
     *
     * @return int Number of files written
     */
    public function publishPublic(string $code, bool $force = true): int
    {
        $theme = $this->themes->get($code);
        if (! $theme) {
            return 0;
        }
        $public = $this->themes->publicRoot().DIRECTORY_SEPARATOR.$theme->name;
        if ($force && is_dir($public)) {
            File::deleteDirectory($public);
        }
        File::ensureDirectoryExists($public);

        $copied = 0;
        foreach (['assets', 'dist'] as $item) {
            $from = $theme->path.DIRECTORY_SEPARATOR.$item;
            if (is_dir($from)) {
                $copied += $this->syncDirectory($from, $public.DIRECTORY_SEPARATOR.$item);
            }
        }
        foreach (['preview.svg', 'preview.png', 'preview.webp', 'theme.json', 'settings_schema.json'] as $file) {
            $copied += $this->syncFile(
                $theme->path.DIRECTORY_SEPARATOR.$file,
                $public.DIRECTORY_SEPARATOR.$file
            );
        }
        foreach (array_merge($theme->css, $theme->js) as $rel) {
            $copied += $this->syncFile(
                $theme->path.DIRECTORY_SEPARATOR.$rel,
                $public.DIRECTORY_SEPARATOR.$rel
            );
        }

        return $copied;
    }

    /**
     * Publish every bundled theme under themes/ (Classic today).
     *
     * @return int Number of files written
     */
    public function publishBundled(bool $force = false): int
    {
        $copied = 0;
        $root = $this->themes->bundledRoot();
        if (! is_dir($root)) {
            return 0;
        }
        foreach (File::directories($root) as $dir) {
            if (! is_file($dir.DIRECTORY_SEPARATOR.'theme.json')) {
                continue;
            }
            $copied += $this->publishPublic(basename($dir), $force);
        }

        return $copied;
    }

    protected function syncDirectory(string $from, string $to): int
    {
        $copied = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS)
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            // Sourcemaps can embed full original sources — never publish them.
            if (str_ends_with(strtolower($file->getFilename()), '.map')) {
                continue;
            }
            $rel = substr($file->getPathname(), strlen(rtrim($from, DIRECTORY_SEPARATOR)) + 1);
            $copied += $this->syncFile($file->getPathname(), $to.DIRECTORY_SEPARATOR.$rel);
        }

        return $copied;
    }

    protected function syncFile(string $from, string $to): int
    {
        if (! is_file($from)) {
            return 0;
        }
        if (is_file($to)
            && filesize($from) === filesize($to)
            && (int) filemtime($from) <= (int) filemtime($to)) {
            return 0;
        }
        File::ensureDirectoryExists(dirname($to));
        File::copy($from, $to);
        @touch($to, (int) filemtime($from));

        return 1;
    }

    protected function extractSafe(ZipArchive $zip, string $tmp): void
    {
        $tmpReal = realpath($tmp) ?: $tmp;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }
            if (str_contains($name, '..') || str_starts_with($name, '/')) {
                throw ValidationException::withMessages(['zip' => 'The zip contains an unsafe path.']);
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($ext === '' || ! in_array($ext, $this->allowed, true)) {
                throw ValidationException::withMessages(['zip' => 'Theme zips may only include static assets. Remove .'.$ext.' files.']);
            }
            $target = $tmp.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $name);
            $parent = dirname($target);
            File::ensureDirectoryExists($parent);
            $parentReal = realpath($parent) ?: $parent;
            if (! str_starts_with($parentReal, $tmpReal)) {
                throw ValidationException::withMessages(['zip' => 'The zip contains an unsafe path.']);
            }
            $contents = $zip->getFromIndex($i);
            if ($contents === false) {
                continue;
            }
            File::put($target, $contents);
        }
    }

    protected function locateRoot(string $tmp): string
    {
        if (is_file($tmp.DIRECTORY_SEPARATOR.'theme.json')) {
            return $tmp;
        }
        $dirs = File::directories($tmp);
        if (count($dirs) === 1 && is_file($dirs[0].DIRECTORY_SEPARATOR.'theme.json')) {
            return $dirs[0];
        }

        throw ValidationException::withMessages(['zip' => 'theme.json must sit at the zip root or inside a single folder.']);
    }

    protected function assertParent(ThemeManifest $manifest): void
    {
        if (! $manifest->parent) {
            return;
        }
        if ($manifest->parent === $manifest->name) {
            throw ValidationException::withMessages(['zip' => 'A theme cannot parent itself.']);
        }
        if (! $this->themes->exists($manifest->parent)) {
            throw ValidationException::withMessages(['zip' => 'Parent theme '.$manifest->parent.' is not installed.']);
        }
    }

    protected function assertEngine(ThemeManifest $manifest, string $root): void
    {
        if ($manifest->engine !== 'spa-dist') {
            return;
        }
        $hasJs = $manifest->js !== [];
        $hasIndex = is_file($root.DIRECTORY_SEPARATOR.'dist'.DIRECTORY_SEPARATOR.'index.html');
        if (! $hasJs && ! $hasIndex) {
            throw ValidationException::withMessages(['zip' => 'A full override theme must include dist/index.html or assets.js in theme.json.']);
        }
    }
}
