<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'purchase_order_id', 'product_id', 'sku', 'name', 'qty_ordered', 'qty_received', 'unit_cost',
])]
class PurchaseOrderItem extends Model
{
    protected function casts(): array
    {
        return [
            'qty_ordered' => 'decimal:3',
            'qty_received' => 'decimal:3',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PurchaseOrderReceipt::class);
    }

    public function remaining(): float
    {
        return max(0, (float) $this->qty_ordered - (float) $this->qty_received);
    }

    public function lineTotal(): float
    {
        return round((float) $this->qty_ordered * (float) $this->unit_cost, 4);
    }
}
