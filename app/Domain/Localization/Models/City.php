<?php

namespace App\Domain\Localization\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['country_id', 'region_id', 'name', 'is_enabled', 'position'])]
class City extends Model
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

    public function region(): BelongsTo
    {
        return $this->belongsTo(CountryRegion::class, 'region_id');
    }
}
