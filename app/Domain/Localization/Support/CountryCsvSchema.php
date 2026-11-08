<?php

namespace App\Domain\Localization\Support;

use App\Domain\Localization\Models\Country;
use App\Domain\Settings\AdminCsv;

final class CountryCsvSchema
{
    /** @return list<string> */
    public static function columns(): array
    {
        return ['code', 'iso3', 'name', 'calling_code', 'default_currency_code', 'is_enabled', 'is_default', 'position'];
    }

    /** @return list<string> */
    public static function required(): array
    {
        return ['code', 'name'];
    }

    /** @return list<array<string, mixed>> */
    public static function samples(): array
    {
        return [
            [
                'code' => 'PT',
                'iso3' => 'PRT',
                'name' => 'Portugal',
                'calling_code' => '351',
                'default_currency_code' => 'EUR',
                'is_enabled' => '1',
                'is_default' => '0',
                'position' => '50',
            ],
        ];
    }

    public static function sampleCsv(string $delimiter = AdminCsv::DELIMITER): string
    {
        return AdminCsv::stringify(self::columns(), self::samples(), $delimiter);
    }

    public static function exportCsv(string $delimiter = AdminCsv::DELIMITER): string
    {
        $rows = Country::query()->orderByDesc('is_default')->orderBy('position')->orderBy('name')->limit(10000)->get()->map(fn (Country $row) => [
            'code' => $row->code,
            'iso3' => $row->iso3,
            'name' => $row->name,
            'calling_code' => $row->calling_code,
            'default_currency_code' => $row->default_currency_code,
            'is_enabled' => $row->is_enabled ? '1' : '0',
            'is_default' => $row->is_default ? '1' : '0',
            'position' => $row->position,
        ])->all();

        return AdminCsv::stringify(self::columns(), $rows, $delimiter);
    }

    public static function import(string $contents, ?string $delimiter = null): int
    {
        $parsed = AdminCsv::parse($contents, self::columns(), 'Country', $delimiter, self::required());
        $count = 0;
        $position = (int) (Country::query()->max('position') ?? 0);

        foreach ($parsed['rows'] as $row) {
            $code = strtoupper(AdminCsv::get($row, 'code'));
            $name = AdminCsv::get($row, 'name');
            if ($code === '' || $name === '') {
                continue;
            }
            $payload = [
                'iso3' => strtoupper(AdminCsv::get($row, 'iso3') ?: $code),
                'name' => $name,
                'calling_code' => AdminCsv::nullable(AdminCsv::get($row, 'calling_code')),
                'default_currency_code' => strtoupper(AdminCsv::get($row, 'default_currency_code')) ?: null,
                'is_enabled' => AdminCsv::bool(AdminCsv::get($row, 'is_enabled'), true),
                'is_default' => AdminCsv::bool(AdminCsv::get($row, 'is_default'), false),
                'position' => AdminCsv::int(AdminCsv::get($row, 'position'), $position + 1) ?? ($position + 1),
            ];
            $country = Country::query()->updateOrCreate(['code' => $code], $payload);
            if ($country->is_default) {
                Country::query()->where('id', '!=', $country->id)->update(['is_default' => false]);
            }
            $count++;
        }

        return $count;
    }
}
