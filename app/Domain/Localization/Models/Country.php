<?php

namespace App\Domain\Localization\Models;

use App\Domain\Shipping\Models\ShippingZone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'code', 'iso3', 'name', 'calling_code', 'default_currency_code', 'timezone',
    'address_format', 'postal_code_label', 'tax_id_label', 'has_regions',
    'is_enabled', 'is_default', 'position', 'zone_id',
])]
class Country extends Model
{
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'is_default' => 'boolean',
            'has_regions' => 'boolean',
            'position' => 'integer',
        ];
    }

    public static function defaultCode(): ?string
    {
        return static::query()->where('is_default', true)->value('code')
            ?? static::query()->where('is_enabled', true)->orderBy('position')->orderBy('name')->value('code');
    }

    public function regions(): HasMany
    {
        return $this->hasMany(CountryRegion::class)->orderBy('position')->orderBy('name');
    }

    public function cities(): HasMany
    {
        return $this->hasMany(City::class)->orderBy('position')->orderBy('name');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'default_currency_code', 'code');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(ShippingZone::class, 'zone_id');
    }

    /**
     * Enabled countries for address forms, including installed states/regions.
     *
    * @return list<array{code: string, name: string, calling_code: string|null, has_regions: bool, regions: list<array{code: string, name: string}>}>
     */
    public static function optionsForAddresses(): array
    {
        return static::query()
            ->where('is_enabled', true)
            ->with([
                'regions' => fn ($q) => $q->where('is_enabled', true)->orderBy('position')->orderBy('name'),
                'cities' => fn ($q) => $q->where('is_enabled', true)->with('region')->orderBy('position')->orderBy('name'),
            ])
            ->orderBy('position')
            ->orderBy('name')
            ->get()
            ->map(fn (self $country) => [
                'code' => $country->code,
                'name' => $country->name,
                'calling_code' => $country->calling_code,
                'postal_code_label' => $country->postal_code_label ?: 'PIN / ZIP',
                'postal' => \App\Domain\Localization\PostalCode::client($country->code),
                'tax_id_label' => $country->tax_id_label,
                'has_regions' => (bool) $country->has_regions && $country->regions->isNotEmpty(),
                'has_cities' => $country->cities->isNotEmpty(),
                'regions' => $country->regions->map(fn (CountryRegion $region) => [
                    'code' => $region->code,
                    'name' => $region->name,
                ])->values()->all(),
                'cities' => $country->cities->map(fn (City $city) => [
                    'name' => $city->name,
                    'region_code' => $city->region?->code,
                    'region_name' => $city->region?->name,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }
}
