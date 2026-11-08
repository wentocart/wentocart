<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'parent_product_id', 'child_product_id', 'quantity', 'min_quantity', 'max_quantity',
    'qty_editable', 'is_required', 'default_selected', 'option_group', 'position',
])]
class ProductComponent extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'min_quantity' => 'integer',
            'max_quantity' => 'integer',
            'qty_editable' => 'boolean',
            'is_required' => 'boolean',
            'default_selected' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'parent_product_id');
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'child_product_id');
    }
}
