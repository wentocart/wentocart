<?php

namespace App\Domain\Plugin;

use App\Domain\Plugin\Models\InstalledPlugin;
use App\Domain\Settings\Models\FileChangeLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;
use ZipArchive;

class PluginInstaller
{
    public function __construct(
        protected PluginRegistry $registry,
        protected SecurityScanner $scanner,
        protected StructureValidator $structure,
        protected CompatibilityChecker $compatibility,
        protected DependencyResolver $dependencies,
    ) {}

    /**
     * Register an on-disk extension under extensions/{vendor}/{name} (no second copy).
     */
    public function installFromSource(string $vendor, string $name): PluginManifest
    {
        $src = app(LocalExtensionCatalog::class)->sourcePath($vendor, $name);
        $manifest = $this->registry->read($src, false);
        if ($manifest->type !== 'safe' && ! CertifiedApps::mayInstall($manifest)) {
            throw ValidationException::withMessages([
                'app' => 'Only Safe extensions, scanned payment/shipping driver zips, or APP_KEY-signed certified zips can be installed from the catalog.',
            ]);
        }

        if (InstalledPlugin::query()->where('vendor', $manifest->vendor)->where('name', $manifest->name)->exists()) {
            throw ValidationException::withMessages([
                'app' => $manifest->displayName.' is already installed.',
            ]);
        }

        $this->compatibility->assertCompatible($manifest);
        $this->dependencies->assertInstallable($manifest);
        $this->structure->assertStandardTree($src, $manifest);
        $this->scanner->assertSafeTree($src, $manifest);

        $this->persist($manifest, false, enabled: true);
        FileChangeLog::record('app.install', 'extensions/'.$manifest->id());

        return $this->registry->find($manifest->vendor, $manifest->name) ?? $manifest;
    }

    public function install(UploadedFile $zip): PluginManifest
    {
        [$manifest, $extractedRoot, $cleanup] = $this->unpackAndValidate($zip, forUpdate: false);

        try {
            $this->compatibility->assertCompatible($manifest);
            $this->dependencies->assertInstallable($manifest);

            $dest = $this->destPath($manifest->vendor, $manifest->name);
            if (is_dir($dest)) {
                File::deleteDirectory($dest);
            }
            File::ensureDirectoryExists(dirname($dest));
            File::moveDirectory($extractedRoot, $dest, true);

            $final = $this->registry->read($dest, false);
            $this->persist($final, false, enabled: true);
            FileChangeLog::record('app.install', 'extensions/'.$final->id());

            return $this->registry->find($final->vendor, $final->name) ?? $final;
        } finally {
            $cleanup();
        }
    }

    /**
     * Install Extension upload flow:
     * - not installed → install (enabled)
     * - same/older → friendly no-op
     * - newer → stage zip; admin clicks Upgrade to apply
     *
     * @return array{action: 'install'|'staged'|'noop', manifest: PluginManifest, pending_version?: string}
     */
    public function receiveUpload(UploadedFile $zip): array
    {
        [$manifest, $extractedRoot, $cleanup] = $this->unpackAndValidate($zip, forUpdate: true);
        $cleanup();

        $existing = InstalledPlugin::query()
            ->where('vendor', $manifest->vendor)
            ->where('name', $manifest->name)
            ->first();

        if (! $existing) {
            return ['action' => 'install', 'manifest' => $this->install($zip)];
        }

        if (version_compare((string) $manifest->version, (string) $existing->version, '<=')) {
            return ['action' => 'noop', 'manifest' => $this->registry->find($existing->vendor, $existing->name) ?? $manifest];
        }

        $this->stageZip($zip, $manifest);

        return [
            'action' => 'staged',
            'manifest' => $this->registry->find($existing->vendor, $existing->name) ?? $manifest,
            'pending_version' => (string) $manifest->version,
        ];
    }

    /**
     * @deprecated Use receiveUpload()
     *
     * @return array{manifest: PluginManifest, action: 'install'|'upgrade'|'staged'|'noop'}
     */
    public function installOrUpgrade(UploadedFile $zip): array
    {
        $result = $this->receiveUpload($zip);

        return ['manifest' => $result['manifest'], 'action' => $result['action']];
    }

