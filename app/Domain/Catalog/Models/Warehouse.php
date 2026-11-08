<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Localization\Models\Language;
use App\Domain\Store\Concerns\ShopLookupAssigned;
use App\Domain\Store\Models\ShopLookup;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Platform-owned stock location. Sellers do not own or manage warehouses;
 * warehouse_stocks are stripped from seller product APIs.
 */
#[Fillable([
    'name',
    'code',
    'is_default',
    'is_enabled',
    'email',
    'phone',
    'fax',
    'website',
    'address',
    'address_line2',
    'city',
    'state',
    'postcode',
    'country',
    'latitude',
    'longitude',
    'logo_path',
])]
class Warehouse extends Model
{
    use ShopLookupAssigned;

    public static function shopLookupFamily(): string
    {
        return ShopLookup::FAMILY_WAREHOUSE;
    }

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_enabled' => 'boolean',
        ];
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(WarehouseStock::class);
    }

    public function translations(): HasMany
    {
        return $this->hasMany(WarehouseTranslation::class);
    }

    public function translation(?string $locale = null): ?WarehouseTranslation
    {
        return Language::pickTranslation($this->translations, $locale);
    }

    public function displayName(?string $locale = null): string
    {
        return (string) ($this->translation($locale)?->name ?: $this->name);
    }
}
