<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Localization\Models\Language;
use App\Domain\Ops\EntityObserver;
use App\Domain\Store\Concerns\ShopLookupAssigned;
use App\Domain\Store\Models\ShopLookup;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'seller_id', 'email', 'phone', 'website', 'facebook_url', 'instagram_url', 'youtube_url', 'twitter_url',
    'linkedin_url', 'tiktok_url', 'address', 'address_line2', 'city', 'state', 'postcode', 'country',
    'logo_path', 'is_enabled', 'position',
])]
#[ObservedBy([EntityObserver::class])]
class Supplier extends Model
{
    use ShopLookupAssigned;

    public static function shopLookupFamily(): string
    {
        return ShopLookup::FAMILY_SUPPLIER;
    }
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(SupplierTranslation::class);
    }

    public function translation(?string $locale = null): ?SupplierTranslation
    {
        return Language::pickTranslation($this->translations, $locale);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_supplier')
            ->withPivot(['supplier_reference', 'wholesale_price', 'is_default']);
    }

    public function displayName(): string
    {
        return $this->translation()?->name ?? "Supplier #{$this->id}";
    }

    public function contactSummary(): string
    {
        return collect([$this->email, $this->phone])
            ->filter()
            ->implode(' · ') ?: '';
    }
}
