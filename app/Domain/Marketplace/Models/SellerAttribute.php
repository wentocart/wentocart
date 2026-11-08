<?php

namespace App\Domain\Marketplace\Models;

use App\Domain\Catalog\Models\CatalogAttribute;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'seller_id', 'attribute_id', 'name', 'code', 'type',
    'supports_swatches', 'is_enabled', 'position',
])]
class SellerAttribute extends Model
{
    protected function casts(): array
    {
        return [
            'supports_swatches' => 'boolean',
            'is_enabled' => 'boolean',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(CatalogAttribute::class, 'attribute_id');
    }
}
