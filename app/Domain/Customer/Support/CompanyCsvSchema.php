<?php

namespace App\Domain\Customer\Support;

use App\Domain\Customer\Models\Company;
use App\Domain\Settings\AdminCsv;

final class CompanyCsvSchema
{
    /** @return list<string> */
    public static function columns(): array
    {
        return ['name', 'email', 'phone', 'credit_limit', 'net_days', 'is_enabled'];
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
                'name' => 'Northwind Trading',
                'email' => 'ap@northwind.example',
                'phone' => '+1-555-0140',
                'credit_limit' => '5000',
                'net_days' => '30',
                'is_enabled' => '1',
            ],
            [
                'name' => 'Harbor Supplies Co',
                'email' => 'billing@harbor.example',
                'phone' => '+1-555-0141',
                'credit_limit' => '2500',
                'net_days' => '15',
                'is_enabled' => '1',
            ],
        ];
    }

    public static function sampleCsv(string $delimiter = AdminCsv::DELIMITER): string
    {
        return AdminCsv::stringify(self::columns(), self::samples(), $delimiter);
    }

    public static function exportCsv(string $delimiter = AdminCsv::DELIMITER): string
    {
        $rows = Company::query()->orderBy('name')->limit(10000)->get()->map(fn (Company $company) => [
            'name' => $company->name,
            'email' => $company->email,
            'phone' => $company->phone,
            'credit_limit' => $company->credit_limit,
            'net_days' => $company->net_days,
            'is_enabled' => $company->is_enabled ? '1' : '0',
        ])->all();

        return AdminCsv::stringify(self::columns(), $rows, $delimiter);
    }

    public static function import(string $contents, ?string $delimiter = null): int
    {
        $parsed = AdminCsv::parse($contents, self::columns(), 'Company', $delimiter, self::required());
        $count = 0;
        foreach ($parsed['rows'] as $row) {
            $name = AdminCsv::get($row, 'name');
            if ($name === '') {
                continue;
            }
            $email = AdminCsv::nullable(AdminCsv::get($row, 'email'));
            $payload = [
                'name' => $name,
                'email' => $email,
                'phone' => AdminCsv::nullable(AdminCsv::get($row, 'phone')),
                'credit_limit' => AdminCsv::float(AdminCsv::get($row, 'credit_limit'), 0) ?? 0,
                'net_days' => AdminCsv::int(AdminCsv::get($row, 'net_days'), 30) ?? 30,
                'is_enabled' => AdminCsv::bool(AdminCsv::get($row, 'is_enabled'), true),
            ];
            $existing = Company::query()
                ->where(function ($query) use ($name, $email) {
                    $query->where('name', $name);
                    if ($email) {
                        $query->orWhere('email', $email);
                    }
                })
                ->first();
            if ($existing) {
                $existing->update($payload);
            } else {
                Company::query()->create($payload);
            }
            $count++;
        }

        return $count;
    }
}