    /**
     * @return array{version: string, display_name: string, staged_at: string}|null
     */
    public function pendingStage(string $vendor, string $name): ?array
    {
        $metaPath = $this->stageDir($vendor, $name).DIRECTORY_SEPARATOR.'meta.json';
        $zipPath = $this->stageDir($vendor, $name).DIRECTORY_SEPARATOR.'package.zip';
        if (! is_file($metaPath) || ! is_file($zipPath)) {
            return null;
        }
        $meta = json_decode((string) file_get_contents($metaPath), true);
        if (! is_array($meta) || empty($meta['version'])) {
            return null;
        }

        return [
            'version' => (string) $meta['version'],
            'display_name' => (string) ($meta['display_name'] ?? $vendor.'/'.$name),
            'staged_at' => (string) ($meta['staged_at'] ?? ''),
        ];
    }

    public function applyStagedUpgrade(string $vendor, string $name): PluginManifest
    {
        $zip = $this->stagedUploadedFile($vendor, $name);
        try {
            $manifest = $this->update($zip, $vendor, $name);
            $this->clearStage($vendor, $name);

            return $manifest;
        } finally {
            // staged copy stays until clearStage; temp UploadedFile path is the staged zip itself — do not unlink
        }
    }

    /**
     * Wipe settings (uninstall) then reinstall from staged zip if present, else from on-disk files. Enabled after.
     */
    public function reset(string $vendor, string $name): PluginManifest
    {
        $vendor = strtolower($vendor);
        $name = strtolower($name);
        $staged = $this->pendingStage($vendor, $name);
        $zip = $staged ? $this->stagedUploadedFile($vendor, $name) : null;

        $this->uninstall($vendor, $name);

        if ($zip) {
            try {
                // Treat as fresh install from staged package
                [$manifest, $extractedRoot, $cleanup] = $this->unpackAndValidate($zip, forUpdate: false);
                try {
                    $this->compatibility->assertCompatible($manifest);
                    $this->dependencies->assertInstallable($manifest);
                    $dest = $this->destPath($manifest->vendor, $manifest->name);
                    if (is_dir($dest)) {
                        File::deleteDirectory($dest);
                    }
                    File::ensureDirectoryExists(dirname($dest));
                    File::moveDirectory($extractedRoot, $dest, true);
                    $final = $this->registry->read($dest, false);
                    $this->persist($final, false, enabled: true);
                    FileChangeLog::record('app.reset', 'extensions/'.$final->id());
                    $this->clearStage($vendor, $name);

                    return $this->registry->find($final->vendor, $final->name) ?? $final;
                } finally {
                    $cleanup();
                }
            } catch (Throwable $e) {
                throw $e;
            }
        }

        $manifest = $this->installFromSource($vendor, $name);
        FileChangeLog::record('app.reset', 'extensions/'.$manifest->id());

        return $manifest;
    }

    protected function stageDir(string $vendor, string $name): string
    {
        return storage_path('app/extension-stages/'.strtolower($vendor).'/'.strtolower($name));
    }

