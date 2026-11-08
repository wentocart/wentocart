<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Localization\Models\Language;
use App\Domain\Ops\EntityObserver;
use App\Domain\Ops\StaffActivityObserver;
use App\Domain\Store\Concerns\ShopLookupAssigned;
use App\Domain\Store\Models\ShopLookup;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'seller_id', 'code', 'logo_path', 'banner_path', 'website', 'country_code', 'established_year',
    'email', 'phone', 'address', 'address_line2', 'city', 'state', 'postcode', 'country',
    'facebook_url', 'instagram_url', 'youtube_url', 'twitter_url',
    'linkedin_url', 'tiktok_url', 'is_enabled', 'position',
])]
#[ObservedBy([EntityObserver::class, StaffActivityObserver::class])]
class Brand extends Model
{
    use SoftDeletes;
    use ShopLookupAssigned;

    public static function shopLookupFamily(): string
    {
        return ShopLookup::FAMILY_BRAND;
    }

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'established_year' => 'integer',
            'position' => 'integer',
        ];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(BrandTranslation::class);
    }

    public function translation(?string $locale = null): ?BrandTranslation
    {
        return Language::pickTranslation($this->translations, $locale);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(BrandImage::class)->orderBy('position');
    }
}
