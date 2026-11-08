<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'product_id', 'rental_unit', 'min_duration', 'max_duration', 'default_duration',
    'pricing_model', 'availability_config', 'security_deposit_type', 'security_deposit_value',
    'inventory_mode', 'maintenance_units', 'out_of_service_units', 'fulfillment_method',
    'return_method', 'return_location', 'grace_period', 'grace_period_unit',
    'late_fee_type', 'late_fee_value', 'damage_fee_type', 'damage_fee_value',
    'lost_item_fee_type', 'lost_item_fee_value', 'cleaning_fee', 'policies',
])]
class ProductRentalSetting extends Model
{
    protected $table = 'product_rentals';

    protected function casts(): array
    {
        return [
            'availability_config' => 'array',
            'security_deposit_value' => 'decimal:2',
            'late_fee_value' => 'decimal:2',
            'damage_fee_value' => 'decimal:2',
            'lost_item_fee_value' => 'decimal:2',
            'cleaning_fee' => 'decimal:2',
            'policies' => 'array',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function pricingTiers(): HasMany
    {
        return $this->hasMany(ProductRentalPricingTier::class, 'product_id', 'product_id')->orderBy('position');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(ProductRentalAsset::class, 'product_id', 'product_id')->orderBy('position');
    }
}
