<?php

namespace App\Domain\Marketplace\Models;

use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'order_id', 'seller_order_id', 'seller_id', 'customer_id', 'number', 'status',
    'reason', 'customer_note', 'seller_note', 'admin_note',
    'refund_subtotal', 'refund_tax', 'refund_total',
    'commission_reversed', 'seller_net_reversed', 'restock',
    'requested_at', 'approved_at', 'rejected_at', 'received_at', 'refunded_at',
    'approved_by_admin_id', 'rejected_by_admin_id', 'refunded_by_admin_id',
])]
class SellerReturn extends Model
{
    public const STATUS_REQUESTED = 'requested';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_APPROVED,
        self::STATUS_RECEIVED,
        self::STATUS_REFUNDED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    protected function casts(): array
    {
        return [
            'refund_subtotal' => 'decimal:2',
            'refund_tax' => 'decimal:2',
            'refund_total' => 'decimal:2',
            'commission_reversed' => 'decimal:2',
            'seller_net_reversed' => 'decimal:2',
            'restock' => 'boolean',
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'received_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SellerReturnItem::class);
    }

    public function canTransitionTo(string $to): bool
    {
        $map = [
            self::STATUS_REQUESTED => [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED],
            self::STATUS_APPROVED => [self::STATUS_RECEIVED, self::STATUS_REFUNDED, self::STATUS_REJECTED],
            self::STATUS_RECEIVED => [self::STATUS_REFUNDED],
            self::STATUS_REFUNDED => [],
            self::STATUS_REJECTED => [],
            self::STATUS_CANCELLED => [],
        ];

        return in_array($to, $map[$this->status] ?? [], true);
    }
}
