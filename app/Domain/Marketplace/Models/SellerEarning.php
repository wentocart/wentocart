<?php

namespace App\Domain\Marketplace\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'seller_id', 'seller_order_id', 'order_id', 'product_id', 'order_item_id',
    'gross_amount', 'commission_amount', 'net_amount',
    'refunded_gross', 'refunded_commission', 'refunded_net', 'paid_net',
    'commission_rate',
    'commission_type', 'commission_value', 'commission_source', 'commission_rule_id',
    'status', 'available_at', 'released_at',
])]
class SellerEarning extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_PAID = 'paid';

    /** Order was refunded before payout — not withdrawable. */
    public const STATUS_REFUNDED = 'refunded';

    protected function casts(): array
    {
        return [
            'gross_amount' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'refunded_gross' => 'decimal:2',
            'refunded_commission' => 'decimal:2',
            'refunded_net' => 'decimal:2',
            'paid_net' => 'decimal:2',
            'commission_rate' => 'decimal:2',
            'commission_value' => 'decimal:2',
            'available_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /** Remaining seller net after partial refunds and partial payouts (immutable originals). */
    public function remainingNet(): float
    {
        return round(max(0, (float) $this->net_amount - (float) $this->refunded_net - (float) $this->paid_net), 2);
    }

    public function remainingCommission(): float
    {
        return round(max(0, (float) $this->commission_amount - (float) $this->refunded_commission), 2);
    }

    public function remainingGross(): float
    {
        return round(max(0, (float) $this->gross_amount - (float) $this->refunded_gross), 2);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }
}
