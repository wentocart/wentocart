<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_id', 'value', 'selling_price', 'is_enabled', 'position'])]
class ProductGiftCardAmount extends Model
{
    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'is_enabled' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function effectiveSellingPrice(): float
    {
        return (float) ($this->selling_price ?? $this->value);
    }
}
