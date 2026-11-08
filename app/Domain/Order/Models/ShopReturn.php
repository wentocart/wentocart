<?php

namespace App\Domain\Order\Models;

use App\Domain\Customer\Models\Customer;
use App\Domain\Ops\EntityObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'order_id', 'customer_id', 'number', 'status', 'reason', 'reason_id', 'resolution', 'customer_note', 'admin_note',
    'refund_subtotal', 'refund_tax', 'refund_total', 'restock',
    'requested_at', 'approved_at', 'rejected_at', 'received_at', 'refunded_at',
    'approved_by_admin_id', 'rejected_by_admin_id', 'refunded_by_admin_id',
])]
#[ObservedBy([EntityObserver::class])]
class ShopReturn extends Model
{
    public const STATUS_REQUESTED = 'requested';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_REFUNDED = 'refunded';

    protected $table = 'order_returns';

    protected function casts(): array
    {
        return [
            'refund_subtotal' => 'decimal:2',
            'refund_tax' => 'decimal:2',
            'refund_total' => 'decimal:2',
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

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ShopReturnItem::class, 'order_return_id');
    }
}
