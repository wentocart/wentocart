<?php

namespace App\Domain\Marketplace\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name', 'code', 'description', 'price', 'billing_period',
    'product_limit', 'order_limit', 'max_staff', 'allows_communication',
    'is_enabled', 'position', 'permissions', 'allowed_product_types',
    'commission_value', 'is_featured', 'is_default', 'duration_days',
])]
class MarketplaceSubscriptionPlan extends Model
{
    public const PERIOD_MONTH = 'month';

    public const PERIOD_YEAR = 'year';

    public const PERIOD_LIFETIME = 'lifetime';

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'commission_value' => 'decimal:2',
            'allows_communication' => 'boolean',
            'is_enabled' => 'boolean',
            'is_featured' => 'boolean',
            'is_default' => 'boolean',
            'permissions' => 'array',
            'allowed_product_types' => 'array',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(MarketplaceSellerSubscription::class, 'plan_id');
    }

    /** Duration (days); empty → 30 month / 365 year / lifetime null. */
    public function resolvedDurationDays(): ?int
    {
        if ($this->billing_period === self::PERIOD_LIFETIME) {
            return null;
        }
        if ($this->duration_days !== null && (int) $this->duration_days > 0) {
            return (int) $this->duration_days;
        }

        return match ($this->billing_period) {
            self::PERIOD_YEAR => 365,
            default => 30,
        };
    }
}
