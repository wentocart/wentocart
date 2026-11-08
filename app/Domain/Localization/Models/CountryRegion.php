<?php

namespace App\Domain\Localization\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['country_id', 'code', 'name', 'is_enabled', 'position'])]
class CountryRegion extends Model
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
}
