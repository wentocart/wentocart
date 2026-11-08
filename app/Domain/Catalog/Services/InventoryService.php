<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\InventoryMovement;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\Warehouse;
use App\Domain\Catalog\Models\WarehouseStock;
use App\Domain\Settings\InventorySettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Core inventory movements (Phase 13: seller_id stamped for seller-owned products).
 *
 * Supplier inventory is intentionally separate — suppliers never write these rows.
 * Warehouses are platform-owned locations; sellers do not manage warehouse_stocks.
 */
class InventoryService
{
    public function defaultWarehouse(): Warehouse
    {
        $warehouse = Warehouse::withoutGlobalScope('shop')
            ->where('is_default', true)
            ->where('is_enabled', true)
            ->first();
        if ($warehouse) {
            return $warehouse;
        }

        $promote = Warehouse::withoutGlobalScope('shop')->where('is_enabled', true)->orderBy('id')->first();
        if ($promote) {
            return DB::transaction(function () use ($promote) {
                Warehouse::withoutGlobalScope('shop')->where('id', '!=', $promote->id)->update(['is_default' => false]);
                $promote->update(['is_default' => true, 'is_enabled' => true]);

                return $promote->fresh();
            });
        }

        $disabledDefault = Warehouse::withoutGlobalScope('shop')->where('is_default', true)->first();
        if ($disabledDefault) {
            $disabledDefault->update(['is_enabled' => true]);

            return $disabledDefault->fresh();
        }

        return Warehouse::withoutGlobalScope('shop')->create([
            'name' => 'Default Warehouse',
            'code' => 'DEFAULT',
            'is_default' => true,
            'is_enabled' => true,
        ]);
    }

    public function ensureExactlyOneDefault(?Warehouse $preferred = null): Warehouse
    {
        return DB::transaction(function () use ($preferred) {
            if ($preferred && $preferred->is_default) {
                $preferred->update(['is_enabled' => true, 'is_default' => true]);
                Warehouse::withoutGlobalScope('shop')->where('id', '!=', $preferred->id)->update(['is_default' => false]);

                return $preferred->fresh();
            }

            $defaults = Warehouse::withoutGlobalScope('shop')->where('is_default', true)->lockForUpdate()->get();
            if ($defaults->count() === 1 && $defaults->first()->is_enabled) {
                return $defaults->first();
            }

            if ($defaults->count() > 1) {
                $keep = $defaults->first(fn (Warehouse $row) => $row->is_enabled) ?: $defaults->first();
                Warehouse::withoutGlobalScope('shop')->where('id', '!=', $keep->id)->update(['is_default' => false]);
                $keep->update(['is_default' => true, 'is_enabled' => true]);

                return $keep->fresh();
            }

            return $this->defaultWarehouse();
        });
    }

    public function enabledWarehouses()
    {
        return Warehouse::withoutGlobalScope('shop')->where('is_enabled', true)->orderBy('name')->orderBy('id')->get();
    }

