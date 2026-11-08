<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_id', 'type', 'label', 'is_required', 'position', 'config'])]
class ProductCustomizationField extends Model
{
    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'config' => 'array',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
