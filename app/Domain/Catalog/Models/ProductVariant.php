<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\ProductVariantObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[ObservedBy([ProductVariantObserver::class])]
#[Fillable([
    'product_id', 'sku', 'barcode', 'price', 'sale_price', 'sale_price_type', 'cost_price',
    'stock_qty', 'reserved_qty', 'manage_stock', 'stock_status', 'allow_backorder',
    'weight', 'length', 'width', 'height', 'cover_path', 'cover_image_id',
    'is_enabled', 'is_default', 'position', 'combination_key',
])]
class ProductVariant extends Model
{
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'weight' => 'decimal:3',
            'length' => 'decimal:3',
            'width' => 'decimal:3',
            'height' => 'decimal:3',
            'manage_stock' => 'boolean',
            'allow_backorder' => 'boolean',
            'is_enabled' => 'boolean',
            'is_default' => 'boolean',
            'position' => 'integer',
            'stock_qty' => 'decimal:3',
            'reserved_qty' => 'decimal:3',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function coverImage(): BelongsTo
    {
        return $this->belongsTo(ProductImage::class, 'cover_image_id');
    }

    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(AttributeValue::class, 'product_variant_values');
    }

    public function availableStock(): float
    {
        return max(0, (float) $this->stock_qty - (float) ($this->reserved_qty ?? 0));
    }

    public function inStockFor(float $qty): bool
    {
        if (! $this->is_enabled) {
            return false;
        }
        if (! $this->manage_stock) {
            return true;
        }
        if ($this->allow_backorder || $this->stock_status === 'on_backorder') {
            return true;
        }
        if ($this->stock_status === 'out_of_stock' && $this->availableStock() <= 0) {
            return false;
        }

        return $this->availableStock() >= $qty;
    }

    public function sellingPrice(): float
    {
        $regular = max(0, (float) ($this->price ?? 0));
        if (! \App\Domain\Settings\PricingSettings::salePricing()) {
            return $regular;
        }
        if ($this->sale_price === null || $this->sale_price === '') {
            return $regular;
        }

        $this->loadMissing('product');
        $parent = $this->product;
        if ($parent && ! $parent->saleWindowOpen()) {
            return $regular;
        }

        if ($this->sale_price_type === 'percent') {
            $percent = max(0, min(100, (float) $this->sale_price));

            return max(0, \App\Domain\Settings\PricingSettings::roundMoney($regular * (1 - ($percent / 100))));
        }

        return max(0, min($regular, \App\Domain\Settings\PricingSettings::roundMoney((float) $this->sale_price)));
    }

    /** Human-readable attribute labels joined for order lines / UI. */
    public function attributeLabel(string $separator = ' / '): string
    {
        $this->loadMissing(['attributeValues.translations', 'attributeValues.attribute']);

        $labels = $this->attributeValues
            ->sortBy(fn (AttributeValue $value) => $value->attribute?->position ?? 0)
            ->map(fn (AttributeValue $value) => $value->translation()?->value ?: $value->code)
            ->filter()
            ->values()
            ->all();

        return implode($separator, $labels);
    }
}
