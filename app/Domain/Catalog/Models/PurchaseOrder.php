<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'number',
    'supplier_id',
    'warehouse_id',
    'status',
    'expected_at',
    'notes',
    'discount_type',
    'discount_value',
    'shipping_cost',
    'ordered_at',
    'received_at',
])]
class PurchaseOrder extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ORDERED = 'ordered';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'expected_at' => 'date',
            'ordered_at' => 'datetime',
            'received_at' => 'datetime',
            'discount_value' => 'decimal:4',
            'shipping_cost' => 'decimal:4',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PurchaseOrderReceipt::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_ORDERED], true);
    }

    public function lineSubtotal(): float
    {
        $this->loadMissing('items');

        return round((float) $this->items->sum(fn (PurchaseOrderItem $item) => $item->lineTotal()), 4);
    }

    public function discountAmount(): float
    {
        $subtotal = $this->lineSubtotal();
        $value = max(0, (float) $this->discount_value);
        if ($this->discount_type === 'percent') {
            $pct = min(100, $value);

            return round(min($subtotal, $subtotal * ($pct / 100)), 4);
        }
        if ($this->discount_type === 'fixed') {
            return round(min($subtotal, $value), 4);
        }

        return 0.0;
    }

    public function grandTotal(): float
    {
        return max(0, round($this->lineSubtotal() - $this->discountAmount() + (float) $this->shipping_cost, 4));
    }

    public function isOverdue(): bool
    {
        if (! $this->expected_at) {
            return false;
        }
        if (! in_array($this->status, [self::STATUS_DRAFT, self::STATUS_ORDERED], true)) {
            return false;
        }

        return $this->expected_at->lt(now()->startOfDay());
    }

    public function overdueDays(): int
    {
        if (! $this->isOverdue()) {
            return 0;
        }

        return (int) $this->expected_at->diffInDays(now()->startOfDay());
    }
}
