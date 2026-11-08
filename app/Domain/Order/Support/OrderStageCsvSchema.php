<?php

namespace App\Domain\Order\Support;

use App\Domain\Order\Models\OrderStage;
use App\Domain\Settings\AdminCsv;
use App\Support\HexColor;
use Illuminate\Support\Str;

final class OrderStageCsvSchema
{
    /** @return list<string> */
    public static function columns(): array
    {
        return ['code', 'name', 'buyer_label', 'tone', 'lane', 'is_enabled', 'position'];
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
                'code' => 'awaiting-parts',
                'name' => 'Awaiting parts',
                'buyer_label' => 'We are waiting on a part',
                'tone' => HexColor::TONES['warning'],
                'lane' => 'hold',
                'is_enabled' => '1',
                'position' => '80',
            ],
        ];
    }

    public static function sampleCsv(string $delimiter = AdminCsv::DELIMITER): string
    {
        return AdminCsv::stringify(self::columns(), self::samples(), $delimiter);
    }

    public static function exportCsv(string $delimiter = AdminCsv::DELIMITER): string
    {
        $rows = OrderStage::query()->orderBy('position')->orderBy('id')->get()->map(fn (OrderStage $stage) => [
            'code' => $stage->code,
            'name' => $stage->name,
            'buyer_label' => $stage->buyer_label,
            'tone' => $stage->tone,
            'lane' => $stage->lane,
            'is_enabled' => $stage->is_enabled ? '1' : '0',
            'position' => $stage->position,
        ])->all();

        return AdminCsv::stringify(self::columns(), $rows, $delimiter);
    }

    public static function import(string $contents, ?string $delimiter = null): int
    {
        $parsed = AdminCsv::parse($contents, self::columns(), 'Order stage', $delimiter, self::required());
        $count = 0;
        $position = (int) (OrderStage::query()->max('position') ?? 0);

        foreach ($parsed['rows'] as $row) {
            $name = AdminCsv::get($row, 'name');
            if ($name === '') {
                continue;
            }
            $code = Str::slug(AdminCsv::get($row, 'code') ?: $name);
            if ($code === '') {
                continue;
            }
            $lane = AdminCsv::get($row, 'lane') ?: 'inbox';
            if (! isset(OrderStage::LANES[$lane])) {
                $lane = 'inbox';
            }
            $payload = [
                'name' => $name,
                'buyer_label' => AdminCsv::get($row, 'buyer_label') ?: $name,
                'tone' => HexColor::parse(AdminCsv::get($row, 'tone') ?: 'neutral', HexColor::TONES['neutral']),
                'lane' => $lane,
                'is_enabled' => AdminCsv::bool(AdminCsv::get($row, 'is_enabled'), true),
            ];
            $existing = OrderStage::query()->where('code', $code)->first();
            if ($existing) {
                if ($existing->is_system) {
                    $existing->update([
                        'name' => $payload['name'],
                        'buyer_label' => $payload['buyer_label'],
                        'is_enabled' => $payload['is_enabled'],
                    ]);
                } else {
                    $existing->update($payload);
                }
            } else {
                $position += 10;
                OrderStage::query()->create(array_merge($payload, [
                    'code' => $code,
                    'position' => AdminCsv::int(AdminCsv::get($row, 'position'), $position) ?? $position,
                    'is_system' => false,
                    'notify_staff' => false,
                    'buyer_may_pay' => true,
                    'buyer_may_cancel' => true,
                ]));
            }
            $count++;
        }

        return $count;
    }
}
