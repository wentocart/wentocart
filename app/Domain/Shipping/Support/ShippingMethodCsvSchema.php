<?php

namespace App\Domain\Shipping\Support;

use App\Domain\Settings\AdminCsv;
use App\Domain\Settings\PricingSettings;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Domain\Tax\Models\TaxClass;
use Illuminate\Support\Str;

final class ShippingMethodCsvSchema
{
    /** @return list<string> */
    public static function columns(): array
    {
        return ['code', 'name', 'type', 'price', 'tax_class', 'description', 'is_enabled', 'position'];
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
                'code' => 'csv-flat',
                'name' => 'CSV flat rate',
                'type' => 'flat',
                'price' => '9.99',
                'tax_class' => 'Standard',
                'description' => 'Sample flat rate created from CSV.',
                'is_enabled' => '1',
                'position' => '10',
            ],
            [
                'code' => 'csv-free',
                'name' => 'CSV free shipping',
                'type' => 'free',
                'price' => '0',
                'tax_class' => '',
                'description' => 'Sample free shipping method.',
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
        $rows = ShippingMethod::query()
            ->with('taxClass:id,name')
            ->orderBy('position')
            ->orderBy('name')
            ->limit(10000)
            ->get()
            ->map(fn (ShippingMethod $method) => [
                'code' => $method->code,
                'name' => $method->name,
                'type' => $method->type,
                'price' => $method->price,
                'tax_class' => $method->taxClass?->name,
                'description' => $method->description,
                'is_enabled' => $method->is_enabled ? '1' : '0',
                'position' => $method->position,
            ])->all();

        return AdminCsv::stringify(self::columns(), $rows, $delimiter);
    }

    public static function import(string $contents, ?string $delimiter = null): int
    {
        $parsed = AdminCsv::parse($contents, self::columns(), 'Shipping method', $delimiter, self::required());
        $count = 0;
        $position = (int) (ShippingMethod::query()->max('position') ?? 0);

        foreach ($parsed['rows'] as $row) {
            $name = AdminCsv::get($row, 'name');
            if ($name === '') {
                continue;
            }
            $code = Str::slug(AdminCsv::get($row, 'code') ?: $name);
            $type = AdminCsv::get($row, 'type') ?: 'flat';
            if (! isset(ShippingMethod::TYPES[$type])) {
                $type = 'flat';
            }
            $payload = [
                'name' => $name,
                'type' => $type,
                'price' => AdminCsv::float(AdminCsv::get($row, 'price'), 0) ?? 0,
                'description' => AdminCsv::nullable(AdminCsv::get($row, 'description')),
                'is_enabled' => AdminCsv::bool(AdminCsv::get($row, 'is_enabled'), true),
                'position' => AdminCsv::int(AdminCsv::get($row, 'position'), $position + 10) ?? ($position + 10),
            ];
            // Only configure shipping tax class while charge-tax-on-shipping is enabled.
            if (PricingSettings::shippingTax()) {
                $taxClassName = trim((string) AdminCsv::get($row, 'tax_class'));
                $taxClassId = null;
                if ($taxClassName !== '') {
                    $taxClassId = TaxClass::query()->where('name', $taxClassName)->value('id');
                }
                $payload['tax_class_id'] = $taxClassId;
            }
            $existing = ShippingMethod::query()->where('code', $code)->first();
            if ($existing) {
                $existing->update($payload);
            } else {
                $position = (int) $payload['position'];
                ShippingMethod::query()->create(array_merge($payload, [
                    'code' => $code,
                    'driver' => $type === 'carrier' ? 'sandbox' : 'offline',
                ]));
            }
            $count++;
        }

        return $count;
    }
}
