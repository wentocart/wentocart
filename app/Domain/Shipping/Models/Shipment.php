<?php

namespace App\Domain\Shipping\Models;

use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerOrder;
use App\Domain\Order\Models\Order;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'order_id', 'seller_order_id', 'seller_id', 'shipping_method_id', 'shipping_cost', 'status',
    'carrier', 'tracking_number', 'tracking_url',
    'shipped_at', 'delivered_at', 'shipping_address', 'notes',
])]
class Shipment extends Model
{
    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY_TO_SHIP = 'ready_to_ship';

    public const STATUS_SHIPPED = 'shipped';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PROCESSING,
        self::STATUS_READY_TO_SHIP,
        self::STATUS_SHIPPED,
        self::STATUS_DELIVERED,
        self::STATUS_CANCELLED,
    ];

    protected function casts(): array
    {
        return [
            'shipping_cost' => 'decimal:2',
            'shipping_address' => 'array',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
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

    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ShipmentItem::class);
    }

    public function isActive(): bool
    {
        return $this->status !== self::STATUS_CANCELLED;
    }

    public function canTransitionTo(string $to): bool
    {
        $map = [
            self::STATUS_PROCESSING => [self::STATUS_READY_TO_SHIP, self::STATUS_SHIPPED, self::STATUS_CANCELLED],
            self::STATUS_READY_TO_SHIP => [self::STATUS_SHIPPED, self::STATUS_CANCELLED],
            self::STATUS_SHIPPED => [self::STATUS_DELIVERED],
            self::STATUS_DELIVERED => [],
            self::STATUS_CANCELLED => [],
        ];

        return in_array($to, $map[$this->status] ?? [], true);
    }
}
