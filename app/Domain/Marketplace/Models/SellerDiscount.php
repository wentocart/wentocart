<?php

namespace App\Domain\Marketplace\Models;

use App\Domain\Promo\Models\DiscountRule;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'seller_id', 'discount_rule_id', 'name', 'code', 'apply_as', 'action', 'value',
    'min_cart', 'max_uses', 'used_count', 'starts_at', 'expires_at', 'is_enabled',
])]
class SellerDiscount extends Model
{
    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'min_cart' => 'decimal:2',
            'is_enabled' => 'boolean',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function discountRule(): BelongsTo
    {
        return $this->belongsTo(DiscountRule::class);
    }
}
