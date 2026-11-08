<?php

namespace App\Domain\Customer\Support;

use App\Domain\Customer\Models\CustomerGroup;
use App\Domain\Settings\AdminCsv;
use App\Support\HexColor;
use Illuminate\Support\Str;

final class CustomerGroupCsvSchema
{
    /** @return list<string> */
    public static function columns(): array
    {
        return ['id', 'name', 'code', 'color', 'discount_percent', 'tax_exempt', 'description', 'is_enabled', 'customers_count'];
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
                'id' => '',
                'name' => 'VIP Club',
                'code' => 'vip-club',
                'color' => HexColor::TONES['warning'],
                'discount_percent' => '5',
                'tax_exempt' => '0',
                'description' => 'Sample VIP group for CSV import.',
                'is_enabled' => '1',
                'customers_count' => '',
            ],
            [
                'id' => '',
                'name' => 'Trade partners',
                'code' => 'trade-partners',
                'color' => HexColor::TONES['info'],
                'discount_percent' => '10',
                'tax_exempt' => '0',
                'description' => 'Sample wholesale group.',
                'is_enabled' => '1',
                'customers_count' => '',
            ],
        ];
    }

    public static function sampleCsv(string $delimiter = AdminCsv::DELIMITER): string
    {
        return AdminCsv::stringify(self::columns(), self::samples(), $delimiter);
    }

    public static function exportCsv(string $delimiter = AdminCsv::DELIMITER): string
    {
        $rows = CustomerGroup::query()
            ->withCount('customers')
            ->orderBy('position')
            ->orderBy('name')
            ->limit(10000)
            ->get()
            ->map(fn (CustomerGroup $group) => [
                'id' => $group->id,
                'name' => $group->name,
                'code' => $group->code,
                'color' => $group->color,
                'discount_percent' => $group->discount_percent,
                'tax_exempt' => $group->tax_exempt ? '1' : '0',
                'description' => $group->description,
                'is_enabled' => $group->is_enabled ? '1' : '0',
                'customers_count' => (int) ($group->customers_count ?? 0),
            ])->all();

        return AdminCsv::stringify(self::columns(), $rows, $delimiter);
    }

    public static function import(string $contents, ?string $delimiter = null): int
    {
        $parsed = AdminCsv::parse($contents, self::columns(), 'Customer group', $delimiter, self::required());
        $position = (int) CustomerGroup::query()->max('position');
        $count = 0;

        foreach ($parsed['rows'] as $row) {
            $name = AdminCsv::get($row, 'name');
            $code = Str::slug(AdminCsv::get($row, 'code') ?: $name);
            if ($name === '' || $code === '') {
                continue;
            }
            $color = HexColor::parse(AdminCsv::get($row, 'color') ?: 'primary');
            $payload = [
                'name' => $name,
                'color' => $color,
                'discount_percent' => AdminCsv::float(AdminCsv::get($row, 'discount_percent'), 0) ?? 0,
                'tax_exempt' => AdminCsv::bool(AdminCsv::get($row, 'tax_exempt'), false),
                'description' => AdminCsv::nullable(AdminCsv::get($row, 'description')),
                'is_enabled' => AdminCsv::bool(AdminCsv::get($row, 'is_enabled'), true),
            ];
            $existing = CustomerGroup::query()->where('code', $code)->first();
            if ($existing) {
                $existing->update($payload);
            } else {
                $position++;
                CustomerGroup::query()->create([
                    ...$payload,
                    'code' => $code,
                    'position' => $position,
                ]);
            }
            $count++;
            if ($count >= 5000) {
                break;
            }
        }

        return $count;
    }
}
