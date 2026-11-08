<?php

namespace App\Domain\Promo\Models;

use App\Domain\Localization\Money;
use App\Domain\Ops\EntityObserver;
use App\Domain\Promo\DiscountEngine;
use App\Domain\Store\Concerns\ShopOwned;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name', 'apply_as', 'code', 'action', 'value', 'currency_code', 'priority', 'stop_further',
    'min_cart', 'min_qty', 'max_discount', 'max_uses', 'max_uses_per_customer', 'used_count',
    'starts_at', 'expires_at', 'is_enabled', 'store_id', 'seller_id', 'parent_id',
    'allow_sellers_to_join',
])]
#[ObservedBy([EntityObserver::class])]
class DiscountRule extends Model
{
    use ShopOwned;

    protected static string $shopFamily = 'discounts';

    public const APPLY_AS = [
        'catalog' => 'Catalog price',
        'cart' => 'Automatic cart',
        'coupon' => 'Coupon code',
    ];

    public const ACTIONS = [
        'percent' => 'Percent off',
        'amount' => 'Fixed amount off',
        'set_price' => 'Set price to',
        'buy_x_get_y' => 'Buy X get 1 free',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'min_cart' => 'decimal:2',
            'max_discount' => 'decimal:2',
            'priority' => 'integer',
            'min_qty' => 'integer',
            'max_uses' => 'integer',
            'max_uses_per_customer' => 'integer',
            'used_count' => 'integer',
            'stop_further' => 'boolean',
            'is_enabled' => 'boolean',
            'allow_sellers_to_join' => 'boolean',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => app(DiscountEngine::class)->flush());
        static::deleted(fn () => app(DiscountEngine::class)->flush());
    }

    public function isLive(): bool
    {
        if (! $this->is_enabled) {
            return false;
        }
        if ($this->starts_at && $this->starts_at->isFuture()) {
            return false;
        }
        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }
        if ($this->max_uses !== null && (int) $this->used_count >= (int) $this->max_uses) {
            return false;
        }

        return true;
    }

    public function conditions(): HasMany
    {
        return $this->hasMany(DiscountRuleCondition::class);
    }

    public function scopeLive(Builder $query): Builder
    {
        $now = now();

        return $query->where('is_enabled', true)
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>=', $now);
            })
            ->where(function (Builder $q) {
                $q->whereNull('max_uses')->orWhereColumn('used_count', '<', 'max_uses');
            });
    }

    public function applyAsLabel(): string
    {
        return self::APPLY_AS[$this->apply_as] ?? $this->apply_as;
    }

    public function actionSummary(): string
    {
        $value = rtrim(rtrim(number_format((float) $this->value, 2, '.', ''), '0'), '.');
        $currency = strtoupper((string) ($this->currency_code ?: ''));
        if ($this->action === 'percent') {
            return $value.'% off';
        }
        if ($this->action === 'set_price') {
            return 'Set price '.$value.($currency ? ' '.$currency : '');
        }
        if ($this->action === 'buy_x_get_y') {
            return 'Buy '.$value.' get 1 free';
        }

        return $value.($currency ? ' '.$currency : '').' off';
    }

    public function conditionSummary(): string
    {
        $this->loadMissing('conditions');
        if ($this->conditions->isEmpty()) {
            return 'Whole catalog';
        }

        $labels = DiscountRuleCondition::TYPES;
        $parts = $this->conditions->map(function (DiscountRuleCondition $condition) use ($labels) {
            $label = $labels[$condition->type] ?? $condition->type;
            if ($condition->type === 'catalog') {
                return $label;
            }
            $count = count($condition->values());

            return $label.($count ? ' ('.$count.')' : '');
        });

        return $parts->implode(', ');
    }

    public function currencyCode(): string
    {
        return strtoupper((string) ($this->currency_code ?: Money::defaultCode()));
    }

    /** Convert a money field on this rule into the target (usually store-default) currency. */
    public function convertAmount(?float $amount, ?string $toCode = null): ?float
    {
        if ($amount === null) {
            return null;
        }

        return Money::convert((float) $amount, $this->currencyCode(), $toCode ?: Money::defaultCode());
    }

    public function reductionFor(float $base, ?string $currencyCode = null): float
    {
        $base = max(0, $base);
        if ($base <= 0) {
            return 0;
        }

        // Catalog/cart bases are stored in the shop default currency.
        $toCode = $currencyCode ?: Money::defaultCode();
        $value = $this->action === 'percent'
            ? (float) $this->value
            : (float) $this->convertAmount((float) $this->value, $toCode);

        $amount = match ($this->action) {
            'percent' => round($base * ($value / 100), 2),
            'set_price' => round(max(0, $base - $value), 2),
            'buy_x_get_y' => 0.0,
            default => min($value, $base),
        };

        if ($this->max_discount !== null) {
            $max = (float) $this->convertAmount((float) $this->max_discount, $toCode);
            $amount = min($amount, $max);
        }

        return max(0, $amount);
    }
}
