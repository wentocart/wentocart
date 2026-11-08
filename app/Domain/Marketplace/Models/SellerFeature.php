<?php

namespace App\Domain\Marketplace\Models;

use App\Domain\Catalog\Models\Feature;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'seller_id', 'feature_id', 'name', 'code', 'type',
    'is_filterable', 'is_enabled', 'position',
])]
class SellerFeature extends Model
{
    protected function casts(): array
    {
        return [
            'is_filterable' => 'boolean',
            'is_enabled' => 'boolean',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }
}
