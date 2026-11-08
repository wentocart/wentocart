<?php

namespace App\Domain\Tax\Support;

use App\Domain\Catalog\Support\CatalogCsvImport;
use App\Domain\Settings\AdminCsv;
use App\Domain\Tax\Models\TaxClass;
use App\Domain\Tax\Models\TaxRate;
use App\Domain\Tax\Models\TaxRule;
use App\Domain\Tax\Models\TaxZone;

final class TaxRuleCsvSchema
{
    /** @return list<string> */
    public static function columns(): array
    {
        return ['name', 'class_name', 'zone_name', 'rate_name', 'rate', 'priority', 'is_compound', 'is_enabled'];
    }

    /** @return list<string> */
    public static function required(): array
    {
        return ['name', 'class_name'];
    }

    /** @return list<array<string, mixed>> */
    public static function samples(): array
    {
        return [
            [
                'name' => 'Standard US',
                'class_name' => 'Standard',
                'zone_name' => '',
                'rate_name' => 'CSV rate',
                'rate' => '8.25',
                'priority' => '10',
                'is_compound' => '0',
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
        $rows = TaxRule::query()
            ->with(['taxClass:id,name', 'zone:id,name', 'taxRate:id,name,rate'])
            ->orderByDesc('priority')
            ->limit(10000)
            ->get()
            ->map(fn (TaxRule $rule) => [
                'name' => $rule->name,
                'class_name' => $rule->taxClass?->name,
                'zone_name' => $rule->zone?->name,
                'rate_name' => $rule->taxRate?->name,
                'rate' => $rule->resolvedRate(),
                'priority' => $rule->priority,
                'is_compound' => $rule->is_compound ? '1' : '0',
                'is_enabled' => $rule->is_enabled ? '1' : '0',
            ])
            ->all();

        return AdminCsv::stringify(self::columns(), $rows, $delimiter);
    }

    public static function import(string $contents, ?string $delimiter = null): int
    {
        $parsed = AdminCsv::parse($contents, self::columns(), 'Tax rule', $delimiter, self::required());
        $errors = [];
        $count = 0;

        foreach ($parsed['rows'] as $index => $row) {
            $name = AdminCsv::get($row, 'name');
            $className = AdminCsv::get($row, 'class_name');
            $line = CatalogCsvImport::rowNumber($index);
            $class = TaxClass::query()->where('name', $className)->first();
            if (! $class) {
                $errors[] = "Row {$line}: unknown tax class \"{$className}\".";
                continue;
            }
            $zoneId = null;
            $zoneName = AdminCsv::get($row, 'zone_name');
            if ($zoneName !== '') {
                $zone = TaxZone::query()->where('name', $zoneName)->first();
                if (! $zone) {
                    $errors[] = "Row {$line}: unknown tax zone \"{$zoneName}\".";
                    continue;
                }
                $zoneId = $zone->id;
            }
            $rateId = null;
            $rateName = AdminCsv::get($row, 'rate_name');
            $rateValue = AdminCsv::float(AdminCsv::get($row, 'rate'));
            if ($rateName !== '') {
                $rate = TaxRate::query()->firstOrCreate(
                    ['name' => $rateName],
                    ['rate' => $rateValue ?? 0, 'is_enabled' => true]
                );
                if ($rateValue !== null) {
                    $rate->update(['rate' => $rateValue]);
                }
                $rateId = $rate->id;
            }
            TaxRule::query()->updateOrCreate(
                ['name' => $name, 'tax_class_id' => $class->id],
                [
                    'tax_zone_id' => $zoneId,
                    'tax_rate_id' => $rateId,
                    'rate' => $rateValue,
                    'priority' => AdminCsv::int(AdminCsv::get($row, 'priority'), 10) ?? 10,
                    'is_compound' => AdminCsv::bool(AdminCsv::get($row, 'is_compound'), false),
                    'is_enabled' => AdminCsv::bool(AdminCsv::get($row, 'is_enabled'), true),
                ]
            );
            $count++;
        }

        CatalogCsvImport::throwRowErrors($errors);

        return $count;
    }
}