    protected function stageZip(UploadedFile $zip, PluginManifest $manifest): void
    {
        $dir = $this->stageDir($manifest->vendor, $manifest->name);
        File::ensureDirectoryExists($dir);
        $dest = $dir.DIRECTORY_SEPARATOR.'package.zip';
        if (is_file($dest)) {
            @unlink($dest);
        }
        // Prefer copy — UploadedFile may be cleaned by PHP after the request.
        if (! @copy((string) $zip->getRealPath(), $dest)) {
            File::put($dest, (string) file_get_contents((string) $zip->getRealPath()));
        }
        File::put($dir.DIRECTORY_SEPARATOR.'meta.json', json_encode([
            'vendor' => $manifest->vendor,
            'name' => $manifest->name,
            'version' => $manifest->version,
            'display_name' => $manifest->displayName,
            'staged_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT));
    }

    protected function stagedUploadedFile(string $vendor, string $name): UploadedFile
    {
        $path = $this->stageDir($vendor, $name).DIRECTORY_SEPARATOR.'package.zip';
        if (! is_file($path)) {
            throw ValidationException::withMessages([
                'app' => 'No staged upgrade package. Upload a newer zip via Install Extension first.',
            ]);
        }

        return new UploadedFile($path, basename($path), 'application/zip', null, true);
    }

    public function clearStage(string $vendor, string $name): void
    {
        $dir = $this->stageDir($vendor, $name);
        if (is_dir($dir)) {
            File::deleteDirectory($dir);
        }
    }

    public function update(UploadedFile $zip, ?string $expectedVendor = null, ?string $expectedName = null): PluginManifest
    {
        [$manifest, $extractedRoot, $cleanup] = $this->unpackAndValidate($zip, forUpdate: true);

        if ($expectedVendor !== null && $expectedName !== null) {
            if ($manifest->vendor !== strtolower($expectedVendor) || $manifest->name !== strtolower($expectedName)) {
                $cleanup();
                throw ValidationException::withMessages([
                    'zip' => 'Zip identity '.$manifest->id().' does not match '.$expectedVendor.'/'.$expectedName.'.',
                ]);
            }
        }

        $existing = InstalledPlugin::query()
            ->where('vendor', $manifest->vendor)
            ->where('name', $manifest->name)
            ->first();
        if (! $existing) {
            $cleanup();
            throw ValidationException::withMessages([
                'zip' => 'Extension '.$manifest->id().' is not installed. Install it first.',
            ]);
        }

        $dest = $this->destPath($manifest->vendor, $manifest->name);
        $backup = null;
        $previousEnabled = (bool) $existing->is_enabled;
        $previousMeta = $existing->only([
            'display_name', 'version', 'author', 'description', 'type',
            'capabilities', 'hooks', 'path', 'checksum', 'health_status', 'last_error',
        ]);

        try {
            $this->compatibility->assertCompatible($manifest);
            $map = $this->dependencies->installedMap();
            $map[$manifest->id()] = [
                'version' => $manifest->version,
                'enabled' => $previousEnabled,
            ];
            $errors = $this->dependencies->validateForInstallOrUpdate($manifest, $map);
            if ($errors !== []) {
                throw ValidationException::withMessages(['zip' => $errors[0], 'dependencies' => $errors]);
            }

            $backup = $this->backup($manifest->vendor, $manifest->name, (string) $existing->version);
            if (is_dir($dest)) {
                File::deleteDirectory($dest);
            }
            File::ensureDirectoryExists(dirname($dest));
            File::moveDirectory($extractedRoot, $dest, true);

            $final = $this->registry->read($dest, false);
            $row = $this->persist($final, false, enabled: $previousEnabled);
            $this->setHealth($row, 'ok', null);

            try {
                PluginKernel::reset();
                app(PluginKernel::class)->boot();
                if ($previousEnabled) {
                    $row->refresh();
                    if (($row->health_status ?? null) === 'error') {
                        throw new \RuntimeException($row->last_error ?: 'Extension failed to boot after update.');
                    }
                }
            } catch (Throwable $e) {
                if ($e instanceof ValidationException) {
                    throw $e;
                }
                $this->restore($backup, $dest);
                if (is_dir($dest)) {
                    $restored = $this->registry->read($dest, false);
                    $this->persist($restored, false, enabled: $previousEnabled);
                } else {
                    $existing->fill($previousMeta)->save();
                }
                $failed = InstalledPlugin::query()
                    ->where('vendor', $manifest->vendor)
                    ->where('name', $manifest->name)
                    ->first();
                if ($failed) {
                    $this->setHealth($failed, 'error', 'Update failed and was rolled back: '.$e->getMessage());
                }
                throw ValidationException::withMessages([
                    'zip' => 'Update failed. Previous version was restored. '.$e->getMessage(),
                ]);
            }

            FileChangeLog::record('app.update', 'extensions/'.$final->id());

            return $this->registry->find($final->vendor, $final->name) ?? $final;
        } finally {
            $cleanup();
        }
    }

    public function persist(PluginManifest $manifest, bool $bundled, bool $enabled): InstalledPlugin
    {
        return InstalledPlugin::query()->updateOrCreate(
            ['vendor' => $manifest->vendor, 'name' => $manifest->name],
            [
                'display_name' => $manifest->displayName,
                'version' => $manifest->version,
                'author' => $manifest->author,
                'description' => $manifest->description,
                'type' => $manifest->type,
                'capabilities' => $manifest->capabilities,
                'hooks' => $manifest->hooks,
                'is_bundled' => false,
                'is_enabled' => $enabled,
                'path' => $manifest->path,
                'checksum' => is_file($manifest->path.'/app.json') ? hash_file('sha256', $manifest->path.'/app.json') : null,
            ]
        );
    }

    /**
     * Unregister from the shop; keep files on disk so Install can run again.
     */
    public function uninstall(string $vendor, string $name): void
    {
        $this->dependencies->assertRemovable($vendor, $name);

        $row = InstalledPlugin::query()->where('vendor', $vendor)->where('name', $name)->first();
        if ($row) {
            \App\Domain\Plugin\Support\ExtensionRuntime::forgetMethods($row);
        }

        if ($row) {
            ExtensionTables::drop($vendor, $name, ExtensionTables::suffixesFromPath((string) $row->path));
        }
        $row?->settings()->delete();
        $row?->delete();
        FileChangeLog::record('app.uninstall', 'extensions/'.$vendor.'/'.$name);
    }

    /**
     * Uninstall if needed, then remove extensions/{vendor}/{name} from disk.
     */
    public function deleteFromDisk(string $vendor, string $name): void
    {
        $vendor = strtolower($vendor);
        $name = strtolower($name);
        if (! ExtensionSlug::isValid($vendor) || ! ExtensionSlug::isValid($name)) {
            throw ValidationException::withMessages(['app' => 'Use vendor/name letter/hyphen slugs.']);
        }

        if (InstalledPlugin::query()->where('vendor', $vendor)->where('name', $name)->exists()) {
            $this->uninstall($vendor, $name);
        }

        $path = $this->destPath($vendor, $name);
        $root = realpath($this->registry->root());
        $real = realpath($path);
        if ($real && $root && str_starts_with($real, $root) && is_dir($real)) {
            File::deleteDirectory($real);
            FileChangeLog::record('app.delete', 'extensions/'.$vendor.'/'.$name);
        }

        $this->pruneEmptyVendorDirectory($vendor);
    }

    /**
     * Remove extensions/{vendor} when no extensions remain under that vendor.
     */
    protected function pruneEmptyVendorDirectory(string $vendor): void
    {
        $vendor = strtolower($vendor);
        if (! ExtensionSlug::isValid($vendor)) {
            return;
        }

        $root = realpath($this->registry->root());
        $vendorPath = $this->registry->root().DIRECTORY_SEPARATOR.$vendor;
        $realVendor = realpath($vendorPath);
        if (! $root || ! $realVendor || ! str_starts_with($realVendor, $root.DIRECTORY_SEPARATOR) || ! is_dir($realVendor)) {
            return;
        }

        // Never remove the extensions root itself.
        if ($realVendor === $root) {
            return;
        }

        $leftover = array_values(array_filter(
            scandir($realVendor) ?: [],
            fn (string $entry) => $entry !== '.' && $entry !== '..'
        ));
        if ($leftover !== []) {
            return;
        }

        File::deleteDirectory($realVendor);
        FileChangeLog::record('app.delete.vendor', 'extensions/'.$vendor);
    }

    public function backup(string $vendor, string $name, string $version): string
    {
        $src = $this->destPath($vendor, $name);
        if (! is_dir($src)) {
            throw ValidationException::withMessages(['zip' => 'Cannot backup missing extension files.']);
        }
        $stamp = date('YmdHis');
        $dest = storage_path('app/plugin-backups/'.$vendor.'/'.$name.'/'.$version.'-'.$stamp);
        File::ensureDirectoryExists(dirname($dest));
        if (is_dir($dest)) {
            File::deleteDirectory($dest);
        }
        File::copyDirectory($src, $dest);

        return $dest;
    }

    public function restore(string $backupPath, string $dest): void
    {
        if (! is_dir($backupPath)) {
            throw ValidationException::withMessages(['zip' => 'Backup missing; cannot restore.']);
        }
        if (is_dir($dest)) {
            File::deleteDirectory($dest);
        }
        File::ensureDirectoryExists(dirname($dest));
        File::copyDirectory($backupPath, $dest);
    }

    /**
     * @return array{0: PluginManifest, 1: string, 2: callable(): void}
     */
    protected function unpackAndValidate(UploadedFile $zip, bool $forUpdate): array
    {
        if ($zip->getError() !== UPLOAD_ERR_OK) {
            throw ValidationException::withMessages(['zip' => 'The zip could not be uploaded.']);
        }
        $max = (int) config('wento.apps.max_zip_kb', 10240) * 1024;
        if ($zip->getSize() && $zip->getSize() > $max) {
            throw ValidationException::withMessages(['zip' => 'Extension zip is too large.']);
        }

        $archive = new ZipArchive;
        if ($archive->open($zip->getRealPath()) !== true) {
            throw ValidationException::withMessages(['zip' => 'That file is not a readable zip.']);
        }

        $tmp = storage_path('app/plugin-uploads/'.uniqid('app_', true));
        File::ensureDirectoryExists($tmp);

        $cleanup = function () use ($tmp): void {
            if (is_dir($tmp)) {
                File::deleteDirectory($tmp);
            }
        };

        try {
            $this->extractSafe($archive, $tmp);
            $archive->close();
            $root = $this->locateRoot($tmp);
            $manifest = $this->registry->read($root, false);
            if ($manifest->type === 'certified'
                && ! config('wento.apps.allow_certified_unsigned', false)
                && ! config('wento.apps.allow_unsigned', false)
                && ! CertifiedApps::mayInstall($manifest)
            ) {
                throw ValidationException::withMessages([
                    'zip' => 'Certified extensions can only be installed from the marketplace after review, except scanned payment/shipping driver zips or packs signed with this shop APP_KEY.',
                ]);
            }
            if (! $forUpdate && InstalledPlugin::query()
                ->where('vendor', $manifest->vendor)
                ->where('name', $manifest->name)
                ->exists()) {
                throw ValidationException::withMessages([
                    'zip' => 'Extension '.$manifest->id().' is already installed. Upload a newer zip via Install Extension, then click Upgrade.',
                ]);
            }
            $this->structure->assertStandardTree($root, $manifest);
            $this->scanner->assertSafeTree($root, $manifest);

            return [$manifest, $root, $cleanup];
        } catch (Throwable $e) {
            $cleanup();
            throw $e;
        }
    }

    protected function destPath(string $vendor, string $name): string
    {
        return $this->registry->pathFor($vendor, $name);
    }

    protected function setHealth(InstalledPlugin $row, string $status, ?string $error): void
    {
        if (! Schema::hasColumn('plugins', 'health_status')) {
            return;
        }
        $row->forceFill([
            'health_status' => $status,
            'last_error' => $error,
        ])->save();
    }

    protected function extractSafe(ZipArchive $zip, string $tmp): void
    {
        $allowed = ['json', 'php', 'css', 'js', 'mjs', 'txt', 'md', 'svg', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'woff', 'woff2'];
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
            if ($ext === '' || ! in_array($ext, $allowed, true)) {
                throw ValidationException::withMessages(['zip' => 'Extension zips may not include .'.$ext.' files.']);
            }
            $target = $tmp.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $name);
            File::ensureDirectoryExists(dirname($target));
            $parentReal = realpath(dirname($target)) ?: dirname($target);
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
        if (is_file($tmp.DIRECTORY_SEPARATOR.'app.json')) {
            return $tmp;
        }
        $dirs = File::directories($tmp);
        if (count($dirs) === 1 && is_file($dirs[0].DIRECTORY_SEPARATOR.'app.json')) {
            return $dirs[0];
        }

        throw ValidationException::withMessages(['zip' => 'app.json must sit at the zip root or inside a single folder.']);
    }
}
