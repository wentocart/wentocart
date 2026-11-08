<?php

namespace App\Domain\Order\Support;

use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Settings\AdminCsv;
use App\Domain\Settings\Models\Setting;

final class OrderCsvSchema
{
    /** @return list<string> */
    public static function columns(): array
    {
        return ['id', 'number', 'email', 'customer', 'status', 'payment_status', 'payment_method', 'grand_total', 'currency', 'created_at'];
    }

    /** @return list<string> */
    public static function required(): array
    {
        return ['number', 'email'];
    }

    /** @return list<array<string, mixed>> */
    public static function samples(): array
    {
        return [
            [
                'id' => '',
                'number' => 'SO-CSV-1001',
                'email' => 'jane.shopper@example.com',
                'customer' => 'Jane Shopper',
                'status' => 'pending',
                'payment_status' => 'pending',
                'payment_method' => 'Bank transfer',
                'grand_total' => '49.00',
                'currency' => 'USD',
                'created_at' => '',
            ],
            [
                'id' => '',
                'number' => 'SO-CSV-1002',
                'email' => 'alex.wholesale@example.com',
                'customer' => 'Alex Wholesale',
                'status' => 'pending',
                'payment_status' => 'paid',
                'payment_method' => 'Bank transfer',
                'grand_total' => '120.00',
                'currency' => 'USD',
                'created_at' => '',
            ],
        ];
    }

    public static function sampleCsv(string $delimiter = AdminCsv::DELIMITER): string
    {
        return AdminCsv::stringify(self::columns(), self::samples(), $delimiter);
    }

    public static function import(string $contents, ?string $delimiter = null): int
    {
        set_time_limit(120);
        $parsed = AdminCsv::parse($contents, self::columns(), 'Order', $delimiter, self::required());
        $currency = Setting::getValue('currency', 'USD') ?: 'USD';
        $count = 0;

        foreach ($parsed['rows'] as $row) {
            $number = AdminCsv::get($row, 'number');
            $email = strtolower(AdminCsv::get($row, 'email'));
            if ($number === '' || $email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            if (Order::query()->where('number', $number)->exists()) {
                continue;
            }
            $customer = Customer::query()->where('email', $email)->first();
            $status = strtolower(AdminCsv::get($row, 'status') ?: 'pending') ?: 'pending';
            $paymentStatus = strtolower(AdminCsv::get($row, 'payment_status') ?: 'pending');
            if (! in_array($paymentStatus, ['paid', 'pending', 'failed', 'refunded'], true)) {
                $paymentStatus = 'pending';
            }
            $total = AdminCsv::float(AdminCsv::get($row, 'grand_total'), 0) ?? 0;
            $code = strtoupper(AdminCsv::get($row, 'currency') ?: $currency) ?: $currency;

            $address = [
                'first_name' => $customer?->first_name ?: explode('@', $email)[0],
                'last_name' => $customer?->last_name ?: '',
                'email' => $email,
            ];

            Order::createTrusted([
                'number' => $number,
                'customer_id' => $customer?->id,
                'email' => $email,
                'status' => $status,
                'currency_code' => $code,
                'currency_rate' => 1,
                'subtotal' => $total,
                'tax_total' => 0,
                'shipping_total' => 0,
                'discount_total' => 0,
                'fee_total' => 0,
                'grand_total' => $total,
                'payment_status' => $paymentStatus,
                'amount_paid' => $paymentStatus === 'paid' ? $total : 0,
                'amount_due' => $paymentStatus === 'paid' ? 0 : $total,
                'billing_address' => $address,
                'shipping_address' => $address,
            ]);
            $count++;
            if ($count >= 1000) {
                break;
            }
        }

        return $count;
    }
}
