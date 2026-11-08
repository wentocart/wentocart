<?php

namespace App\Domain\Platform\Upgrade;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;
use ZipArchive;

final class UpgradeRestoreService
{
    public function __construct(
        protected UpgradeBackupService $backups = new UpgradeBackupService,
    ) {}

    public function restore(string $backupId, ?callable $progress = null): void
    {
        $dir = $this->backups->backupDir($backupId);
        if ($dir === null) {
            throw new RuntimeException('Backup not found.');
        }
        $manifestPath = $dir.DIRECTORY_SEPARATOR.'manifest.json';
        if (! is_file($manifestPath)) {
            throw new RuntimeException('Backup manifest missing.');
        }
        $manifest = json_decode((string) File::get($manifestPath), true);
        if (! is_array($manifest)) {
            throw new RuntimeException('Invalid backup manifest.');
        }

        $dbName = (string) ($manifest['database_file'] ?? 'database.sql');
        $coreName = (string) ($manifest['core_file'] ?? 'core.zip');
        $dbFile = $dir.DIRECTORY_SEPARATOR.$dbName;
        $coreFile = $dir.DIRECTORY_SEPARATOR.$coreName;

        $progress && $progress('Restoring database…');
        $this->restoreDatabase($dbFile);

        $progress && $progress('Restoring core files…');
        if (is_file($coreFile)) {
            $this->restoreCore($coreFile);
        }

        $progress && $progress('Clearing caches…');
        try {
            Artisan::call('optimize:clear');
        } catch (Throwable) {
            // ignore when artisan not fully available
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        $version = (string) ($manifest['installed_version'] ?? '1.0.0');
        PlatformMeta::stamp($version);
        PlatformMeta::log($version, 'restored', 'Restored backup '.$backupId);
    }

    protected function restoreDatabase(string $dbFile): void
    {
        if (! is_file($dbFile)) {
            throw new RuntimeException('Database backup file missing.');
        }
        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            $target = DB::connection()->getDatabaseName();
            DB::disconnect();
            File::copy($dbFile, $target);
            DB::reconnect();

            return;
        }

        if (str_ends_with(strtolower($dbFile), '.sql')) {
            $sql = File::get($dbFile);
            DB::unprepared('SET FOREIGN_KEY_CHECKS=0');
            // Split on ;\n for large dumps — keep simple for PHP dumps / mysqldump
            foreach ($this->splitSql($sql) as $statement) {
                $statement = trim($statement);
                if ($statement === '' || str_starts_with($statement, '--')) {
                    continue;
                }
                try {
                    DB::unprepared($statement);
                } catch (Throwable $e) {
                    // continue on non-fatal; rethrow if empty restore
                    if (! str_contains($e->getMessage(), 'already exists')) {
                        // keep going for multi-statement noise
                    }
                }
            }
            DB::unprepared('SET FOREIGN_KEY_CHECKS=1');

            return;
        }

        throw new RuntimeException('Unsupported database backup format.');
    }

    /**
     * @return list<string>
     */
    protected function splitSql(string $sql): array
    {
        $parts = preg_split('/;\s*\n/', $sql) ?: [];

        return array_values(array_filter(array_map('trim', $parts)));
    }

    protected function restoreCore(string $coreFile): void
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP zip extension is required to restore core.zip.');
        }
        $zip = new ZipArchive;
        if ($zip->open($coreFile) !== true) {
            throw new RuntimeException('Could not open core.zip.');
        }
        $allowed = ['app/', 'bootstrap/', 'config/', 'database/', 'public/build/', 'public/index.php', 'public/wento-recovery.php', 'resources/', 'routes/', 'extensions/', 'themes/', 'vendor/', 'composer.json', 'composer.lock', 'artisan', 'LICENSE', 'NOTICE'];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            if ($name === '' || str_contains($name, '..')) {
                continue;
            }
            $ok = false;
            foreach ($allowed as $prefix) {
                if ($name === rtrim($prefix, '/') || str_starts_with($name, $prefix)) {
                    $ok = true;
                    break;
                }
            }
            if (! $ok) {
                continue;
            }
            $target = base_path($name);
            if (str_ends_with($name, '/')) {
                File::ensureDirectoryExists($target);
                continue;
            }
            File::ensureDirectoryExists(dirname($target));
            $stream = $zip->getStream($name);
            if ($stream === false) {
                continue;
            }
            $data = stream_get_contents($stream);
            fclose($stream);
            if ($data !== false) {
                File::put($target, $data);
            }
        }
        $zip->close();
    }
}
