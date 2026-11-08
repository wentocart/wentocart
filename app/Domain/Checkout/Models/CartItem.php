<?php

namespace App\Domain\Checkout\Models;

use App\Domain\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'cart_id', 'product_id', 'parent_product_id', 'combination_id', 'subscription_plan_id', 'shipping_method_id', 'qty', 'unit_price',
    'customization', 'bundle_selection', 'gift_card_data', 'booking_date', 'booking_end_date', 'rental_duration', 'rental_data',
    'preorder_data',
])]
class CartItem extends Model
{
    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'customization' => 'array',
            'bundle_selection' => 'array',
            'gift_card_data' => 'array',
            'booking_date' => 'date',
            'booking_end_date' => 'date',
            'rental_duration' => 'integer',
            'rental_data' => 'array',
            'preorder_data' => 'array',
        ];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function subscriptionPlan(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Subscription\Models\SubscriptionPlan::class, 'subscription_plan_id');
    }
}
