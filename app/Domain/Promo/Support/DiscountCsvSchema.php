<?php

namespace App\Domain\Promo\Support;

use App\Domain\Localization\Models\Currency;
use App\Domain\Promo\Models\DiscountRule;
use App\Domain\Settings\AdminCsv;

final class DiscountCsvSchema
{
    /** @return list<string> */
    public static function columns(): array
    {
        return [
            'name', 'apply_as', 'code', 'action', 'value', 'currency_code',
            'priority', 'min_cart', 'min_qty', 'max_discount', 'max_uses',
            'max_uses_per_customer',
            'starts_at', 'expires_at', 'is_enabled',
        ];
    }

    /** @return list<string> */
    public static function required(): array
    {
        return ['name', 'apply_as', 'action', 'value'];
    }

    /** @return list<array<string, mixed>> */
    public static function samples(): array
    {
        return [
            [
                'name' => 'Welcome 10 percent',
                'apply_as' => 'coupon',
                'code' => 'WELCOME10',
                'action' => 'percent',
                'value' => '10',
                'currency_code' => 'USD',
                'priority' => '100',
                'min_cart' => '0',
                'min_qty' => '',
                'max_discount' => '',
                'max_uses' => '500',
                'max_uses_per_customer' => '1',
                'starts_at' => '',
                'expires_at' => '',
                'is_enabled' => '1',
            ],
            [
                'name' => 'Cart 5 off',
                'apply_as' => 'cart',
                'code' => '',
                'action' => 'amount',
                'value' => '5',
                'currency_code' => 'USD',
                'priority' => '50',
                'min_cart' => '40',
                'min_qty' => '',
                'max_discount' => '',
                'max_uses' => '',
                'max_uses_per_customer' => '',
                'starts_at' => '',
                'expires_at' => '',
                'is_enabled' => '1',
            ],
        ];
    }

    public static function sampleCsv(string $delimiter = AdminCsv::DELIMITER): string
    {
        return AdminCsv::stringify(self::columns(), self::samples(), $delimiter);
    }

    public static function exportCsv(?array $ids = null, string $delimiter = AdminCsv::DELIMITER): string
    {
        $rows = DiscountRule::query()
            ->when(! empty($ids), fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('priority')
            ->orderBy('id')
            ->limit(10000)
            ->get()
            ->map(fn (DiscountRule $rule) => [
            'name' => $rule->name,
            'apply_as' => $rule->apply_as,
            'code' => $rule->code,
            'action' => $rule->action,
            'value' => $rule->value,
            'currency_code' => $rule->currency_code,
            'priority' => $rule->priority,
            'min_cart' => $rule->min_cart,
            'min_qty' => $rule->min_qty,
            'max_discount' => $rule->max_discount,
            'max_uses' => $rule->max_uses,
            'max_uses_per_customer' => $rule->max_uses_per_customer,
            'starts_at' => $rule->starts_at?->toDateTimeString(),
            'expires_at' => $rule->expires_at?->toDateTimeString(),
            'is_enabled' => $rule->is_enabled ? '1' : '0',
        ])->all();

        return AdminCsv::stringify(self::columns(), $rows, $delimiter);
    }

    public static function import(string $contents, ?string $delimiter = null): int
    {
        \App\Domain\Promo\Models\DiscountRedemption::ensureStorage();
        $parsed = AdminCsv::parse($contents, self::columns(), 'Discount', $delimiter, self::required());
        $fallbackCurrency = Currency::query()->where('is_default', true)->value('code')
            ?: Currency::query()->where('is_enabled', true)->value('code')
            ?: 'USD';
        $count = 0;

        foreach ($parsed['rows'] as $row) {
            $name = AdminCsv::get($row, 'name');
            $applyAs = AdminCsv::get($row, 'apply_as') ?: 'cart';
            $action = AdminCsv::get($row, 'action') ?: 'percent';
            if ($name === '' || ! isset(DiscountRule::APPLY_AS[$applyAs]) || ! isset(DiscountRule::ACTIONS[$action])) {
                continue;
            }
            $code = strtoupper(AdminCsv::get($row, 'code'));
            if ($applyAs !== 'coupon') {
                $code = '';
            }
            if ($applyAs === 'coupon' && $code === '') {
                continue;
            }
            $currency = strtoupper(AdminCsv::get($row, 'currency_code') ?: $fallbackCurrency) ?: $fallbackCurrency;
            $payload = [
                'name' => $name,
                'apply_as' => $applyAs,
                'code' => $code !== '' ? $code : null,
                'action' => $action,
                'value' => AdminCsv::float(AdminCsv::get($row, 'value'), 0) ?? 0,
                'currency_code' => $currency,
                'priority' => AdminCsv::int(AdminCsv::get($row, 'priority'), 100) ?? 100,
                'stop_further' => false,
                'min_cart' => AdminCsv::float(AdminCsv::get($row, 'min_cart')),
                'min_qty' => AdminCsv::int(AdminCsv::get($row, 'min_qty')),
                'max_discount' => AdminCsv::float(AdminCsv::get($row, 'max_discount')),
                'max_uses' => AdminCsv::int(AdminCsv::get($row, 'max_uses')),
                'max_uses_per_customer' => AdminCsv::int(AdminCsv::get($row, 'max_uses_per_customer')),
                'starts_at' => AdminCsv::nullable(AdminCsv::get($row, 'starts_at')),
                'expires_at' => AdminCsv::nullable(AdminCsv::get($row, 'expires_at')),
                'is_enabled' => AdminCsv::bool(AdminCsv::get($row, 'is_enabled'), true),
            ];
            $existing = $code !== ''
                ? DiscountRule::query()->where('code', $code)->first()
                : DiscountRule::query()->where('name', $name)->where('apply_as', $applyAs)->whereNull('code')->first();
            if ($existing) {
                $existing->update($payload);
            } else {
                DiscountRule::query()->create(array_merge($payload, ['used_count' => 0]));
            }
            $count++;
        }

        return $count;
    }
}
