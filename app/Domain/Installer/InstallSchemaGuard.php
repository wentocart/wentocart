<?php

namespace App\Domain\Installer;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * After dump import, ALTER demo columns onto the physical prefixed tables.
 * Do not skip based on Schema::hasColumn — that helper can disagree with Eloquent's UPDATE.
 */
class InstallSchemaGuard
{
    /**
     * @return array<string, list<string>>
     */
    public static function requiredStringColumns(): array
    {
        return [
            'categories' => ['image_path', 'thumbnail_path', 'banner_path', 'cover_path', 'icon_path', 'og_image_path'],
            'brands' => ['logo_path', 'banner_path', 'og_image_path'],
            'products' => ['cover_path'],
            'product_variants' => ['cover_path'],
            'sellers' => ['logo_path', 'banner_path'],
            'admins' => ['jump_shortcut'],
        ];
    }

    public static function ensure(?string $prefix = null): void
    {
        self::patch($prefix);
    }

    /**
     * Always try ADD COLUMN on every prefix candidate. Duplicate / missing table is ignored.
     */
    public static function patch(?string $prefix = null): void
    {
        foreach (self::requiredStringColumns() as $table => $columns) {
            foreach ($columns as $column) {
                self::addNullableStringColumn($table, $column, $prefix);
            }
        }
    }

    public static function tableExists(string $table, ?string $prefix = null): bool
    {
        if (! self::usesMysql()) {
            return Schema::hasTable($table);
        }

        foreach (self::prefixCandidates($prefix) as $candidate) {
            try {
                DB::select('SHOW COLUMNS FROM `'.self::physical($table, $candidate).'`');

                return true;
            } catch (Throwable) {
                continue;
            }
        }

        return false;
    }

    public static function hasColumn(string $table, string $column, ?string $prefix = null): bool
    {
        if (! self::usesMysql()) {
            return Schema::hasTable($table) && Schema::hasColumn($table, $column);
        }

        foreach (self::prefixCandidates($prefix) as $candidate) {
            try {
                foreach (DB::select('SHOW COLUMNS FROM `'.self::physical($table, $candidate).'`') as $row) {
                    if (strcasecmp(self::columnField($row), $column) === 0) {
                        return true;
                    }
                }
            } catch (Throwable) {
                continue;
            }
        }

        return false;
    }

    public static function addNullableStringColumn(string $table, string $column, ?string $prefix = null): void
    {
        $column = self::safeIdent($column);
        $table = self::safeIdent($table);
        if ($column === '' || $table === '') {
            return;
        }

        if (! self::usesMysql()) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, $column)) {
                return;
            }
            Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                $blueprint->string($column)->nullable();
            });

            return;
        }

        foreach (self::prefixCandidates($prefix) as $candidate) {
            try {
                DB::statement('ALTER TABLE `'.self::physical($table, $candidate).'` ADD COLUMN `'.$column.'` VARCHAR(255) NULL');
            } catch (Throwable $e) {
                $msg = $e->getMessage();
                if (str_contains($msg, 'Duplicate column')
                    || str_contains($msg, '42S21')
                    || str_contains($msg, "doesn't exist")
                    || str_contains($msg, 'Unknown table')
                    || str_contains($msg, '42S02')) {
                    continue;
                }
                throw $e;
            }
        }
    }

    /**
     * ALTER then write only the given attributes (not Eloquent fillable extras).
     *
     * @param  array<string, mixed>  $values
     */
    public static function write(Model $model, array $values): void
    {
        if ($values === []) {
            return;
        }

        foreach (array_keys($values) as $column) {
            if (is_string($column)) {
                self::addNullableStringColumn($model->getTable(), $column);
            }
        }

        $payload = $values;
        if ($model->usesTimestamps()) {
            $payload['updated_at'] = $payload['updated_at'] ?? now();
        }

        try {
            $model->newQuery()->whereKey($model->getKey())->update($payload);
        } catch (QueryException $e) {
            if (! str_contains($e->getMessage(), 'Unknown column') && ! str_contains($e->getMessage(), '42S22')) {
                throw $e;
            }
            if (! preg_match("/Unknown column '([^']+)'/", $e->getMessage(), $matches)) {
                throw $e;
            }
            unset($payload[$matches[1]], $values[$matches[1]]);
            self::write($model, $values);

            return;
        }

        $model->forceFill($values)->syncOriginal();
    }

    /**
     * @return list<string>
     */
    protected static function prefixCandidates(?string $prefix): array
    {
        $raw = [
            $prefix,
            DB::getTablePrefix(),
            function_exists('wento_db_prefix') ? wento_db_prefix() : '',
        ];
        $out = [];
        foreach ($raw as $item) {
            $item = self::safeIdent((string) $item);
            if ($item !== '' && ! str_ends_with($item, '_')) {
                $item .= '_';
            }
            if ($item !== '' && ! in_array($item, $out, true)) {
                $out[] = $item;
            }
        }
        $out[] = '';

        return $out;
    }

    protected static function usesMysql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    protected static function physical(string $table, string $prefix = ''): string
    {
        return self::safeIdent($prefix.$table);
    }

    protected static function safeIdent(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9_]/', '', $name) ?? '';
    }

    protected static function columnField(object|array $row): string
    {
        $arr = is_array($row) ? $row : get_object_vars($row);
        foreach ($arr as $key => $value) {
            if (strcasecmp((string) $key, 'Field') === 0 || strcasecmp((string) $key, 'name') === 0) {
                return (string) $value;
            }
        }

        $first = reset($arr);

        return is_string($first) || is_numeric($first) ? (string) $first : '';
    }
}
