<?php

namespace App\Domain\Localization;

use Illuminate\Support\Facades\File;

/**
 * Full ISO 3166-1 country list for pack install dropdowns.
 * Source file: database/localization/world-countries.json
 */
final class WorldCountries
{
    /** @var list<array<string, mixed>>|null */
    private static ?array $all = null;

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $byCode = null;

    /**
     * @return list<array{code: string, iso3: ?string, name: string, calling_code: ?string, default_currency_code: ?string, continent: ?string}>
     */
    public static function all(): array
    {
        self::boot();

        return self::$all ?? [];
    }

    public static function find(string $code): ?array
    {
        self::boot();
        $code = strtoupper($code);

        return self::$byCode[$code] ?? null;
    }

    public static function count(): int
    {
        return count(self::all());
    }

    private static function boot(): void
    {
        if (self::$all !== null) {
            return;
        }

        $path = database_path('localization/world-countries.json');
        if (! File::exists($path)) {
            self::$all = [];
            self::$byCode = [];

            return;
        }

        $data = json_decode(File::get($path), true) ?: [];
        $rows = [];
        foreach ($data['countries'] ?? [] as $row) {
            if (empty($row['code']) || empty($row['name'])) {
                continue;
            }
            $code = strtoupper((string) $row['code']);
            $entry = [
                'code' => $code,
                'iso3' => isset($row['iso3']) ? strtoupper((string) $row['iso3']) : null,
                'name' => (string) $row['name'],
                'calling_code' => $row['calling_code'] ?? null,
                'default_currency_code' => isset($row['default_currency_code'])
                    ? strtoupper((string) $row['default_currency_code'])
                    : null,
                'continent' => $row['continent'] ?? null,
                'has_regions' => false,
                'position' => 0,
            ];
            $rows[] = $entry;
        }

        self::$all = $rows;
        self::$byCode = [];
        foreach ($rows as $row) {
            self::$byCode[$row['code']] = $row;
        }
    }
}
