<?php

namespace App\Domain\Customer\Support;

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerAddress;
use App\Domain\Customer\Services\CustomerService;
use App\Domain\Settings\AdminCsv;

final class AddressCsvSchema
{
    /** @return list<string> */
    public static function columns(): array
    {
        return [
            'id', 'customer_id', 'customer_email', 'label', 'first_name', 'last_name', 'phone',
            'line1', 'line2', 'city', 'state', 'postal_code', 'country_code',
            'is_default_billing', 'is_default_shipping',
        ];
    }

    /** @return list<string> */
    public static function required(): array
    {
        return ['line1'];
    }

    /** @return list<array<string, mixed>> */
    public static function samples(): array
    {
        return [
            [
                'id' => '',
                'customer_id' => '',
                'customer_email' => 'jane.shopper@example.com',
                'label' => 'Home',
                'first_name' => 'Jane',
                'last_name' => 'Shopper',
                'phone' => '+1-555-0100',
                'line1' => '100 Market Street',
                'line2' => 'Suite 4',
                'city' => 'Austin',
                'state' => 'TX',
                'postal_code' => '78701',
                'country_code' => 'US',
                'is_default_billing' => '1',
                'is_default_shipping' => '1',
            ],
        ];
    }

    public static function sampleCsv(string $delimiter = AdminCsv::DELIMITER): string
    {
        return AdminCsv::stringify(self::columns(), self::samples(), $delimiter);
    }

    public static function import(string $contents, ?string $delimiter = null): int
    {
        $parsed = AdminCsv::parse($contents, self::columns(), 'Address', $delimiter, self::required());
        $customers = app(CustomerService::class);
        $emailCache = [];
        $count = 0;

        foreach ($parsed['rows'] as $row) {
            $line1 = AdminCsv::get($row, 'line1');
            if ($line1 === '') {
                continue;
            }
            $customerId = AdminCsv::int(AdminCsv::get($row, 'customer_id'), 0) ?: 0;
            $email = strtolower(AdminCsv::get($row, 'customer_email'));
            if ($customerId < 1 && $email !== '') {
                if (! array_key_exists($email, $emailCache)) {
                    $emailCache[$email] = (int) (Customer::query()->where('email', $email)->value('id') ?: 0);
                }
                $customerId = $emailCache[$email];
            }
            if ($customerId < 1) {
                continue;
            }
            $customer = Customer::query()->find($customerId);
            if (! $customer) {
                continue;
            }
            $payload = [
                'label' => AdminCsv::get($row, 'label') ?: 'Shipping',
                'first_name' => AdminCsv::nullable(AdminCsv::get($row, 'first_name')),
                'last_name' => AdminCsv::nullable(AdminCsv::get($row, 'last_name')),
                'phone' => AdminCsv::nullable(AdminCsv::get($row, 'phone')),
                'line1' => $line1,
                'line2' => AdminCsv::nullable(AdminCsv::get($row, 'line2')),
                'city' => AdminCsv::nullable(AdminCsv::get($row, 'city')),
                'state' => AdminCsv::nullable(AdminCsv::get($row, 'state')),
                'postal_code' => AdminCsv::nullable(AdminCsv::get($row, 'postal_code')),
                'country_code' => strtoupper(AdminCsv::get($row, 'country_code') ?: 'IN') ?: 'IN',
                'is_default_billing' => AdminCsv::bool(AdminCsv::get($row, 'is_default_billing'), false),
                'is_default_shipping' => AdminCsv::bool(AdminCsv::get($row, 'is_default_shipping'), false),
            ];
            $existingId = AdminCsv::int(AdminCsv::get($row, 'id'), 0) ?: 0;
            if ($existingId > 0) {
                $existing = CustomerAddress::query()
                    ->whereKey($existingId)
                    ->where('customer_id', $customerId)
                    ->first();
                if ($existing) {
                    $customers->updateAddress($customer, $existing->id, $payload);
                    $count++;
                    continue;
                }
            }
            $customers->addAddress($customer, $payload);
            $count++;
            if ($count >= 10000) {
                break;
            }
        }

        return $count;
    }
}
