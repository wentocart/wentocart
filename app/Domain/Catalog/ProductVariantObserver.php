<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Services\CustomerStockAlertService;
use Illuminate\Support\Facades\DB;

class ProductVariantObserver
{
    /** @var list<string> */
    protected array $availabilityFields = [
        'stock_qty',
        'stock_status',
        'reserved_qty',
        'manage_stock',
        'allow_backorder',
    ];

    public function updated(ProductVariant $variant): void
    {
        if (! $variant->wasChanged($this->availabilityFields)) {
            return;
        }

        $alerts = app(CustomerStockAlertService::class);
        if (! $alerts->enabled()) {
            return;
        }

        $before = new ProductVariant;
        $before->forceFill($variant->getOriginal());
        $wasAvailable = $alerts->variantIsInStock($before);
        $isAvailable = $alerts->variantIsInStock($variant);
        if ($wasAvailable || ! $isAvailable) {
            return;
        }

        $productId = (int) $variant->product_id;
        $variantId = (int) $variant->id;
        DB::afterCommit(function () use ($productId, $variantId, $wasAvailable) {
            $product = Product::query()->find($productId);
            if (! $product) {
                return;
            }
            app(CustomerStockAlertService::class)->handleAvailabilityTransition($product, $wasAvailable, $variantId);
        });
    }
}
