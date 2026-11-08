<?php

namespace App\Domain\Marketplace\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Order\Models\OrderItem;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'seller_order_id', 'order_item_id', 'product_id', 'sku', 'name',
    'qty', 'returned_qty', 'cancelled_qty', 'unit_price', 'tax_amount', 'total',
])]
class SellerOrderItem extends Model
{
    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function returnableQty(): int
    {
        return max(0, (int) $this->qty - (int) $this->returned_qty - (int) $this->cancelled_qty);
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
