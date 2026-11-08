<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['warehouse_id', 'product_id', 'qty', 'reserved_qty'])]
class WarehouseStock extends Model
{
    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'reserved_qty' => 'decimal:3',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function available(): float
    {
        return max(0, (float) $this->qty - (float) $this->reserved_qty);
    }
}
