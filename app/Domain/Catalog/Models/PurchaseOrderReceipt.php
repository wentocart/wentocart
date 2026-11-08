<?php

namespace App\Domain\Catalog\Models;

use App\Models\Admin;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'purchase_order_id',
    'purchase_order_item_id',
    'product_id',
    'warehouse_id',
    'admin_id',
    'qty',
    'unit_cost',
    'inventory_reference',
    'is_final',
    'reversed_at',
    'reversed_by_admin_id',
    'reversal_reference',
    'notes',
])]
class PurchaseOrderReceipt extends Model
{
    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'unit_cost' => 'decimal:4',
            'is_final' => 'boolean',
            'reversed_at' => 'datetime',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function reversedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reversed_by_admin_id');
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }
}
