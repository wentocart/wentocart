<?php

namespace App\Domain\Catalog\Support;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\CatalogInventoryService;
use App\Domain\Settings\AdminCsv;

final class InventoryCsvSchema
{
    /** @return list<string> */
    public static function columns(): array
    {
        return ['id', 'sku', 'qty', 'note'];
    }

    /** @return list<string> */
    public static function required(): array
    {
        return ['sku', 'qty'];
    }

    /** @return list<array<string, mixed>> */
    public static function samples(): array
    {
        return [
            [
                'sku' => 'DEMO-SKU',
                'qty' => '25',
                'note' => 'CSV stock set',
            ],
        ];
    }

    public static function sampleCsv(string $delimiter = AdminCsv::DELIMITER): string
    {
        return AdminCsv::stringify(self::columns(), self::samples(), $delimiter);
    }

    public static function exportCsv(string $delimiter = AdminCsv::DELIMITER): string
    {
        $rows = Product::query()
            ->orderBy('sku')
            ->limit(10000)
            ->get(['sku', 'stock_qty', 'reserved_qty'])
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'sku' => $product->sku,
                'qty' => (int) $product->availableStock(),
                'note' => '',
            ])
            ->all();

        return AdminCsv::stringify(self::columns(), $rows, $delimiter);
    }

    public static function import(string $contents, ?string $delimiter = null): int
    {
        $parsed = AdminCsv::parse($contents, self::columns(), 'Inventory', $delimiter, self::required());
        $inventory = app(CatalogInventoryService::class);
        $errors = [];
        $count = 0;

        foreach ($parsed['rows'] as $index => $row) {
            $sku = AdminCsv::get($row, 'sku');
            $qty = AdminCsv::int(AdminCsv::get($row, 'qty'), 0);
            $product = Product::query()->where('sku', $sku)->first();
            $line = CatalogCsvImport::rowNumber($index);
            if (! $product) {
                $errors[] = "Row {$line}: unknown product SKU \"{$sku}\".";

                continue;
            }
            $qty = max(0, (int) $qty);
            $current = (int) $product->availableStock();
            $delta = $qty - $current;
            if ($delta === 0) {
                continue;
            }
            $inventory->adjust($product, $delta, AdminCsv::nullable(AdminCsv::get($row, 'note')) ?: 'CSV import');
            $count++;
        }

        CatalogCsvImport::throwRowErrors($errors);

        return $count;
    }
}
