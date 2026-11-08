<?php

namespace App\Domain\Installer;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * XAMPP ships table_open_cache=64. A store has about 220 tables, so every
 * install query sits in "Opening tables" on Linux and macOS. Raise the cache
 * for this server process, and persist it in a writable XAMPP config.
 */
final class DatabaseServerTune
{
    public const TARGET_OPEN_CACHE = 800;

    public const TARGET_DEFINITION_CACHE = 2000;

    /**
     * @return array{table_open_cache: int, table_definition_cache: int}
     */
    public static function cacheTargets(int $openCache, int $definitionCache, int $openFilesLimit): array
    {
        $openTarget = $openCache;
        $room = $openFilesLimit - 400;
        if ($room > $openCache) {
            $openTarget = max($openCache, min(self::TARGET_OPEN_CACHE, $room));
        }

        return [
            'table_open_cache' => $openTarget,
            'table_definition_cache' => max($definitionCache, self::TARGET_DEFINITION_CACHE),
        ];
    }

    public static function withRaisedCache(string $cnf, int $openCache = self::TARGET_OPEN_CACHE, int $definitionCache = self::TARGET_DEFINITION_CACHE): string
    {
        if (preg_match('/^table_open_cache\s*=\s*(\d+)\s*$/m', $cnf, $match) && (int) $match[1] < $openCache) {
            $cnf = preg_replace('/^table_open_cache\s*=\s*\d+\s*$/m', 'table_open_cache='.$openCache, $cnf, 1) ?? $cnf;
        }

        if (preg_match('/^table_definition_cache\s*=\s*(\d+)\s*$/m', $cnf, $match)) {
            if ((int) $match[1] < $definitionCache) {
                $cnf = preg_replace('/^table_definition_cache\s*=\s*\d+\s*$/m', 'table_definition_cache='.$definitionCache, $cnf, 1) ?? $cnf;
            }
        } else {
            $cnf = preg_replace(
                '/^(table_open_cache\s*=\s*\d+\s*)$/m',
                '$1'."\n".'table_definition_cache='.$definitionCache,
                $cnf,
                1
            ) ?? $cnf;
        }

        return self::withFasterInnodb($cnf);
    }

    /**
     * XAMPP's 16MB InnoDB pool and flush-on-every-commit make a fresh install take half an hour.
     */
    public static function withFasterInnodb(string $cnf): string
    {
        $pool = 'innodb_buffer_pool_size=128M';
        if (preg_match('/^innodb_buffer_pool_size\s*=\s*(\S+)\s*$/m', $cnf, $match)) {
            if (self::bytes($match[1]) < 128 * 1024 * 1024) {
                $cnf = preg_replace('/^innodb_buffer_pool_size\s*=\s*\S+\s*$/m', $pool, $cnf, 1) ?? $cnf;
            }
        } else {
            $cnf = self::insertDirective($cnf, 'table_definition_cache', $pool)
                ?? self::insertDirective($cnf, 'table_open_cache', $pool)
                ?? $cnf;
        }

        $flush = 'innodb_flush_log_at_trx_commit=2';
        if (preg_match('/^innodb_flush_log_at_trx_commit\s*=\s*1\s*$/m', $cnf)) {
            $cnf = preg_replace('/^innodb_flush_log_at_trx_commit\s*=\s*1\s*$/m', $flush, $cnf, 1) ?? $cnf;
        } elseif (! preg_match('/^innodb_flush_log_at_trx_commit\s*=/m', $cnf)) {
            $cnf = self::insertDirective($cnf, 'innodb_buffer_pool_size', $flush) ?? $cnf;
        }

        return $cnf;
    }

    public static function bytes(string $value): int
    {
        $value = trim($value);
        if (! preg_match('/^(\d+)([KMG])?$/i', $value, $match)) {
            return (int) $value;
        }

        $number = (int) $match[1];

        return match (strtoupper($match[2] ?? '')) {
            'K' => $number * 1024,
            'M' => $number * 1048576,
            'G' => $number * 1073741824,
            default => $number,
        };
    }

    protected static function insertDirective(string $cnf, string $afterKey, string $line): ?string
    {
        $updated = preg_replace('/^('.$afterKey.'\s*=\s*\S+\s*)$/m', '$1'."\n".$line, $cnf, 1, $count);

        return ($count ?? 0) > 0 ? $updated : null;
    }

    /**
     * @param  (callable(string): void)|null  $progress
     */
    public static function raise(?callable $progress = null): void
    {
        $raised = self::raiseGlobals();
        $persisted = self::persistXamppConfig();
        if ($raised || $persisted) {
            InstallProgress::say($progress, '  Raised the database cache and memory so install writes are not waiting on disk.');
        }
    }

    protected static function raiseGlobals(): bool
    {
        try {
            $rows = DB::select("SHOW VARIABLES WHERE Variable_name IN ('open_files_limit', 'table_open_cache', 'table_definition_cache', 'innodb_buffer_pool_size', 'innodb_buffer_pool_chunk_size', 'innodb_flush_log_at_trx_commit')");
        } catch (Throwable) {
            return false;
        }

        $current = [];
        foreach ($rows as $row) {
            $name = strtolower((string) ($row->Variable_name ?? ''));
            $current[$name] = (int) ($row->Value ?? 0);
        }

        $targets = self::cacheTargets(
            $current['table_open_cache'] ?? 0,
            $current['table_definition_cache'] ?? 0,
            $current['open_files_limit'] ?? 1024,
        );

        $changed = false;
        foreach ($targets as $variable => $target) {
            if ($target <= ($current[$variable] ?? 0)) {
                continue;
            }
            try {
                DB::statement('SET GLOBAL '.$variable.'='.$target);
                $changed = true;
            } catch (Throwable) {
                // Shared hosts often cannot change global variables.
            }
        }

        $pool = $current['innodb_buffer_pool_size'] ?? 0;
        $chunk = max(1048576, $current['innodb_buffer_pool_chunk_size'] ?? (128 * 1024 * 1024));
        $targetPool = max(128 * 1024 * 1024, $chunk);
        $targetPool = (int) (ceil($targetPool / $chunk) * $chunk);
        if ($pool > 0 && $pool < $targetPool) {
            try {
                DB::statement('SET GLOBAL innodb_buffer_pool_size='.$targetPool);
                $changed = true;
            } catch (Throwable) {
            }
        }

        if (($current['innodb_flush_log_at_trx_commit'] ?? 1) === 1) {
            try {
                DB::statement('SET GLOBAL innodb_flush_log_at_trx_commit=2');
                $changed = true;
            } catch (Throwable) {
            }
        }

        return $changed;
    }

    protected static function persistXamppConfig(): bool
    {
        $changed = false;
        foreach (self::xamppConfigPaths() as $path) {
            if (! is_file($path) || ! is_readable($path) || ! is_writable($path)) {
                continue;
            }
            $original = (string) file_get_contents($path);
            $updated = self::withRaisedCache($original);
            if ($updated === $original) {
                continue;
            }
            if (file_put_contents($path, $updated) === false) {
                continue;
            }
            $changed = true;
        }

        return $changed;
    }

    /**
     * @return list<string>
     */
    public static function xamppConfigPaths(): array
    {
        $paths = [
            '/opt/lampp/etc/my.cnf',
            '/Applications/XAMPP/xamppfiles/etc/my.cnf',
        ];
        if (stripos(PHP_OS, 'WIN') === 0) {
            $paths[] = 'C:\\xampp\\mysql\\bin\\my.ini';
            $paths[] = 'C:\\xampp\\mysql\\my.ini';
        }

        return $paths;
    }
}
