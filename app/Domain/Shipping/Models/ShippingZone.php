<?php

namespace App\Domain\Shipping\Models;

use App\Domain\Localization\Models\Country;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'is_enabled', 'position'])]
class ShippingZone extends Model
{
    protected static function booted(): void
    {
        static::deleting(function (ShippingZone $zone) {
            $zone->countries()->update(['zone_id' => null]);
        });
    }

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function countries(): HasMany
    {
        return $this->hasMany(Country::class, 'zone_id');
    }

    /**
     * A country belongs to one zone. Countries omitted here are released.
     * Countries included here move off whatever zone they were in.
     *
     * @param  list<int>  $countryIds
     */
    public function assignCountries(array $countryIds): void
    {
        $countryIds = array_values(array_unique(array_map('intval', $countryIds)));
        if ($countryIds === []) {
            $this->countries()->update(['zone_id' => null]);

            return;
        }

        $this->countries()->whereNotIn('id', $countryIds)->update(['zone_id' => null]);
        Country::query()->whereIn('id', $countryIds)->update(['zone_id' => $this->id]);
    }

    /** @param  list<int>  $zoneIds */
    public static function matchesCountry(array $zoneIds, ?int $countryId): bool
    {
        if ($zoneIds === []) {
            return true;
        }
        if (! $countryId) {
            return false;
        }

        return self::query()
            ->whereIn('id', $zoneIds)
            ->where('is_enabled', true)
            ->whereHas('countries', fn ($q) => $q->where('countries.id', $countryId))
            ->exists();
    }
}
