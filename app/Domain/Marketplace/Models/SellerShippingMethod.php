<?php

namespace App\Domain\Marketplace\Models;

use App\Domain\Shipping\Models\ShippingMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'seller_id', 'shipping_method_id', 'code', 'name', 'description',
    'type', 'price', 'is_enabled', 'config',
])]
class SellerShippingMethod extends Model
{
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_enabled' => 'boolean',
            'config' => 'array',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class);
    }
}
