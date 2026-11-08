<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Support\CatalogMediaUrl;
use App\Domain\Catalog\Support\DefaultImage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['product_id', 'path', 'alt', 'position'])]
class ProductImage extends Model
{
    protected $appends = ['url'];

    protected static function booted(): void
    {
        static::deleting(function (ProductImage $image): void {
            ProductVariant::query()
                ->where(function ($query) use ($image) {
                    $query->where('cover_image_id', $image->id)
                        ->orWhere(function ($inner) use ($image) {
                            $inner->where('product_id', $image->product_id)
                                ->where('cover_path', $image->path);
                        });
                })
                ->update([
                    'cover_image_id' => null,
                    'cover_path' => null,
                ]);
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class, 'cover_image_id');
    }

    public function getUrlAttribute(): string
    {
        return CatalogMediaUrl::url($this->path, DefaultImage::MD);
    }

    /**
     * Resolve descriptive alt text, falling back to product name and SKU if unset.
     */
    public function altText(?string $fallback = null): string
    {
        if (filled($this->alt)) {
            return (string) $this->alt;
        }

        if ($fallback !== null && $fallback !== '') {
            return $fallback;
        }

        $product = $this->relationLoaded('product') ? $this->product : null;
        $name = $product ? (string) ($product->name ?? '') : '';
        $sku = $product ? (string) ($product->sku ?? '') : '';

        return trim(($name !== '' ? $name : 'Product').($sku !== '' ? ' - '.$sku : ''));
    }
}
