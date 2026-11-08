<?php

namespace App\Domain\Order\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_return_id', 'order_item_id', 'qty', 'unit_price', 'tax_amount', 'line_total'])]
class ShopReturnItem extends Model
{
    protected $table = 'order_return_items';

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function shopReturn(): BelongsTo
    {
        return $this->belongsTo(ShopReturn::class, 'order_return_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