    /**
     * Resolve the warehouse for an order line. Prefers the stamped warehouse_id.
     */
    public function warehouseForOrderItem(?int $warehouseId, Product $product, float $qty = 1, ?string $reference = null): Warehouse
    {
        if ($warehouseId) {
            $existing = Warehouse::withoutGlobalScope('shop')->find($warehouseId);
            if ($existing) {
                return $existing;
            }
        }

        return $this->pickWarehouse($product, $qty, $reference);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function syncFromForm(Product $product, array $data, float $previousQty): void
    {
        if (! array_key_exists('warehouse_stocks', $data)) {
            return;
        }

        $rows = is_array($data['warehouse_stocks']) ? $data['warehouse_stocks'] : [];
        if ($rows === []) {
            return;
        }
        $submittedIds = [];

        DB::transaction(function () use ($product, $rows, &$submittedIds) {
            $before = WarehouseStock::query()
                ->where('product_id', $product->id)
                ->get()
                ->keyBy('warehouse_id');

            foreach ($rows as $row) {
                if (empty($row['warehouse_id'])) {
                    continue;
                }
                $warehouseId = (int) $row['warehouse_id'];
                $submittedIds[] = $warehouseId;
                $nextQty = max(0, (float) ($row['qty'] ?? 0));
                $nextReserved = max(0, (float) ($row['reserved_qty'] ?? 0));
                $previous = $before->get($warehouseId);
                $prevQty = $previous ? (float) $previous->qty : 0.0;

                WarehouseStock::query()->updateOrCreate(
                    [
                        'product_id' => $product->id,
                        'warehouse_id' => $warehouseId,
                    ],
                    [
                        'qty' => $nextQty,
                        'reserved_qty' => $nextReserved,
                    ]
                );

                $delta = round($nextQty - $prevQty, 3);
                if ($delta != 0.0) {
                    $this->record(
                        $product,
                        InventoryMovement::ACTION_ADJUSTMENT,
                        $delta,
                        'Manual',
                        $warehouseId
                    );
                }
            }

            $omit = WarehouseStock::query()
                ->where('product_id', $product->id)
                ->when($submittedIds !== [], fn ($q) => $q->whereNotIn('warehouse_id', $submittedIds))
                ->when($submittedIds === [], fn ($q) => $q)
                ->get();

            foreach ($omit as $stock) {
                $prevQty = (float) $stock->qty;
                if ($prevQty != 0.0 || (float) $stock->reserved_qty != 0.0) {
                    if ($prevQty != 0.0) {
                        $this->record(
                            $product,
                            InventoryMovement::ACTION_ADJUSTMENT,
                            round(0 - $prevQty, 3),
                            'Manual',
                            (int) $stock->warehouse_id
                        );
                    }
                    $stock->update(['qty' => 0, 'reserved_qty' => 0]);
                }
            }

            $product->update([
                'stock_qty' => (float) WarehouseStock::query()->where('product_id', $product->id)->sum('qty'),
                'reserved_qty' => (float) WarehouseStock::query()->where('product_id', $product->id)->sum('reserved_qty'),
            ]);
        });
    }

    /**
     * Warehouse used for fulfillment. When inventory.scope is warehouse,
     * pick the enabled location with enough available qty (most stock first).
     * Does not fall back to an understocked location when stock checks are required.
     */
    public function pickWarehouse(Product $product, float $qty = 1, ?string $reference = null): Warehouse
    {
        if ($reference) {
            $prior = InventoryMovement::query()
                ->where('product_id', $product->id)
                ->where('reference', $reference)
                ->whereNotNull('warehouse_id')
                ->latest('id')
                ->first();
            if ($prior?->warehouse_id) {
                $existing = Warehouse::withoutGlobalScope('shop')->find($prior->warehouse_id);
                if ($existing) {
                    return $existing;
                }
            }
        }

        if (InventorySettings::scope() !== 'warehouse') {
            return $this->defaultWarehouse();
        }

        $rows = WarehouseStock::query()
            ->where('product_id', $product->id)
            ->whereHas('warehouse', fn ($q) => $q->withoutGlobalScope('shop')->where('is_enabled', true))
            ->get()
            ->sortByDesc(fn (WarehouseStock $row) => $row->available());

        $fit = $rows->first(fn (WarehouseStock $row) => $row->available() >= $qty);
        if ($fit) {
            $warehouse = Warehouse::withoutGlobalScope('shop')->find($fit->warehouse_id);
            if ($warehouse) {
                return $warehouse;
            }
        }

        if ($rows->isEmpty()) {
            return $this->defaultWarehouse();
        }

        // Understocked locations exist but none can fulfill — still return the best candidate
        // for allow_negative / backorder paths; callers must enforce availability.
        $chosen = $rows->first();
        $warehouse = $chosen ? Warehouse::withoutGlobalScope('shop')->find($chosen->warehouse_id) : null;

        return $warehouse ?: $this->defaultWarehouse();
    }

    public function deduct(
        Product $product,
        float $qty,
        string $action,
        string $reference,
        ?Warehouse $warehouse = null,
        ?int $combinationId = null,
        ?array $componentSelection = null,
    ): void {
        if ($qty <= 0 || ! InventorySettings::enabled()) {
            return;
        }

        if ($product->isComposite() && ($product->inventory_mode ?: 'derived') === 'derived') {
            foreach ($this->resolveCompositeMoves($product, $componentSelection) as $move) {
                $this->deduct(
                    $move['product'],
                    $qty * $move['qty'],
                    $action,
                    $reference.'#comp:'.$move['product']->id,
                    $warehouse
                );
            }

            return;
        }

        $action = match ($action) {
            'order', 'order_edit', InventoryMovement::ACTION_ORDER => InventoryMovement::ACTION_SALE,
            InventoryMovement::ACTION_ADJUSTMENT, 'removed' => InventoryMovement::ACTION_ADJUSTMENT,
            default => InventoryMovement::ACTION_SALE,
        };

        if ($combinationId && $product->isCombination()) {
            $this->deductCombination($product, $combinationId, $qty, $action, $reference, $warehouse);

            return;
        }

        if (! $product->manage_stock) {
            return;
        }

        DB::transaction(function () use ($product, $qty, $action, $reference, $warehouse) {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->first();
            if (! $locked || ! $locked->manage_stock) {
                return;
            }

            if ($this->alreadyRecorded($locked->id, $action, $reference, -$qty)) {
                return;
            }

            $warehouse = $warehouse ?: $this->pickWarehouse($locked, $qty, $reference);
            $stock = $this->lockLocationStock($locked, $warehouse);

            $allowOversell = InventorySettings::allowNegative() || $locked->allow_backorder;
            if (! $allowOversell) {
                if ($locked->availableStock() < $qty) {
                    throw ValidationException::withMessages([
                        'stock' => 'Not enough stock for '.($locked->sku ?: 'product #'.$locked->id).'.',
                    ]);
                }
                if ($stock->available() < $qty) {
                    throw ValidationException::withMessages([
                        'stock' => 'Not enough stock at warehouse '.$warehouse->code.' for '.($locked->sku ?: 'product #'.$locked->id).'.',
                    ]);
                }
            }

            $locked->decrement('stock_qty', $qty);
            $stock->update(['qty' => (float) $stock->qty - $qty]);
            $this->record($locked, $action, -$qty, $reference, $warehouse->id);

            $fresh = $locked->fresh();
            if ((float) $fresh->stock_qty <= 0 && ! $fresh->allow_backorder) {
                $fresh->update(['stock_status' => 'out_of_stock']);
            }

            $productId = (int) $locked->id;
            DB::afterCommit(function () use ($productId) {
                $product = Product::query()->find($productId);
                if ($product) {
                    app(LowStockNotifier::class)->bellIfLow($product);
                }
            });
        });
    }

    protected function deductCombination(
        Product $product,
        int $combinationId,
        float $qty,
        string $action,
        string $reference,
        ?Warehouse $warehouse,
    ): void {
        DB::transaction(function () use ($product, $combinationId, $qty, $action, $reference, $warehouse) {
            $lockedProduct = Product::query()->whereKey($product->id)->lockForUpdate()->first();
            if (! $lockedProduct) {
                return;
            }

            $comboRef = $reference.'#combo:'.$combinationId;
            if ($this->alreadyRecorded($lockedProduct->id, $action, $comboRef, -$qty)) {
                return;
            }

            $variant = ProductVariant::query()
                ->where('product_id', $lockedProduct->id)
                ->whereKey($combinationId)
                ->lockForUpdate()
                ->first();

            if (! $variant || ! $variant->is_enabled) {
                throw ValidationException::withMessages([
                    'combination_id' => 'That combination is not available.',
                ]);
            }

            if ($variant->manage_stock) {
                $allowOversell = InventorySettings::allowNegative() || $variant->allow_backorder;
                if (! $allowOversell && $variant->availableStock() < $qty) {
                    throw ValidationException::withMessages([
                        'stock' => 'Not enough stock for combination '.($variant->sku ?: '#'.$variant->id).'.',
                    ]);
                }
                $variant->decrement('stock_qty', $qty);
                $freshVariant = $variant->fresh();
                if ((float) $freshVariant->stock_qty <= 0 && ! $freshVariant->allow_backorder) {
                    $freshVariant->update(['stock_status' => 'out_of_stock']);
                } elseif ((float) $freshVariant->stock_qty > 0 && $freshVariant->stock_status === 'out_of_stock') {
                    $freshVariant->update(['stock_status' => 'in_stock']);
                }
            }

            if ($lockedProduct->manage_stock || $variant->manage_stock) {
                $warehouse = $warehouse ?: $this->pickWarehouse($lockedProduct, $qty, $reference);
                $stock = $this->lockLocationStock($lockedProduct, $warehouse);
                if ($lockedProduct->manage_stock) {
                    $lockedProduct->decrement('stock_qty', $qty);
                }
                $stock->update(['qty' => (float) $stock->qty - $qty]);
                $this->record($lockedProduct, $action, -$qty, $comboRef, $warehouse->id);
            }

            app(CombinationService::class)->syncParentInventory($lockedProduct->fresh());

            $productId = (int) $lockedProduct->id;
            DB::afterCommit(function () use ($productId) {
                $product = Product::query()->find($productId);
                if ($product) {
                    app(LowStockNotifier::class)->bellIfLow($product);
                }
            });
        });
    }

    public function restock(
        Product $product,
        float $qty,
        string $action,
        string $reference,
        ?Warehouse $warehouse = null,
        ?int $combinationId = null,
        ?array $componentSelection = null,
    ): bool {
        if ($qty <= 0 || ! InventorySettings::enabled()) {
            return false;
        }

        if ($product->isComposite() && ($product->inventory_mode ?: 'derived') === 'derived') {
            $applied = false;
            foreach ($this->resolveCompositeMoves($product, $componentSelection) as $move) {
                $applied = $this->restock(
                    $move['product'],
                    $qty * $move['qty'],
                    $action,
                    $reference.'#comp:'.$move['product']->id,
                    $warehouse
                ) || $applied;
            }

            return $applied;
        }

        $normalized = InventoryMovement::normalizeAction($action);
        if (! in_array($normalized, [
            InventoryMovement::ACTION_RESTOCK,
            InventoryMovement::ACTION_RETURN,
            InventoryMovement::ACTION_ADJUSTMENT,
            InventoryMovement::ACTION_RELEASE,
            InventoryMovement::ACTION_PURCHASE,
        ], true)) {
            $normalized = InventoryMovement::ACTION_RESTOCK;
        }

        if ($combinationId && $product->isCombination()) {
            return $this->restockCombination($product, $combinationId, $qty, $normalized, $reference, $warehouse);
        }

        if (! $product->manage_stock) {
            return false;
        }

        $applied = false;
        DB::transaction(function () use ($product, $qty, $normalized, $reference, $warehouse, &$applied) {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->first();
            if (! $locked || ! $locked->manage_stock) {
                return;
            }

            if ($this->alreadyRecorded($locked->id, $normalized, $reference, $qty)) {
                return;
            }

            $warehouse = $warehouse ?: $this->pickWarehouse($locked, $qty, $reference);
            $stock = $this->lockLocationStock($locked, $warehouse, false);

            $locked->increment('stock_qty', $qty);
            $stock->update(['qty' => (float) $stock->qty + $qty]);
            $this->record($locked, $normalized, $qty, $reference, $warehouse->id);
            $applied = true;

            $fresh = $locked->fresh();
            if ((float) $fresh->stock_qty > 0 && $fresh->stock_status === 'out_of_stock') {
                $fresh->update(['stock_status' => 'in_stock']);
            }
        });

        return $applied;
    }

    protected function restockCombination(
        Product $product,
        int $combinationId,
        float $qty,
        string $action,
        string $reference,
        ?Warehouse $warehouse,
    ): bool {
        $applied = false;
        DB::transaction(function () use ($product, $combinationId, $qty, $action, $reference, $warehouse, &$applied) {
            $lockedProduct = Product::query()->whereKey($product->id)->lockForUpdate()->first();
            if (! $lockedProduct) {
                return;
            }

            $comboRef = $reference.'#combo:'.$combinationId;
            if ($this->alreadyRecorded($lockedProduct->id, $action, $comboRef, $qty)) {
                return;
            }

            $variant = ProductVariant::query()
                ->where('product_id', $lockedProduct->id)
                ->whereKey($combinationId)
                ->lockForUpdate()
                ->first();
            if (! $variant) {
                return;
            }

            if ($variant->manage_stock) {
                $variant->increment('stock_qty', $qty);
                $freshVariant = $variant->fresh();
                if ((float) $freshVariant->stock_qty > 0 && $freshVariant->stock_status === 'out_of_stock') {
                    $freshVariant->update(['stock_status' => 'in_stock']);
                }
            }

            if ($lockedProduct->manage_stock || $variant->manage_stock) {
                $warehouse = $warehouse ?: $this->pickWarehouse($lockedProduct, $qty, $reference);
                $stock = $this->lockLocationStock($lockedProduct, $warehouse, false);
                if ($lockedProduct->manage_stock) {
                    $lockedProduct->increment('stock_qty', $qty);
                }
                $stock->update(['qty' => (float) $stock->qty + $qty]);
                $this->record($lockedProduct, $action, $qty, $comboRef, $warehouse->id);
                $applied = true;
            }

            app(CombinationService::class)->syncParentInventory($lockedProduct->fresh());
        });

        return $applied;
    }

    /**
     * Soft-hold stock via reserved_qty (checkout currently deducts on sale; this is for future/cart holds).
     */
    public function reserve(
        Product $product,
        float $qty,
        string $reference,
        ?Warehouse $warehouse = null,
        ?int $combinationId = null,
        ?array $componentSelection = null,
    ): void {
        if (! InventorySettings::enabled() || $qty <= 0) {
            return;
        }

        if ($product->isComposite() && ($product->inventory_mode ?: 'derived') === 'derived') {
            foreach ($this->resolveCompositeMoves($product, $componentSelection) as $move) {
                $this->reserve(
                    $move['product'],
                    $qty * $move['qty'],
                    $reference.'#comp:'.$move['product']->id,
                    $warehouse
                );
            }

            return;
        }

        if ($combinationId && $product->isCombination()) {
            $this->reserveCombination($product, $combinationId, $qty, $reference, $warehouse);

            return;
        }

        if (! $product->manage_stock) {
            return;
        }

        DB::transaction(function () use ($product, $qty, $reference, $warehouse) {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            if ($this->alreadyRecorded($locked->id, InventoryMovement::ACTION_RESERVATION, $reference, $qty)) {
                return;
            }
            if ($locked->availableStock() < $qty && ! $locked->allow_backorder) {
                throw ValidationException::withMessages([
                    'stock' => 'Not enough stock to reserve for '.($locked->sku ?: 'product #'.$locked->id).'.',
                ]);
            }

            $warehouse = $warehouse ?: $this->pickWarehouse($locked, $qty, $reference);
            $stock = $this->lockLocationStock($locked, $warehouse, false);

            $locked->increment('reserved_qty', $qty);
            $stock->update(['reserved_qty' => (float) $stock->reserved_qty + $qty]);
            $this->record($locked, InventoryMovement::ACTION_RESERVATION, $qty, $reference, $warehouse->id);
        });
    }

    /**
     * @param  list<array{product_id?: int, qty?: int|float}>|null  $componentSelection
     * @return list<array{product: Product, qty: float}>
     */
    protected function resolveCompositeMoves(Product $product, ?array $componentSelection = null): array
    {
        $product->loadMissing('components.child');
        $moves = [];

        if (is_array($componentSelection) && $componentSelection !== []) {
            $byId = [];
            foreach ($product->components as $component) {
                $byId[(int) $component->child_product_id] = $component->child;
            }
            foreach ($componentSelection as $row) {
                $childId = (int) ($row['product_id'] ?? 0);
                $childQty = max(1, (float) ($row['qty'] ?? 1));
                $child = $byId[$childId] ?? Product::query()->find($childId);
                if (! $child) {
                    continue;
                }
                $moves[] = ['product' => $child, 'qty' => $childQty];
            }

            return $moves;
        }

        foreach ($product->components as $component) {
            $child = $component->child;
            if (! $child) {
                continue;
            }
            $moves[] = [
                'product' => $child,
                'qty' => (float) max(1, (int) $component->quantity),
            ];
        }

        return $moves;
    }

    protected function reserveCombination(
        Product $product,
        int $combinationId,
        float $qty,
        string $reference,
        ?Warehouse $warehouse,
    ): void {
        DB::transaction(function () use ($product, $combinationId, $qty, $reference, $warehouse) {
            $lockedProduct = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $comboRef = $reference.'#combo:'.$combinationId;
            if ($this->alreadyRecorded($lockedProduct->id, InventoryMovement::ACTION_RESERVATION, $comboRef, $qty)) {
                return;
            }

            $variant = ProductVariant::query()
                ->where('product_id', $lockedProduct->id)
                ->whereKey($combinationId)
                ->lockForUpdate()
                ->first();
            if (! $variant || ! $variant->is_enabled) {
                throw ValidationException::withMessages([
                    'combination_id' => 'That combination is not available.',
                ]);
            }

            if ($variant->manage_stock && ! $variant->inStockFor($qty) && ! InventorySettings::allowNegative()) {
                throw ValidationException::withMessages([
                    'stock' => 'Not enough stock to reserve for combination '.($variant->sku ?: '#'.$variant->id).'.',
                ]);
            }

            if ($variant->manage_stock) {
                $variant->increment('reserved_qty', $qty);
            }

            if ($lockedProduct->manage_stock || $variant->manage_stock) {
                $warehouse = $warehouse ?: $this->pickWarehouse($lockedProduct, $qty, $reference);
                $stock = $this->lockLocationStock($lockedProduct, $warehouse, false);
                if ($lockedProduct->manage_stock) {
                    $lockedProduct->increment('reserved_qty', $qty);
                }
                $stock->update(['reserved_qty' => (float) $stock->reserved_qty + $qty]);
                $this->record($lockedProduct, InventoryMovement::ACTION_RESERVATION, $qty, $comboRef, $warehouse->id);
            }
        });
    }

    public function release(
        Product $product,
        float $qty,
        string $reference,
        ?Warehouse $warehouse = null,
        ?int $combinationId = null,
        ?array $componentSelection = null,
    ): void {
        if (! InventorySettings::enabled() || $qty <= 0) {
            return;
        }

        if ($product->isComposite() && ($product->inventory_mode ?: 'derived') === 'derived') {
            foreach ($this->resolveCompositeMoves($product, $componentSelection) as $move) {
                $this->release(
                    $move['product'],
                    $qty * $move['qty'],
                    $reference.'#comp:'.$move['product']->id,
                    $warehouse
                );
            }

            return;
        }

        if ($combinationId && $product->isCombination()) {
            $this->releaseCombination($product, $combinationId, $qty, $reference, $warehouse);

            return;
        }

        if (! $product->manage_stock) {
            return;
        }

        DB::transaction(function () use ($product, $qty, $reference, $warehouse) {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            if ($this->alreadyRecorded($locked->id, InventoryMovement::ACTION_RELEASE, $reference, $qty)) {
                return;
            }

            $release = min($qty, (float) $locked->reserved_qty);
            if ($release <= 0) {
                return;
            }

            $warehouse = $warehouse ?: $this->pickWarehouse($locked, $release, $reference);
            $stock = WarehouseStock::query()
                ->where('product_id', $locked->id)
                ->where('warehouse_id', $warehouse->id)
                ->lockForUpdate()
                ->first();

            $locked->decrement('reserved_qty', $release);
            if ($stock) {
                $stock->update(['reserved_qty' => max(0, (float) $stock->reserved_qty - $release)]);
            }
            $this->record($locked, InventoryMovement::ACTION_RELEASE, $release, $reference, $warehouse->id);
        });
    }

    protected function releaseCombination(
        Product $product,
        int $combinationId,
        float $qty,
        string $reference,
        ?Warehouse $warehouse,
    ): void {
        DB::transaction(function () use ($product, $combinationId, $qty, $reference, $warehouse) {
            $lockedProduct = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $comboRef = $reference.'#combo:'.$combinationId;
            if ($this->alreadyRecorded($lockedProduct->id, InventoryMovement::ACTION_RELEASE, $comboRef, $qty)) {
                return;
            }

            $variant = ProductVariant::query()
                ->where('product_id', $lockedProduct->id)
                ->whereKey($combinationId)
                ->lockForUpdate()
                ->first();
            if (! $variant) {
                return;
            }

            $releaseVariant = min($qty, (float) ($variant->reserved_qty ?? 0));
            if ($releaseVariant > 0 && $variant->manage_stock) {
                $variant->decrement('reserved_qty', $releaseVariant);
            }

            $releaseParent = $lockedProduct->manage_stock
                ? min($qty, (float) $lockedProduct->reserved_qty)
                : $releaseVariant;
            if ($releaseParent <= 0 && $releaseVariant <= 0) {
                return;
            }

            $release = max($releaseParent, $releaseVariant);
            $warehouse = $warehouse ?: $this->pickWarehouse($lockedProduct, $release, $reference);
            $stock = WarehouseStock::query()
                ->where('product_id', $lockedProduct->id)
                ->where('warehouse_id', $warehouse->id)
                ->lockForUpdate()
                ->first();
            if ($lockedProduct->manage_stock && $releaseParent > 0) {
                $lockedProduct->decrement('reserved_qty', $releaseParent);
            }
            if ($stock) {
                $stock->update(['reserved_qty' => max(0, (float) $stock->reserved_qty - $release)]);
            }
            $this->record($lockedProduct, InventoryMovement::ACTION_RELEASE, $release, $comboRef, $warehouse->id);
        });
    }

    /**
     * Move available stock from one warehouse to another. Product stock_qty sum stays unchanged.
     *
     * @return array{reference: string, qty: float, from_warehouse_id: int, to_warehouse_id: int}
     */
    public function transfer(
        Product $product,
        Warehouse $from,
        Warehouse $to,
        float $qty,
        ?string $reference = null,
    ): array {
        $qty = round(max(0, $qty), 3);
        if ($qty <= 0) {
            throw ValidationException::withMessages([
                'qty' => 'Transfer quantity must be greater than zero.',
            ]);
        }
        if ((int) $from->id === (int) $to->id) {
            throw ValidationException::withMessages([
                'to_warehouse_id' => 'Choose a different destination warehouse.',
            ]);
        }
        if (! $from->is_enabled || ! $to->is_enabled) {
            throw ValidationException::withMessages([
                'warehouse_id' => 'Both warehouses must be enabled.',
            ]);
        }
        if (! InventorySettings::enabled()) {
            throw ValidationException::withMessages([
                'stock' => 'Inventory tracking is disabled.',
            ]);
        }

        $reference = $reference ?: ('transfer:'.Str::lower((string) Str::ulid()));

        DB::transaction(function () use ($product, $from, $to, $qty, $reference) {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            if (! $locked->manage_stock) {
                throw ValidationException::withMessages([
                    'stock' => 'This product does not manage stock.',
                ]);
            }

            if ($this->alreadyRecorded($locked->id, InventoryMovement::ACTION_TRANSFER_OUT, $reference, -$qty)) {
                return;
            }

            $source = $this->lockLocationStock($locked, $from, false);
            $dest = $this->lockLocationStock($locked, $to, false);

            if ($source->available() < $qty) {
                throw ValidationException::withMessages([
                    'qty' => 'Not enough available stock at '.$from->code.' for '.($locked->sku ?: 'product #'.$locked->id).'.',
                ]);
            }

            $source->update(['qty' => round((float) $source->qty - $qty, 3)]);
            $dest->update(['qty' => round((float) $dest->qty + $qty, 3)]);

            $this->record($locked, InventoryMovement::ACTION_TRANSFER_OUT, -$qty, $reference, $from->id);
            $this->record($locked, InventoryMovement::ACTION_TRANSFER_IN, $qty, $reference, $to->id);

            $this->rollupProductStock($locked->fresh());
        });

        return [
            'reference' => $reference,
            'qty' => $qty,
            'from_warehouse_id' => (int) $from->id,
            'to_warehouse_id' => (int) $to->id,
        ];
    }

    public function setLocationQty(Product $product, Warehouse $warehouse, float $qty, float $reserved = 0): WarehouseStock
    {
        return WarehouseStock::query()->updateOrCreate(
            ['product_id' => $product->id, 'warehouse_id' => $warehouse->id],
            ['qty' => max(0, $qty), 'reserved_qty' => max(0, $reserved)]
        );
    }

    public function rollupProductStock(Product $product): void
    {
        $product->update([
            'stock_qty' => (float) WarehouseStock::query()->where('product_id', $product->id)->sum('qty'),
            'reserved_qty' => (float) WarehouseStock::query()->where('product_id', $product->id)->sum('reserved_qty'),
        ]);
    }

    public function record(Product $product, string $action, float $qty, ?string $reference = null, ?int $warehouseId = null): InventoryMovement
    {
        return InventoryMovement::query()->create([
            'product_id' => $product->id,
            'seller_id' => $product->seller_id ? (int) $product->seller_id : null,
            'warehouse_id' => $warehouseId,
            'action' => $action,
            'qty' => $qty,
            'reference' => $reference,
        ]);
    }

    public function history(Product $product, int $limit = 50)
    {
        return InventoryMovement::query()
            ->with('warehouse:id,name,code')
            ->where('product_id', $product->id)
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (InventoryMovement $row) => [
                'id' => $row->id,
                'date' => $row->created_at?->timezone(config('app.timezone'))->format('M j, Y'),
                'action' => $row->action,
                'qty' => $row->qty,
                'reference' => $row->reference,
                'seller_id' => $row->seller_id,
                'warehouse_id' => $row->warehouse_id,
                'warehouse_code' => $row->warehouse?->code,
                'warehouse_name' => $row->warehouse?->name,
            ]);
    }

