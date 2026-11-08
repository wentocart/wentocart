<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_id', 'min_qty', 'max_qty', 'price', 'position'])]
class ProductQtyPrice extends Model
{
    protected function casts(): array
    {
        return [
            'min_qty' => 'decimal:3',
            'max_qty' => 'decimal:3',
            'price' => 'decimal:2',
            'position' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function matches(float $qty): bool
    {
        if ($qty < (float) $this->min_qty) {
            return false;
        }
        if ($this->max_qty !== null && $qty > (float) $this->max_qty) {
            return false;
        }

        return true;
    }
}
