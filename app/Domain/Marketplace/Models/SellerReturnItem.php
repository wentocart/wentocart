<?php

namespace App\Domain\Marketplace\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Order\Models\OrderItem;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'seller_return_id', 'seller_order_item_id', 'order_item_id', 'product_id',
    'qty', 'unit_price', 'line_total', 'tax_amount',
    'gross_amount', 'commission_amount', 'net_amount',
])]
class SellerReturnItem extends Model
{
    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
        ];
    }

    public function sellerReturn(): BelongsTo
    {
        return $this->belongsTo(SellerReturn::class);
    }

    public function sellerOrderItem(): BelongsTo
    {
        return $this->belongsTo(SellerOrderItem::class);
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
