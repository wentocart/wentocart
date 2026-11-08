<?php

namespace App\Domain\Payment\Support;

use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Settings\AdminCsv;
use Illuminate\Support\Str;

final class PaymentMethodCsvSchema
{
    /** @return list<string> */
    public static function columns(): array
    {
        return ['code', 'name', 'driver', 'description', 'is_enabled', 'position'];
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
                'code' => 'csv-bank',
                'name' => 'CSV bank transfer',
                'driver' => 'offline',
                'description' => 'Sample offline payment created from CSV.',
                'is_enabled' => '1',
                'position' => '10',
            ],
        ];
    }

    public static function sampleCsv(string $delimiter = AdminCsv::DELIMITER): string
    {
        return AdminCsv::stringify(self::columns(), self::samples(), $delimiter);
    }

    public static function exportCsv(?array $ids = null, string $delimiter = AdminCsv::DELIMITER): string
    {
        $rows = PaymentMethod::query()
            ->when(! empty($ids), fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('position')
            ->orderBy('name')
            ->limit(10000)
            ->get()
            ->map(fn (PaymentMethod $method) => [
            'code' => $method->code,
            'name' => $method->name,
            'driver' => $method->driver ?: 'offline',
            'description' => $method->description,
            'is_enabled' => $method->is_enabled ? '1' : '0',
            'position' => $method->position,
        ])->all();

        return AdminCsv::stringify(self::columns(), $rows, $delimiter);
    }

    public static function import(string $contents, ?string $delimiter = null): int
    {
        $parsed = AdminCsv::parse($contents, self::columns(), 'Payment method', $delimiter, self::required());
        $drivers = ['offline', 'paypal', 'razorpay', 'upi'];
        $count = 0;
        $position = (int) (PaymentMethod::query()->max('position') ?? 0);

        foreach ($parsed['rows'] as $row) {
            $name = AdminCsv::get($row, 'name');
            if ($name === '') {
                continue;
            }
            $code = Str::slug(AdminCsv::get($row, 'code') ?: $name);
            $driver = strtolower(AdminCsv::get($row, 'driver') ?: 'offline');
            if (! in_array($driver, $drivers, true)) {
                $driver = 'offline';
            }
            $payload = [
                'name' => $name,
                'driver' => $driver,
                'description' => AdminCsv::nullable(AdminCsv::get($row, 'description')),
                'is_enabled' => AdminCsv::bool(AdminCsv::get($row, 'is_enabled'), true),
                'position' => AdminCsv::int(AdminCsv::get($row, 'position'), $position + 10) ?? ($position + 10),
            ];
            $existing = PaymentMethod::query()->where('code', $code)->first();
            if ($existing) {
                $existing->update($payload);
            } else {
                $position = (int) $payload['position'];
                PaymentMethod::query()->create(array_merge($payload, [
                    'code' => $code,
                    'config' => [],
                    'rules' => PaymentMethod::emptyRules(),
                ]));
            }
            $count++;
        }

        return $count;
    }
}
