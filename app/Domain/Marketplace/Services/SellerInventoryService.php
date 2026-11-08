<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Catalog\Models\InventoryMovement;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerOrder;
use App\Domain\Marketplace\Models\SellerProduct;
use App\Domain\Marketplace\Events\MarketplaceEvent;
use App\Domain\Marketplace\Services\MarketplaceLifecycleBroker;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 13 — seller-scoped inventory operations.
 *
 * Seller ownership is stamped on movements via product.seller_id.
 * Suppliers never write inventory_movements through this service.
 */
class SellerInventoryService
{
    public function __construct(protected InventoryService $inventory) {}

    public function assertOwnsProduct(Seller $seller, Product $product): Product
    {
        if ((int) $product->seller_id !== (int) $seller->id) {
            abort(403, 'This product does not belong to this seller.');
        }

        return $product;
    }

    /**
     * Seller stock adjustment (positive = restock, negative = remove).
     */
    public function adjust(Seller $seller, Product $product, int $delta, ?string $note = null): Product
    {
        $this->assertOwnsProduct($seller, $product);
        if ($delta === 0) {
            return $product->fresh();
        }

        $beforeQty = (int) $product->stock_qty;
        $reference = 'seller_adjust:'.$seller->id.':'.$product->id.':'.now()->timestamp;
        if ($note) {
            $reference .= ':'.substr(md5($note), 0, 8);
        }

        if ($delta > 0) {
            $this->inventory->restock($product, $delta, InventoryMovement::ACTION_ADJUSTMENT, $reference);
        } else {
            $this->inventory->deduct($product, abs($delta), InventoryMovement::ACTION_ADJUSTMENT, $reference);
        }

        $fresh = $product->fresh();
        app(MarketplaceLifecycleBroker::class)->dispatch(MarketplaceEvent::inventoryAdjusted(
            $seller,
            (int) $fresh->id,
            $delta,
            $beforeQty,
            (int) $fresh->stock_qty,
            $note,
        ));

        return $fresh;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function movements(Seller $seller, Product $product, int $limit = 50): array
    {
        $this->assertOwnsProduct($seller, $product);

        return InventoryMovement::query()
            ->where('product_id', $product->id)
            ->where(function ($query) use ($seller) {
                $query->where('seller_id', $seller->id)
                    ->orWhereNull('seller_id'); // legacy rows before backfill
            })
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (InventoryMovement $row) => [
                'id' => (int) $row->id,
                'action' => (string) $row->action,
                'qty' => (int) $row->qty,
                'reference' => $row->reference,
                'seller_id' => $row->seller_id ? (int) $row->seller_id : null,
                'created_at' => $row->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Restore stock for a refunded/returned seller order (idempotent).
     */
    public function restoreSellerOrderStock(SellerOrder $sellerOrder, string $reason = InventoryMovement::ACTION_RETURN): void
    {
        $sellerOrder->loadMissing(['items', 'seller']);
        $reference = $reason.':seller_order:'.$sellerOrder->id;

        $already = InventoryMovement::query()
            ->where('reference', $reference)
            ->exists();
        if ($already) {
            return;
        }

        DB::transaction(function () use ($sellerOrder, $reason, $reference) {
            foreach ($sellerOrder->items as $item) {
                if (! $item->product_id) {
                    continue;
                }
                $product = Product::query()->whereKey($item->product_id)->lockForUpdate()->first();
                if (! $product || ! $product->manage_stock) {
                    continue;
                }
                if ($sellerOrder->seller_id && (int) $product->seller_id !== (int) $sellerOrder->seller_id) {
                    // Never restock another seller's / platform product from this slice.
                    continue;
                }
                $this->inventory->restock($product, (int) $item->qty, $reason, $reference);
            }
        });
    }

    public function summary(Seller $seller, Product $product): array
    {
        $this->assertOwnsProduct($seller, $product);

        return [
            'product_id' => (int) $product->id,
            'stock_qty' => (int) $product->stock_qty,
            'reserved_qty' => (int) ($product->reserved_qty ?? 0),
            'available' => $product->availableStock(),
            'manage_stock' => (bool) $product->manage_stock,
            'stock_status' => (string) $product->stock_status,
        ];
    }

    /**
     * Seller portal inventory list — owned catalog products only.
     *
     * @return list<array<string, mixed>>
     */
    public function catalog(Seller $seller): array
    {
        $list = app(SellerInventoryListService::class);

        return $list->query($seller, [
            'name' => '',
            'sku' => '',
            'supplier' => '',
            'id_min' => null,
            'id_max' => null,
            'sort' => 'id',
            'dir' => 'desc',
            'page' => 1,
            'per_page' => 1000,
        ])->get()->map(fn (SellerProduct $row) => $list->presentRow($row))->values()->all();
    }

    /**
     * @param  array<int>  $productIds
     * @return array{updated: int, product_ids: list<int>}
     */
    public function bulkAdjust(Seller $seller, array $productIds, int $delta, ?string $note = null): array
    {
        if ($delta === 0) {
            throw ValidationException::withMessages(['delta' => 'Stock change must be non-zero.']);
        }

        $owned = app(SellerInventoryListService::class)->ownedProductIds($seller, $productIds);
        if ($owned === []) {
            abort(422, 'No matching products were found.');
        }

        $updated = [];
        foreach ($owned as $productId) {
            $product = Product::query()->find($productId);
            if (! $product) {
                continue;
            }
            $this->adjust($seller, $product, $delta, $note);
            $updated[] = $productId;
        }

        return [
            'updated' => count($updated),
            'product_ids' => $updated,
        ];
    }
}
