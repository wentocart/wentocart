<?php

namespace App\Domain\Promo\Models;

use App\Domain\Promo\DiscountEngine;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['discount_rule_id', 'type', 'value_ids'])]
class DiscountRuleCondition extends Model
{
    public const TYPES = [
        'catalog' => 'Whole catalog',
        'product' => 'Product',
        'category' => 'Category',
        'brand' => 'Brand',
        'seller' => 'Seller',
        'customer' => 'Customer',
        'customer_group' => 'Customer group',
        'country' => 'Country',
        'region' => 'State / region',
        'currency' => 'Currency',
    ];

    public const ITEM_TYPES = ['catalog', 'product', 'category', 'brand', 'seller'];

    public const CONTEXT_TYPES = ['customer', 'customer_group', 'country', 'region', 'currency'];

    protected function casts(): array
    {
        return [
            'value_ids' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => app(DiscountEngine::class)->flush());
        static::deleted(fn () => app(DiscountEngine::class)->flush());
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(DiscountRule::class, 'discount_rule_id');
    }

    /** @return list<string> */
    public function values(): array
    {
        return array_values(array_map('strval', $this->value_ids ?? []));
    }
}
