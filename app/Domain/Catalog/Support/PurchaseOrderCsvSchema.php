<?php

namespace App\Domain\Catalog\Support;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\PurchaseOrder;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Catalog\Models\Warehouse;
use App\Domain\Catalog\Services\PurchaseOrderService;
use App\Domain\Settings\AdminCsv;

final class PurchaseOrderCsvSchema
{
    /** @return list<string> */
    public static function columns(): array
    {
        return ['id', 'number', 'supplier', 'warehouse', 'sku', 'qty', 'unit_cost', 'status'];
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
                'number' => 'PO-SAMPLE',
                'supplier' => 'Demo supplier',
                'warehouse' => 'default',
                'sku' => 'DEMO-SKU',
                'qty' => '12',
                'unit_cost' => '4.50',
                'status' => 'draft',
            ],
        ];
    }

    public static function sampleCsv(string $delimiter = AdminCsv::DELIMITER): string
    {
        return AdminCsv::stringify(self::columns(), self::samples(), $delimiter);
    }

    public static function exportCsv(string $delimiter = AdminCsv::DELIMITER): string
    {
        $rows = [];
        PurchaseOrder::query()->with(['items', 'supplier.translations', 'warehouse'])->orderByDesc('id')->limit(10000)->get()
            ->each(function (PurchaseOrder $order) use (&$rows) {
                foreach ($order->items as $item) {
                    $rows[] = [
                        'id' => $item->id,
                        'number' => $order->number,
                        'supplier' => $order->supplier?->displayName() ?: '',
                        'warehouse' => $order->warehouse?->code ?: '',
                        'sku' => $item->sku,
                        'qty' => $item->qty_ordered,
                        'unit_cost' => $item->unit_cost,
                        'status' => $order->status,
                    ];
                }
            });

        return AdminCsv::stringify(self::columns(), $rows, $delimiter);
    }

    public static function import(string $contents, ?string $delimiter = null): int
    {
        $parsed = AdminCsv::parse($contents, self::columns(), 'Purchase order', $delimiter, self::required());
        $grouped = [];
        foreach ($parsed['rows'] as $row) {
            $number = AdminCsv::get($row, 'number') ?: '';
            $grouped[$number !== '' ? $number : uniqid('po-', true)][] = $row;
        }

        $service = app(PurchaseOrderService::class);
        $count = 0;
        foreach ($grouped as $number => $lines) {
            $first = $lines[0];
            $supplierName = AdminCsv::get($first, 'supplier');
            $supplier = $supplierName !== ''
                ? Supplier::query()->whereHas('translations', fn ($q) => $q->where('name', $supplierName))->first()
                : null;
            $warehouseCode = AdminCsv::get($first, 'warehouse');
            $warehouse = $warehouseCode !== ''
                ? Warehouse::query()->where('code', $warehouseCode)->first()
                : Warehouse::query()->where('is_default', true)->first();
            $items = [];
            foreach ($lines as $line) {
                $sku = AdminCsv::get($line, 'sku');
                $product = Product::query()->where('sku', $sku)->first();
                if (! $product) {
                    continue;
                }
                $items[] = [
                    'sku' => $product->sku,
                    'product_id' => $product->id,
                    'qty' => AdminCsv::get($line, 'qty'),
                    'unit_cost' => AdminCsv::get($line, 'unit_cost'),
                ];
            }
            if ($items === []) {
                continue;
            }
            $existing = is_string($number) && ! str_starts_with($number, 'po-')
                ? PurchaseOrder::query()->where('number', $number)->first()
                : null;
            if ($existing && ! in_array($existing->status, [PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_ORDERED], true)) {
                continue;
            }
            if ($existing && $existing->items()->where('qty_received', '>', 0)->exists()) {
                continue;
            }
            $order = $service->save($existing, [
                'supplier_id' => $supplier?->id,
                'warehouse_id' => $warehouse?->id,
                'notes' => '',
                'items' => $items,
            ]);
            if ($existing && $existing->number) {
                // keep number
            } elseif (is_string($number) && ! str_starts_with((string) $number, 'po-')) {
                $order->update(['number' => $number]);
            }
            $status = strtolower(AdminCsv::get($first, 'status') ?: 'draft');
            if ($status === PurchaseOrder::STATUS_ORDERED && $order->status === PurchaseOrder::STATUS_DRAFT) {
                $service->markOrdered($order);
            }
            $count++;
        }

        return $count;
    }
}
