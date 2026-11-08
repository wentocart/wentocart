<?php

namespace App\Domain\Payment\Models;

use App\Domain\Store\Concerns\ShopAssigned;
use App\Domain\Store\Models\Store;
use App\Domain\Store\StoreContext;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['code', 'name', 'description', 'driver', 'is_enabled', 'position', 'config', 'rules', 'seller_id'])]
class PaymentMethod extends Model
{
    use ShopAssigned;

    public const RULE_KEYS = [
        'category_ids',
        'customer_ids',
        'customer_group_ids',
        'shipping_method_ids',
        'country_ids',
        'currency_ids',
        'language_ids',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'config' => 'array',
            'rules' => 'array',
        ];
    }

    /** @return array<string, list<int>> */
    public static function emptyRules(): array
    {
        return [
            'category_ids' => [],
            'customer_ids' => [],
            'customer_group_ids' => [],
            'shipping_method_ids' => [],
            'country_ids' => [],
            'currency_ids' => [],
            'language_ids' => [],
        ];
    }

    /** @param  array<string, mixed>|null  $rules */
    public static function normalizeRules(?array $rules): array
    {
        $normalized = self::emptyRules();
        if (! is_array($rules)) {
            return $normalized;
        }

        foreach (self::RULE_KEYS as $key) {
            $normalized[$key] = array_values(array_unique(array_map('intval', array_filter((array) ($rules[$key] ?? []), fn ($v) => $v !== '' && $v !== null))));
        }

        return $normalized;
    }

    public function ruleSummary(): string
    {
        $rules = self::normalizeRules($this->rules);
        $parts = [];
        $labels = [
            'category_ids' => 'categories',
            'customer_ids' => 'customers',
            'customer_group_ids' => 'groups',
            'shipping_method_ids' => 'shipping',
            'country_ids' => 'countries',
            'currency_ids' => 'currencies',
            'language_ids' => 'languages',
        ];

        foreach ($labels as $key => $label) {
            $count = count($rules[$key] ?? []);
            if ($count > 0) {
                $parts[] = "{$count} {$label}";
            }
        }

        return $parts === [] ? 'No restrictions' : implode(', ', $parts);
    }

    /**
     * Empty rule lists mean “no restriction” for that dimension.
     *
     * @param  array<string, mixed>  $context
     */
    public function matchesContext(array $context): bool
    {
        $rules = self::normalizeRules($this->rules);

        if ($rules['category_ids'] !== []) {
            $cartCats = array_map('intval', (array) ($context['category_ids'] ?? []));
            if ($cartCats === [] || count(array_intersect($rules['category_ids'], $cartCats)) === 0) {
                return false;
            }
        }

        if ($rules['customer_ids'] !== []) {
            $customerId = (int) ($context['customer_id'] ?? 0);
            if ($customerId <= 0 || ! in_array($customerId, $rules['customer_ids'], true)) {
                return false;
            }
        }

        if ($rules['customer_group_ids'] !== []) {
            $groupId = (int) ($context['customer_group_id'] ?? 0);
            if ($groupId <= 0 || ! in_array($groupId, $rules['customer_group_ids'], true)) {
                return false;
            }
        }

        if ($rules['shipping_method_ids'] !== []) {
            $shippingId = (int) ($context['shipping_method_id'] ?? 0);
            if ($shippingId <= 0 || ! in_array($shippingId, $rules['shipping_method_ids'], true)) {
                return false;
            }
        }

        if ($rules['country_ids'] !== []) {
            $countryId = (int) ($context['country_id'] ?? 0);
            if ($countryId <= 0 || ! in_array($countryId, $rules['country_ids'], true)) {
                return false;
            }
        }

        if ($rules['currency_ids'] !== []) {
            $currencyId = (int) ($context['currency_id'] ?? 0);
            if ($currencyId <= 0 || ! in_array($currencyId, $rules['currency_ids'], true)) {
                return false;
            }
        }

        if ($rules['language_ids'] !== []) {
            $languageId = (int) ($context['language_id'] ?? 0);
            if ($languageId <= 0 || ! in_array($languageId, $rules['language_ids'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Shopper-facing copy. Extensions may store a locale map on config.i18n.
     */
    public function textForLocale(string $attribute, ?string $locale = null): string
    {
        $stored = trim((string) ($this->getAttribute($attribute) ?? ''));
        $config = is_array($this->config) ? $this->config : [];
        $map = $config['i18n'][$attribute] ?? null;
        if (! is_array($map) || $map === []) {
            return $stored;
        }

        $locale = strtolower(str_replace('_', '-', (string) ($locale ?: app()->getLocale())));
        $picked = trim((string) \App\Domain\Plugin\PageDocument::pickLocalized($map, $locale, ''));

        return $picked !== '' ? $picked : $stored;
    }

    public function shops(): BelongsToMany
    {
        return $this->belongsToMany(Store::class, 'payment_method_store')->withPivot('is_enabled')->withTimestamps();
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
