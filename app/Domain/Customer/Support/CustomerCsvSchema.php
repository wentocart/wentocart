<?php

namespace App\Domain\Customer\Support;

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerGroup;
use App\Domain\Settings\AdminCsv;
use App\Domain\Store\StoreContext;
use Illuminate\Support\Facades\Hash;

final class CustomerCsvSchema
{
    /** @return list<string> */
    public static function columns(): array
    {
        return ['id', 'name', 'email', 'phone', 'group', 'status', 'created_at'];
    }

    /** @return list<string> */
    public static function required(): array
    {
        return ['email'];
    }

    /** @return list<array<string, mixed>> */
    public static function samples(): array
    {
        return [
            [
                'id' => '',
                'name' => 'Jane Shopper',
                'email' => 'jane.shopper@example.com',
                'phone' => '+1-555-0100',
                'group' => 'retailer',
                'status' => 'active',
                'created_at' => '',
            ],
            [
                'id' => '',
                'name' => 'Alex Wholesale',
                'email' => 'alex.wholesale@example.com',
                'phone' => '+1-555-0101',
                'group' => 'wholesale',
                'status' => 'active',
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
        $parsed = AdminCsv::parse($contents, self::columns(), 'Customer', $delimiter, self::required());
        $storeId = app(StoreContext::class)->customerHomeId() ?: null;
        $now = now();
        $password = Hash::make('password');
        $groupCodes = CustomerGroup::codes();
        $seen = [];
        $rows = [];

        foreach ($parsed['rows'] as $row) {
            $email = strtolower(AdminCsv::get($row, 'email'));
            if ($email === '' || isset($seen[$email]) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $seen[$email] = true;
            $name = AdminCsv::get($row, 'name') ?: 'Imported customer';
            $parts = preg_split('/\s+/', $name, 2) ?: [$name];
            $group = strtolower(AdminCsv::get($row, 'group') ?: 'retailer');
            if (! in_array($group, $groupCodes, true)) {
                $group = $groupCodes[0] ?? 'retailer';
            }
            $rows[] = [
                'name' => $name,
                'first_name' => $parts[0] ?: null,
                'last_name' => $parts[1] ?? null,
                'email' => $email,
                'phone' => AdminCsv::nullable(AdminCsv::get($row, 'phone')),
                'password' => $password,
                'customer_group' => $group,
                'is_enabled' => AdminCsv::bool(AdminCsv::get($row, 'status'), true),
                'store_id' => $storeId,
                'created_at' => $now->toDateTimeString(),
                'updated_at' => $now->toDateTimeString(),
            ];
            if (count($rows) >= 25000) {
                break;
            }
        }

        $count = 0;
        foreach (array_chunk($rows, 500) as $chunk) {
            Customer::query()->upsert(
                $chunk,
                $storeId ? ['email', 'store_id'] : ['email'],
                ['name', 'first_name', 'last_name', 'phone', 'customer_group', 'is_enabled', 'updated_at']
            );
            $count += count($chunk);
        }

        return $count;
    }
}
