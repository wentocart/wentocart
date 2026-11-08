<?php

namespace App\Domain\Tax\Models;

use App\Domain\Localization\Models\Country;
use App\Domain\Localization\Models\CountryRegion;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'country_id', 'is_enabled', 'position'])]
class TaxZone extends Model
{
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function regions(): BelongsToMany
    {
        return $this->belongsToMany(CountryRegion::class, 'tax_zone_regions');
    }

    public function rules(): HasMany
    {
        return $this->hasMany(TaxRule::class);
    }
}
