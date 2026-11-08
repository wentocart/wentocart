<?php

namespace App\Domain\Shipping\Models;

use App\Domain\Store\Concerns\ShopAssigned;
use App\Domain\Store\Models\Store;
use App\Domain\Store\StoreContext;
use App\Domain\Tax\Models\TaxClass;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['code', 'name', 'description', 'type', 'driver', 'price', 'is_enabled', 'position', 'config', 'conditions', 'rate_config', 'seller_id', 'tax_class_id'])]
class ShippingMethod extends Model
{
    use ShopAssigned;

    public const TYPES = [
        'flat' => 'Flat rate',
        'free' => 'Free shipping',
        'price_based' => 'Price based',
        'weight_based' => 'Weight based',
        'local_pickup' => 'Local pickup',
        'carrier' => 'Carrier (extension)',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_enabled' => 'boolean',
            'config' => 'array',
            'conditions' => 'array',
            'rate_config' => 'array',
        ];
    }

    /** @return array<string, mixed> */
    public static function emptyConditions(): array
    {
        return [
            'min_order' => null,
            'max_order' => null,
            'min_weight' => null,
            'max_weight' => null,
            'customer_group_ids' => [],
            'country_ids' => [],
            'zone_ids' => [],
        ];
    }

    /** @param  array<string, mixed>|null  $conditions */
    public static function normalizeConditions(?array $conditions): array
    {
        $base = self::emptyConditions();
        if (! is_array($conditions)) {
            return $base;
        }

        foreach (['min_order', 'max_order', 'min_weight', 'max_weight'] as $key) {
            $value = $conditions[$key] ?? null;
            $base[$key] = $value === '' || $value === null ? null : (float) $value;
        }

        foreach (['customer_group_ids', 'country_ids', 'zone_ids'] as $key) {
            $base[$key] = array_values(array_unique(array_map(
                'intval',
                array_filter((array) ($conditions[$key] ?? []), fn ($v) => $v !== '' && $v !== null)
            )));
        }

        // A zone already lists its countries. Keeping both filters made a method
        // disappear unless the address matched the zone and the country list.
        if ($base['zone_ids'] !== []) {
            $base['country_ids'] = [];
        }

        return $base;
    }

    /** @param  array<int, array<string, mixed>>|null  $tiers */
    public static function normalizeTiers(?array $tiers): array
    {
        $out = [];
        foreach ((array) $tiers as $tier) {
            if (! is_array($tier)) {
                continue;
            }
            $min = $tier['min'] ?? null;
            $max = $tier['max'] ?? null;
            $price = $tier['price'] ?? null;
            if ($min === '' && $max === '' && $price === '') {
                continue;
            }
            $out[] = [
                'min' => $min === '' || $min === null ? null : (float) $min,
                'max' => $max === '' || $max === null ? null : (float) $max,
                'price' => $price === '' || $price === null ? 0.0 : (float) $price,
            ];
        }

        return $out;
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? (string) $this->type;
    }

    public function priceSummary(): string
    {
        return match ($this->type) {
            'free' => 'Free',
            'price_based', 'weight_based' => $this->tierSummary(),
            default => number_format((float) $this->price, 2),
        };
    }

    protected function tierSummary(): string
    {
        $tiers = self::normalizeTiers($this->rate_config['tiers'] ?? []);
        if ($tiers === []) {
            return 'No tiers';
        }
        $count = count($tiers);
        $first = $tiers[0]['price'] ?? 0;

        return $count.' tier'.($count === 1 ? '' : 's').' (from '.number_format((float) $first, 2).')';
    }

    public function conditionSummary(): string
    {
        $c = self::normalizeConditions($this->conditions);
        $parts = [];
        if ($c['min_order'] !== null) {
            $parts[] = 'min order '.$c['min_order'];
        }
        if ($c['max_order'] !== null) {
            $parts[] = 'max order '.$c['max_order'];
        }
        if ($c['min_weight'] !== null) {
            $parts[] = 'min wt '.$c['min_weight'];
        }
        if ($c['max_weight'] !== null) {
            $parts[] = 'max wt '.$c['max_weight'];
        }
        if (count($c['customer_group_ids'])) {
            $parts[] = count($c['customer_group_ids']).' groups';
        }
        if (count($c['zone_ids'])) {
            $parts[] = count($c['zone_ids']).' zones';
        } elseif (count($c['country_ids'])) {
            $parts[] = count($c['country_ids']).' countries';
        }

        return $parts === [] ? 'No restrictions' : implode(', ', $parts);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function matchesContext(array $context): bool
    {
        $c = self::normalizeConditions($this->conditions);
        $subtotal = (float) ($context['cart_subtotal'] ?? 0);

        if ($c['min_order'] !== null && $subtotal < (float) $c['min_order']) {
            return false;
        }
        if ($c['max_order'] !== null && $subtotal > (float) $c['max_order']) {
            return false;
        }

        $weightKnown = array_key_exists('cart_weight', $context);
        $weight = (float) ($context['cart_weight'] ?? 0);
        if ($weightKnown && $c['min_weight'] !== null && $weight < (float) $c['min_weight']) {
            return false;
        }
        if ($weightKnown && $c['max_weight'] !== null && $weight > (float) $c['max_weight']) {
            return false;
        }
        if ($c['customer_group_ids'] !== []) {
            $groupId = (int) ($context['customer_group_id'] ?? 0);
            if ($groupId <= 0 || ! in_array($groupId, $c['customer_group_ids'], true)) {
                return false;
            }
        }
        if ($c['zone_ids'] !== []) {
            $countryId = (int) ($context['country_id'] ?? 0);
            if (! ShippingZone::matchesCountry($c['zone_ids'], $countryId > 0 ? $countryId : null)) {
                return false;
            }
        } elseif ($c['country_ids'] !== []) {
            $countryId = (int) ($context['country_id'] ?? 0);
            if ($countryId <= 0 || ! in_array($countryId, $c['country_ids'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Live checkout price for this method (stub `price` is only the fallback).
     */
    public function quoteFor(float $subtotal, float $weight = 0, array $context = []): float
    {
        $driver = strtolower(trim((string) ($this->driver ?: 'offline')));
        if ($driver !== '' && $driver !== 'offline') {
            $quoted = app(\App\Domain\Shipping\ShippingCarrierRegistry::class)
                ->quote($driver, $this, $subtotal, $weight, $context);
            if ($quoted !== null) {
                return round($quoted, 2);
            }
        }

        $basePrice = match ($this->type) {
            'free' => 0.0,
            'price_based' => $this->tierPrice($subtotal),
            'weight_based' => $this->tierPrice($weight),
            default => (float) $this->price,
        };

        $targetCurrency = (string) ($context['currency_code'] ?? \App\Domain\Localization\Money::currentCode());
        $baseCurrency = \App\Domain\Localization\Money::defaultCode();
        if ($basePrice > 0 && $targetCurrency !== '' && strtoupper($targetCurrency) !== strtoupper($baseCurrency)) {
            return round(\App\Domain\Localization\Money::convert($basePrice, $baseCurrency, $targetCurrency), 2);
        }

        return round($basePrice, 2);
    }

    protected function tierPrice(float $value): float
    {
        $tiers = self::normalizeTiers($this->rate_config['tiers'] ?? []);
        foreach ($tiers as $tier) {
            $min = $tier['min'];
            $max = $tier['max'];
            if ($min !== null && $value + 0.00001 < (float) $min) {
                continue;
            }
            if ($max !== null && $value - 0.00001 > (float) $max) {
                continue;
            }

            return round((float) $tier['price'], 2);
        }

        return round((float) $this->price, 2);
    }

    public function taxClass(): BelongsTo
    {
        return $this->belongsTo(TaxClass::class);
    }

    public function shops(): BelongsToMany
    {
        return $this->belongsToMany(Store::class, 'shipping_method_store')->withPivot('is_enabled')->withTimestamps();
    }

    public function constrainToCurrentShop(Builder $query): Builder
    {
        return app(StoreContext::class)->constrainMethods($query);
    }

    public function attachToCurrentShop(): void
    {
        foreach (app(StoreContext::class)->associationIds() as $id) {
            if ($id > 0 && ! $this->shops()->where('stores.id', $id)->exists()) {
                $this->shops()->attach($id, ['is_enabled' => true]);
            }
        }
    }
}
