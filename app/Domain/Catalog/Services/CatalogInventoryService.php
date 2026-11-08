<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\InventoryMovement;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Warehouse;
use Illuminate\Validation\ValidationException;

class CatalogInventoryService
{
    public function __construct(protected InventoryService $inventory) {}

    /**
     * Admin stock adjustment (positive = restock, negative = remove).
     */
    public function adjust(Product $product, int $delta, ?string $note = null, ?int $warehouseId = null): Product
    {
        if ($delta === 0) {
            return $product->fresh();
        }

        $warehouse = $this->resolveWarehouse($warehouseId);

        $reference = 'admin_adjust:'.$product->id.':'.now()->timestamp;
        if ($note) {
            $reference .= ':'.substr(md5($note), 0, 8);
        }

        if ($delta > 0) {
            $this->inventory->restock($product, $delta, InventoryMovement::ACTION_ADJUSTMENT, $reference, $warehouse);
        } else {
            $this->inventory->deduct($product, abs($delta), InventoryMovement::ACTION_ADJUSTMENT, $reference, $warehouse);
        }

        return $product->fresh();
    }

    /**
     * Move available stock between two enabled warehouses.
     *
     * @return array{reference: string, qty: float, from_warehouse_id: int, to_warehouse_id: int}
     */
    public function transfer(Product $product, int $fromWarehouseId, int $toWarehouseId, float $qty, ?string $note = null): array
    {
        $from = Warehouse::query()->whereKey($fromWarehouseId)->where('is_enabled', true)->first();
        $to = Warehouse::query()->whereKey($toWarehouseId)->where('is_enabled', true)->first();
        if (! $from) {
            throw ValidationException::withMessages([
                'from_warehouse_id' => 'Choose an enabled source warehouse.',
            ]);
        }
        if (! $to) {
            throw ValidationException::withMessages([
                'to_warehouse_id' => 'Choose an enabled destination warehouse.',
            ]);
        }

        $reference = 'transfer:'.$product->id.':'.now()->timestamp;
        if ($note) {
            $reference .= ':'.substr(md5($note), 0, 8);
        }

        return $this->inventory->transfer($product, $from, $to, $qty, $reference);
    }

    /**
     * @param  array<int>  $productIds
     * @return array{updated: int, product_ids: list<int>}
     */
    public function bulkAdjust(array $productIds, int $delta, ?string $note = null, ?int $warehouseId = null): array
    {
        if ($delta === 0) {
            throw ValidationException::withMessages(['delta' => 'Stock change must be non-zero.']);
        }

        $owned = app(CatalogInventoryListService::class)->existingProductIds($productIds);
        if ($owned === []) {
            abort(422, 'No matching products were found.');
        }

        $updated = [];
        foreach ($owned as $productId) {
            $product = Product::query()->find($productId);
            if (! $product) {
                continue;
            }
            $this->adjust($product, $delta, $note, $warehouseId);
            $updated[] = $productId;
        }

        return [
            'updated' => count($updated),
            'product_ids' => $updated,
        ];
    }

    protected function resolveWarehouse(?int $warehouseId): ?Warehouse
    {
        if (! $warehouseId) {
            return null;
        }

        $warehouse = Warehouse::query()->whereKey($warehouseId)->where('is_enabled', true)->first();
        if (! $warehouse) {
            throw ValidationException::withMessages([
                'warehouse_id' => 'Choose an enabled warehouse.',
            ]);
        }

        return $warehouse;
    }
}

