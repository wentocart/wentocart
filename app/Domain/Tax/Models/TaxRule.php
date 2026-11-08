<?php

namespace App\Domain\Tax\Models;

use App\Domain\Localization\Models\Country;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tax_class_id', 'country_id', 'tax_zone_id', 'tax_rate_id',
    'name', 'rate', 'priority', 'is_compound', 'is_enabled',
])]
class TaxRule extends Model
{
    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4',
            'priority' => 'integer',
            'is_compound' => 'boolean',
            'is_enabled' => 'boolean',
        ];
    }

    public function taxClass(): BelongsTo
    {
        return $this->belongsTo(TaxClass::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(TaxZone::class, 'tax_zone_id');
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class, 'tax_rate_id');
    }

    public function resolvedRate(): float
    {
        if ($this->taxRate) {
            return (float) $this->taxRate->rate;
        }

        return (float) $this->rate;
    }
}
