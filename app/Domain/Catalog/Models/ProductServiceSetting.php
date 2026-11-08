<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'product_id', 'service_type', 'duration', 'duration_unit', 'pricing_model',
    'delivery_method', 'meeting_instructions', 'delivery_instructions', 'online_service_url',
    'service_area', 'location_info', 'travel_fee', 'availability_config',
])]
class ProductServiceSetting extends Model
{
    protected $table = 'product_services';

    protected function casts(): array
    {
        return [
            'duration' => 'decimal:2',
            'travel_fee' => 'decimal:2',
            'availability_config' => 'array',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function requiresScheduling(): bool
    {
        return $this->service_type === 'appointment';
    }
}
