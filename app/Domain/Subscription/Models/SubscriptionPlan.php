<?php

namespace App\Domain\Subscription\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Localization\Models\Language;
use App\Domain\Store\Concerns\ShopLookupAssigned;
use App\Domain\Store\Models\ShopLookup;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'is_enabled', 'position',
    'trial_enabled', 'trial_duration', 'trial_cycle', 'trial_frequency',
    'duration', 'cycle', 'frequency',
])]
class SubscriptionPlan extends Model
{
    /** Matches MySQL UNSIGNED INT (unsignedInteger columns). */
    public const MAX_UNSIGNED_INT = 4294967295;

    use SoftDeletes;
    use ShopLookupAssigned;

    public static function shopLookupFamily(): string
    {
        return ShopLookup::FAMILY_PLAN;
    }

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'trial_enabled' => 'boolean',
        ];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(SubscriptionPlanTranslation::class);
    }

    public function translation(?string $locale = null): ?SubscriptionPlanTranslation
    {
        return Language::pickTranslation($this->translations, $locale);
    }

    public function productAssignments(): HasMany
    {
        return $this->hasMany(ProductSubscriptionPlan::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_subscription_plans')
            ->withPivot(['price', 'discount_type', 'discount_value', 'is_enabled', 'is_default', 'position'])
            ->withTimestamps();
    }
}
