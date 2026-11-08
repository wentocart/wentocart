<?php

namespace App\Domain\Plugin;

use Illuminate\Support\Facades\Schema;

/**
 * Tables an extension owns. Names are fixed so uninstall can drop only those tables.
 * Declare suffixes in app.json "tables". The physical name is x_{vendor}_{name}_{suffix}
 * (hyphens become underscores). Laravel's table prefix is applied by the schema builder.
 */
final class ExtensionTables
{
    /**
     * @return list<string>
     */
    public static function suffixesFromPath(string $path): array
    {
        $file = rtrim($path, '/\\').DIRECTORY_SEPARATOR.'app.json';
        if (! is_file($file)) {
            return [];
        }
        $raw = json_decode((string) file_get_contents($file), true);

        return self::suffixes(is_array($raw) ? ($raw['tables'] ?? []) : []);
    }

    /**
     * @param  mixed  $tables
     * @return list<string>
     */
    public static function suffixes(mixed $tables): array
    {
        $out = [];
        foreach (is_array($tables) ? $tables : [] as $table) {
            $suffix = strtolower((string) preg_replace('/[^a-z0-9_]/', '', (string) $table));
            if ($suffix === '' || strlen($suffix) > 40 || in_array($suffix, $out, true)) {
                continue;
            }
            $out[] = $suffix;
        }

        return $out;
    }

    public static function physicalName(string $vendor, string $name, string $suffix): string
    {
        $vendor = str_replace('-', '_', strtolower($vendor));
        $name = str_replace('-', '_', strtolower($name));
        $suffix = strtolower((string) preg_replace('/[^a-z0-9_]/', '', $suffix));
        if ($vendor === '' || $name === '' || $suffix === '') {
            return '';
        }

        return 'x_'.$vendor.'_'.$name.'_'.$suffix;
    }

    /**
     * @param  list<string>  $suffixes
     */
    public static function drop(string $vendor, string $name, array $suffixes): void
    {
        foreach (self::suffixes($suffixes) as $suffix) {
            $table = self::physicalName($vendor, $name, $suffix);
            if ($table !== '' && Schema::hasTable($table)) {
                Schema::drop($table);
            }
        }
    }
}
