<?php

namespace App\Domain\Catalog\Support;

use App\Domain\Catalog\Models\Warehouse;
use App\Domain\Catalog\Models\WarehouseTranslation;
use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Localization\Models\Language;
use App\Domain\Settings\AdminCsv;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WarehouseCsvSchema
{
    /** @return list<string> */
    public static function columns(): array
    {
        return [
            'id',
            'name',
            'code',
            'is_enabled',
            'is_default',
            'email',
            'phone',
            'fax',
            'website',
            'address',
            'address_line2',
            'city',
            'state',
            'postcode',
            'country',
            'latitude',
            'longitude',
        ];
    }

    /** @return list<string> */
    public static function required(): array
    {
        return ['name', 'code', 'email', 'address', 'city', 'postcode', 'country'];
    }

    /** @return list<array<string, mixed>> */
    public static function samples(): array
    {
        return [
            [
                'name' => 'East depot',
                'code' => 'EAST-DEPOT',
                'is_enabled' => '1',
                'is_default' => '0',
                'email' => 'east@example.com',
                'phone' => '+1 555 0101',
                'fax' => '+1 555 0109',
                'website' => 'https://east.example.com',
                'address' => '100 Harbor Way',
                'address_line2' => 'Dock 4',
                'city' => 'Boston',
                'state' => 'MA',
                'postcode' => '02110',
                'country' => 'US',
                'latitude' => '42.3601',
                'longitude' => '-71.0589',
            ],
            [
                'name' => 'West hub',
                'code' => 'WEST-HUB',
                'is_enabled' => '1',
                'is_default' => '0',
                'email' => 'west@example.com',
                'phone' => '+1 555 0102',
                'fax' => '',
                'website' => '',
                'address' => '500 Market Street',
                'address_line2' => '',
                'city' => 'San Francisco',
                'state' => 'CA',
                'postcode' => '94105',
                'country' => 'US',
                'latitude' => '37.7749',
                'longitude' => '-122.4194',
            ],
            [
                'name' => 'Central store',
                'code' => 'CENTRAL',
                'is_enabled' => '1',
                'is_default' => '0',
                'email' => 'central@example.com',
                'phone' => '+1 555 0103',
                'fax' => '',
                'website' => '',
                'address' => '12 Industrial Park',
                'address_line2' => 'Building C',
                'city' => 'Chicago',
                'state' => 'IL',
                'postcode' => '60601',
                'country' => 'US',
                'latitude' => '',
                'longitude' => '',
            ],
            [
                'name' => 'North overflow',
                'code' => 'NORTH-OVF',
                'is_enabled' => '0',
                'is_default' => '0',
                'email' => 'north@example.com',
                'phone' => '',
                'fax' => '',
                'website' => '',
                'address' => '88 Cold Storage Rd',
                'address_line2' => '',
                'city' => 'Minneapolis',
                'state' => 'MN',
                'postcode' => '55401',
                'country' => 'US',
                'latitude' => '',
                'longitude' => '',
            ],
            [
                'name' => 'South returns',
                'code' => 'SOUTH-RET',
                'is_enabled' => '1',
                'is_default' => '0',
                'email' => 'returns@example.com',
                'phone' => '+1 555 0105',
                'fax' => '',
                'website' => '',
                'address' => '9 Returns Lane',
                'address_line2' => 'Suite 2',
                'city' => 'Atlanta',
                'state' => 'GA',
                'postcode' => '30301',
                'country' => 'US',
                'latitude' => '',
                'longitude' => '',
            ],
        ];
    }

    public static function sampleCsv(string $delimiter = AdminCsv::DELIMITER): string
    {
        return AdminCsv::stringify(self::columns(), self::samples(), $delimiter);
    }

    public static function exportCsv(string $delimiter = AdminCsv::DELIMITER): string
    {
        $locale = Language::defaultCode();
        $rows = Warehouse::query()
            ->with('translations')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (Warehouse $row) => [
                'id' => $row->id,
                'name' => $row->displayName($locale),
                'code' => $row->code,
                'is_enabled' => $row->is_enabled ? '1' : '0',
                'is_default' => $row->is_default ? '1' : '0',
                'email' => $row->email,
                'phone' => $row->phone,
                'fax' => $row->fax,
                'website' => $row->website,
                'address' => $row->address,
                'address_line2' => $row->address_line2,
                'city' => $row->city,
                'state' => $row->state,
                'postcode' => $row->postcode,
                'country' => $row->country,
                'latitude' => $row->latitude,
                'longitude' => $row->longitude,
            ])->all();

        return AdminCsv::stringify(self::columns(), $rows, $delimiter);
    }

    public static function import(string $contents, ?string $delimiter = null): int
    {
        $parsed = AdminCsv::parse($contents, self::columns(), 'Warehouse', $delimiter, self::required());
        $count = 0;
        $locale = Language::defaultCode();

        return (int) DB::transaction(function () use ($parsed, &$count, $locale) {
            $seenCodes = [];

            foreach ($parsed['rows'] as $index => $row) {
                $rowNumber = $index + 2;
                $name = trim((string) AdminCsv::get($row, 'name'));
                if ($name === '') {
                    continue;
                }
                if (strlen($name) > 120) {
                    throw ValidationException::withMessages([
                        'csv' => "Row {$rowNumber}: Warehouse name must be at most 120 characters.",
                    ]);
                }

                $rawCode = trim((string) AdminCsv::get($row, 'code'));
                $code = strtoupper($rawCode !== '' ? $rawCode : Str::slug($name));
                $code = substr(preg_replace('/[^A-Z0-9\-_]/', '', $code) ?: 'WH', 0, 32);
                if ($code === '') {
                    throw ValidationException::withMessages([
                        'csv' => "Row {$rowNumber}: Warehouse code is required.",
                    ]);
                }
                if (isset($seenCodes[$code])) {
                    throw ValidationException::withMessages([
                        'csv' => "Row {$rowNumber}: Duplicate warehouse code {$code} in this file.",
                    ]);
                }
                $seenCodes[$code] = true;

                $isDefault = AdminCsv::bool(AdminCsv::get($row, 'is_default'), false);
                $isEnabled = AdminCsv::bool(AdminCsv::get($row, 'is_enabled'), true);
                if ($isDefault) {
                    $isEnabled = true;
                }

                $nullable = static fn (mixed $value): ?string => (($value = trim((string) $value)) === '' ? null : $value);
                $nullableFloat = static function (mixed $value) use ($nullable): ?float {
                    $value = $nullable($value);

                    return $value === null ? null : (float) $value;
                };

                $payload = [
                    'name' => $name,
                    'code' => $code,
                    'is_enabled' => $isEnabled,
                    'is_default' => $isDefault,
                    'email' => $nullable(AdminCsv::get($row, 'email')),
                    'phone' => $nullable(AdminCsv::get($row, 'phone')),
                    'fax' => $nullable(AdminCsv::get($row, 'fax')),
                    'website' => $nullable(AdminCsv::get($row, 'website')),
                    'address' => $nullable(AdminCsv::get($row, 'address')),
                    'address_line2' => $nullable(AdminCsv::get($row, 'address_line2')),
                    'city' => $nullable(AdminCsv::get($row, 'city')),
                    'state' => $nullable(AdminCsv::get($row, 'state')),
                    'postcode' => $nullable(AdminCsv::get($row, 'postcode')),
                    'country' => $nullable(AdminCsv::get($row, 'country')),
                    'latitude' => $nullableFloat(AdminCsv::get($row, 'latitude')),
                    'longitude' => $nullableFloat(AdminCsv::get($row, 'longitude')),
                ];

                $id = AdminCsv::int(AdminCsv::get($row, 'id'), null);
                $warehouse = null;
                if ($id) {
                    $warehouse = Warehouse::query()->whereKey($id)->first();
                }
                if (! $warehouse) {
                    $warehouse = Warehouse::query()->whereRaw('UPPER(code) = ?', [$code])->first();
                }

                if ($warehouse) {
                    $conflict = Warehouse::query()
                        ->whereRaw('UPPER(code) = ?', [$code])
                        ->where('id', '!=', $warehouse->id)
                        ->exists();
                    if ($conflict) {
                        throw ValidationException::withMessages([
                            'csv' => "Row {$rowNumber}: Warehouse code {$code} is already used.",
                        ]);
                    }
                    $warehouse->update($payload);
                } else {
                    $conflict = Warehouse::query()->whereRaw('UPPER(code) = ?', [$code])->exists();
                    if ($conflict) {
                        throw ValidationException::withMessages([
                            'csv' => "Row {$rowNumber}: Warehouse code {$code} is already used.",
                        ]);
                    }
                    $warehouse = Warehouse::query()->create($payload);
                }

                WarehouseTranslation::query()->updateOrCreate(
                    [
                        'warehouse_id' => $warehouse->id,
                        'language_code' => $locale,
                    ],
                    ['name' => $name],
                );

                if ($warehouse->is_default) {
                    Warehouse::query()->where('id', '!=', $warehouse->id)->update(['is_default' => false]);
                }

                $count++;
            }

            app(InventoryService::class)->ensureExactlyOneDefault();

            return $count;
        });
    }
}
