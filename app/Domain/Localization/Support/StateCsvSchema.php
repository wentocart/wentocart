<?php

namespace App\Domain\Localization\Support;

use App\Domain\Catalog\Support\CatalogCsvImport;
use App\Domain\Localization\Models\Country;
use App\Domain\Localization\Models\CountryRegion;
use App\Domain\Settings\AdminCsv;

final class StateCsvSchema
{
    /** @return list<string> */
    public static function columns(): array
    {
        return ['country_code', 'code', 'name', 'is_enabled', 'position'];
    }

    /** @return list<string> */
    public static function required(): array
    {
        return ['country_code', 'code', 'name'];
    }

    /** @return list<array<string, mixed>> */
    public static function samples(): array
    {
        return [
            [
                'country_code' => 'US',
                'code' => 'WA',
                'name' => 'Washington',
                'is_enabled' => '1',
                'position' => '10',
            ],
        ];
    }

    public static function sampleCsv(string $delimiter = AdminCsv::DELIMITER): string
    {
        return AdminCsv::stringify(self::columns(), self::samples(), $delimiter);
    }

    public static function exportCsv(string $delimiter = AdminCsv::DELIMITER): string
    {
        $rows = CountryRegion::query()
            ->with('country:id,code')
            ->orderBy('country_id')
            ->orderBy('position')
            ->limit(20000)
            ->get()
            ->map(fn (CountryRegion $row) => [
                'country_code' => $row->country?->code,
                'code' => $row->code,
                'name' => $row->name,
                'is_enabled' => $row->is_enabled ? '1' : '0',
                'position' => $row->position,
            ])
            ->all();

        return AdminCsv::stringify(self::columns(), $rows, $delimiter);
    }

    public static function import(string $contents, ?string $delimiter = null): int
    {
        $parsed = AdminCsv::parse($contents, self::columns(), 'State', $delimiter, self::required());
        $errors = [];
        $count = 0;

        foreach ($parsed['rows'] as $index => $row) {
            $countryCode = strtoupper(AdminCsv::get($row, 'country_code'));
            $code = AdminCsv::get($row, 'code');
            $name = AdminCsv::get($row, 'name');
            $country = Country::query()->where('code', $countryCode)->first();
            $line = CatalogCsvImport::rowNumber($index);
            if (! $country) {
                $errors[] = "Row {$line}: unknown country code \"{$countryCode}\".";
                continue;
            }
            if ($code === '' || $name === '') {
                continue;
            }
            CountryRegion::query()->updateOrCreate(
                ['country_id' => $country->id, 'code' => $code],
                [
                    'name' => $name,
                    'is_enabled' => AdminCsv::bool(AdminCsv::get($row, 'is_enabled'), true),
                    'position' => AdminCsv::int(AdminCsv::get($row, 'position'), 0) ?? 0,
                ]
            );
            $count++;
        }

        CatalogCsvImport::throwRowErrors($errors);

        return $count;
    }
}
