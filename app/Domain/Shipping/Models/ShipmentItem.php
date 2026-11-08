<?php

namespace App\Domain\Shipping\Models;

use App\Domain\Marketplace\Models\SellerOrderItem;
use App\Domain\Order\Models\OrderItem;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'shipment_id', 'order_item_id', 'seller_order_item_id', 'qty',
])]
class ShipmentItem extends Model
{
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function sellerOrderItem(): BelongsTo
    {
        return $this->belongsTo(SellerOrderItem::class);
    }
}