    /**
     * Lock (or create) the location row. If the product has stock but no positive
     * location qty yet (legacy / seller create), seed this warehouse from product totals.
     */
    protected function lockLocationStock(Product $product, Warehouse $warehouse, bool $seedFromProduct = true): WarehouseStock
    {
        $stock = WarehouseStock::query()->firstOrCreate(
            ['product_id' => $product->id, 'warehouse_id' => $warehouse->id],
            ['qty' => 0, 'reserved_qty' => 0]
        );
        WarehouseStock::query()->whereKey($stock->id)->lockForUpdate()->first();
        $stock->refresh();

        if (! $seedFromProduct) {
            return $stock;
        }

        $hasPositiveLocation = WarehouseStock::query()
            ->where('product_id', $product->id)
            ->where(function ($q) {
                $q->where('qty', '>', 0)->orWhere('reserved_qty', '>', 0);
            })
            ->exists();

        if (! $hasPositiveLocation && ((float) $product->stock_qty > 0 || (float) $product->reserved_qty > 0)) {
            $stock->update([
                'qty' => (float) $product->stock_qty,
                'reserved_qty' => (float) $product->reserved_qty,
            ]);
            $stock->refresh();
        }

        return $stock;
    }

    protected function alreadyRecorded(int $productId, string $action, string $reference, float $qty): bool
    {
        if ($reference === '') {
            return false;
        }

        return InventoryMovement::query()
            ->where('product_id', $productId)
            ->where('action', $action)
            ->where('reference', $reference)
            ->where('qty', round($qty, 3))
            ->exists();
    }
}
