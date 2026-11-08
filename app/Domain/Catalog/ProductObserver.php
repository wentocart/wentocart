<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\CustomerStockAlertService;
use App\Domain\Catalog\Services\PriceHistoryRecorder;
use App\Domain\Catalog\Services\StorefrontCatalogCache;
use App\Domain\Ops\CommerceTopics;
use App\Domain\Ops\WebhookPayload;
use Illuminate\Support\Facades\DB;

class ProductObserver
{
    /** @var list<string> */
    protected array $availabilityFields = [
        'stock_qty',
        'stock_status',
        'reserved_qty',
        'manage_stock',
        'allow_backorder',
    ];

    /** @var list<string> */
    protected array $priceFields = [
        'price',
        'compare_price',
        'sale_price',
        'sale_price_type',
        'sale_starts_at',
        'sale_ends_at',
        'is_on_sale',
    ];

    public function created(Product $product): void
    {
        StorefrontCatalogCache::bump();
        CommerceTopics::notify('product.created', WebhookPayload::product($product));
        app(PriceHistoryRecorder::class)->record($product);
    }

    public function updated(Product $product): void
    {
        StorefrontCatalogCache::bump();
        if ($product->wasChanged($this->priceFields)) {
            app(PriceHistoryRecorder::class)->record($product);
        }
        if ($product->wasChanged(['stock_qty', 'stock_status', 'reserved_qty'])) {
            CommerceTopics::notify('stock.updated', WebhookPayload::product($product));
        }
        CommerceTopics::notify('product.updated', WebhookPayload::product($product));

        if (! $product->wasChanged($this->availabilityFields)) {
            return;
        }

        $alerts = app(CustomerStockAlertService::class);
        if (! $alerts->enabled()) {
            return;
        }

        $before = new Product;
        $before->forceFill($product->getOriginal());
        $wasAvailable = $alerts->targetIsInStock($before, null);
        $isAvailable = $alerts->targetIsInStock($product, null);
        if ($wasAvailable || ! $isAvailable) {
            return;
        }

        $productId = (int) $product->id;
        DB::afterCommit(function () use ($productId, $wasAvailable) {
            $fresh = Product::query()->find($productId);
            if (! $fresh) {
                return;
            }
            app(CustomerStockAlertService::class)->handleAvailabilityTransition($fresh, $wasAvailable, null);
        });
    }

    public function deleted(Product $product): void
    {
        StorefrontCatalogCache::bump();
        CommerceTopics::notify('product.deleted', [
            'id' => (int) $product->id,
            'sku' => (string) $product->sku,
        ]);
    }
}
