<?php

namespace App\Domain\Shipping\Support;

use App\Domain\Localization\Models\Country;
use App\Domain\Settings\AdminCsv;
use App\Domain\Shipping\Models\ShippingZone;

final class ShippingZoneCsvSchema
{
    /** @return list<string> */
    public static function columns(): array
    {
        return ['name', 'country_codes', 'is_enabled', 'position'];
    }

    /** @return list<string> */
    public static function required(): array
    {
        return ['name'];
    }

    /** @return list<array<string, mixed>> */
    public static function samples(): array
    {
        return [
            [
                'name' => 'CSV EU',
                'country_codes' => 'DE,FR,US',
                'is_enabled' => '1',
                'position' => '10',
            ],
            [
                'name' => 'CSV Domestic',
                'country_codes' => 'US',
                'is_enabled' => '1',
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
        $rows = ShippingZone::query()
            ->with('countries:id,code,zone_id')
            ->orderBy('position')
            ->orderBy('name')
            ->get()
            ->map(fn (ShippingZone $zone) => [
                'name' => $zone->name,
                'country_codes' => $zone->countries->pluck('code')->implode(','),
                'is_enabled' => $zone->is_enabled ? '1' : '0',
                'position' => $zone->position,
            ])
            ->all();

        return AdminCsv::stringify(self::columns(), $rows, $delimiter);
    }

    public static function import(string $contents, ?string $delimiter = null): int
    {
        $parsed = AdminCsv::parse($contents, self::columns(), 'Shipping zone', $delimiter, self::required());
        $count = 0;
        $position = (int) (ShippingZone::query()->max('position') ?? 0);

        foreach ($parsed['rows'] as $row) {
            $name = AdminCsv::get($row, 'name');
            if ($name === '') {
                continue;
            }
            $codes = collect(preg_split('/[|,]+/', AdminCsv::get($row, 'country_codes')) ?: [])
                ->map(fn ($code) => strtoupper(trim((string) $code)))
                ->filter()
                ->unique()
                ->all();
            $countryIds = $codes === []
                ? []
                : Country::query()->whereIn('code', $codes)->pluck('id')->all();
            $payload = [
                'is_enabled' => AdminCsv::bool(AdminCsv::get($row, 'is_enabled'), true),
                'position' => AdminCsv::int(AdminCsv::get($row, 'position'), $position + 10) ?? ($position + 10),
            ];
            $zone = ShippingZone::query()->where('name', $name)->first();
            if ($zone) {
                $zone->update($payload);
            } else {
                $position = (int) $payload['position'];
                $zone = ShippingZone::query()->create(array_merge($payload, ['name' => $name]));
            }
            $zone->assignCountries($countryIds);
            $count++;
        }

        return $count;
    }
}
