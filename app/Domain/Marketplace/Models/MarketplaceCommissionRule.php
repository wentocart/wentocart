<?php

namespace App\Domain\Marketplace\Models;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'name', 'scope', 'type', 'value',
    'product_id', 'seller_id', 'category_id',
    'is_enabled', 'notes',
])]
class MarketplaceCommissionRule extends Model
{
    public const SCOPE_PRODUCT = 'product';

    public const SCOPE_SELLER = 'seller';

    public const SCOPE_CATEGORY = 'category';

    public const SCOPE_GLOBAL = 'global';

    public const TYPE_PERCENT = 'percent';

    public const TYPE_FIXED = 'fixed';

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'is_enabled' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
