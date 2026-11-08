<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * max_quantity = maximum active pre-order units per authenticated customer for this product.
 * total_limit / orders_placed = product-wide pool across all customers.
 */
#[Fillable([
    'product_id', 'start_at', 'end_at', 'release_at', 'fulfillment_at', 'expected_delivery_at',
    'payment_type', 'deposit_type', 'deposit_value', 'min_quantity', 'max_quantity', 'total_limit',
    'orders_placed', 'message', 'show_badge', 'show_release_date', 'show_delivery_date',
    'show_countdown_pdp', 'show_countdown_listing', 'show_image_label', 'notify_me_enabled',
    'fulfillment_rule', 'preorder_price', 'preorder_price_valid_until',
])]
class ProductPreorderSetting extends Model
{
    protected $table = 'product_preorders';

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'release_at' => 'datetime',
            'fulfillment_at' => 'datetime',
            'expected_delivery_at' => 'datetime',
            'deposit_value' => 'decimal:2',
            'preorder_price' => 'decimal:2',
            'preorder_price_valid_until' => 'datetime',
            'show_badge' => 'boolean',
            'show_release_date' => 'boolean',
            'show_delivery_date' => 'boolean',
            'show_countdown_pdp' => 'boolean',
            'show_countdown_listing' => 'boolean',
            'show_image_label' => 'boolean',
            'notify_me_enabled' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function ordersRemaining(): ?int
    {
        if ($this->total_limit === null) {
            return null;
        }

        return max(0, (int) $this->total_limit - (int) $this->orders_placed);
    }
}
