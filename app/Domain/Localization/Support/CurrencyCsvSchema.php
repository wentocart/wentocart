<?php

namespace App\Domain\Localization\Support;

use App\Domain\Localization\Models\Currency;
use App\Domain\Settings\AdminCsv;

final class CurrencyCsvSchema
{
    /** @return list<string> */
    public static function columns(): array
    {
        return ['code', 'name', 'symbol', 'decimal_places', 'symbol_position', 'exchange_rate', 'is_enabled', 'is_default', 'position'];
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
                'code' => 'CAD',
                'name' => 'Canadian Dollar',
                'symbol' => '$',
                'decimal_places' => '2',
                'symbol_position' => 'before',
                'exchange_rate' => '1.35',
                'is_enabled' => '1',
                'is_default' => '0',
                'position' => '20',
            ],
        ];
    }

    public static function sampleCsv(string $delimiter = AdminCsv::DELIMITER): string
    {
        return AdminCsv::stringify(self::columns(), self::samples(), $delimiter);
    }

    public static function exportCsv(string $delimiter = AdminCsv::DELIMITER): string
    {
        $rows = Currency::query()->orderByDesc('is_default')->orderBy('position')->get()->map(fn (Currency $row) => [
            'code' => $row->code,
            'name' => $row->name,
            'symbol' => $row->symbol,
            'decimal_places' => $row->decimal_places,
            'symbol_position' => $row->symbol_position,
            'exchange_rate' => $row->exchange_rate,
            'is_enabled' => $row->is_enabled ? '1' : '0',
            'is_default' => $row->is_default ? '1' : '0',
            'position' => $row->position,
        ])->all();

        return AdminCsv::stringify(self::columns(), $rows, $delimiter);
    }

    public static function import(string $contents, ?string $delimiter = null): int
    {
        $parsed = AdminCsv::parse($contents, self::columns(), 'Currency', $delimiter, self::required());
        $count = 0;
        $position = (int) (Currency::query()->max('position') ?? 0);

        foreach ($parsed['rows'] as $row) {
            $code = strtoupper(AdminCsv::get($row, 'code'));
            $name = AdminCsv::get($row, 'name');
            if ($code === '' || $name === '') {
                continue;
            }
            $payload = [
                'name' => $name,
                'symbol' => AdminCsv::get($row, 'symbol') ?: $code,
                'decimal_places' => AdminCsv::int(AdminCsv::get($row, 'decimal_places'), 2) ?? 2,
                'symbol_position' => in_array(AdminCsv::get($row, 'symbol_position'), ['before', 'after'], true)
                    ? AdminCsv::get($row, 'symbol_position')
                    : 'before',
                'exchange_rate' => AdminCsv::float(AdminCsv::get($row, 'exchange_rate'), 1) ?? 1,
                'is_enabled' => AdminCsv::bool(AdminCsv::get($row, 'is_enabled'), true),
                'is_default' => AdminCsv::bool(AdminCsv::get($row, 'is_default'), false),
                'position' => AdminCsv::int(AdminCsv::get($row, 'position'), $position + 1) ?? ($position + 1),
            ];
            $currency = Currency::query()->updateOrCreate(['code' => $code], $payload);
            if ($currency->is_default) {
                Currency::query()->where('id', '!=', $currency->id)->update(['is_default' => false]);
            }
            $count++;
        }

        \App\Domain\Localization\Money::forgetCache();

        return $count;
    }
}
