<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Catalog\Models\Category;
use App\Domain\Settings\CustomerSettings;
use App\Domain\Store\ShopIdentity;
use App\Domain\Store\StoreContext;

/**
 * Store setting rows — Wento setting codes only.
 */
final class GraphqlShopStoreSettings
{
    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    public static function homeCategories(array $args = []): array
    {
        $locale = app()->getLocale();
        $limit = max(1, min(100, (int) ($args['limit'] ?? $args['first'] ?? 24)));

        return Category::query()
            ->where('is_enabled', true)
            ->whereNull('parent_id')
            ->with('translations')
            ->orderBy('position')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (Category $c) => ShopSchema::mapCategory($c, $locale))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultStore(): array
    {
        return GraphqlHomeModules::store([]);
    }

    /**
     * Flat Wento setting rows for the storefront.
     *
     * @param  array<string, mixed>  $args
     * @return array{edges: list<array{cursor: string, node: array<string, mixed>}>, pageInfo: array<string, mixed>, totalCount: int}
     */
    public static function storeSettings(array $args = []): array
    {
        $locale = app()->getLocale();
        $identity = ShopIdentity::forStorefront(app(StoreContext::class)->store(), $locale);
        $store = self::defaultStore();

        $rows = [
            ['code' => 'shop.weight_unit', 'value' => 'kg'],
            ['code' => 'shop.name', 'value' => (string) ($identity['name'] ?? '')],
            ['code' => 'shop.email', 'value' => (string) ($identity['email'] ?? '')],
            ['code' => 'shop.currency', 'value' => (string) (config('wento.default_currency') ?: 'USD')],
            ['code' => 'shop.locale', 'value' => $locale],
            ['code' => 'shop.store_code', 'value' => (string) ($store['code'] ?? 'default')],
            ['code' => 'checkout.guest_checkout', 'value' => CustomerSettings::guestCheckout() ? '1' : '0'],
            ['code' => 'customer.registration_enabled', 'value' => CustomerSettings::registrationEnabled() ? '1' : '0'],
        ];

        $codeFilter = trim((string) ($args['code'] ?? $args['slug'] ?? ''));
        if ($codeFilter !== '') {
            $rows = array_values(array_filter(
                $rows,
                fn (array $r) => str_starts_with($r['code'], $codeFilter) || $r['code'] === $codeFilter
            ));
        }

        $first = max(1, min(100, (int) ($args['first'] ?? $args['limit'] ?? 50)));
        $after = trim((string) ($args['after'] ?? ''));
        $offset = 0;
        if ($after !== '') {
            $decoded = base64_decode($after, true);
            if (is_string($decoded) && preg_match('/^cfg:(\d+)$/', $decoded, $m)) {
                $offset = (int) $m[1] + 1;
            }
        }

        $total = count($rows);
        $slice = array_slice($rows, $offset, $first + 1);
        $hasNext = count($slice) > $first;
        $slice = array_slice($slice, 0, $first);
        $edges = [];
        foreach ($slice as $i => $row) {
            $idx = $offset + $i;
            $edges[] = [
                'cursor' => base64_encode('cfg:'.$idx),
                'node' => [
                    'id' => base64_encode($row['code']),
                    'code' => $row['code'],
                    'value' => $row['value'],
                    'storeCode' => (string) ($store['code'] ?? null),
                    'localeCode' => $locale,
                ],
            ];
        }
        $end = $edges !== [] ? $edges[array_key_last($edges)] : null;

        return [
            'edges' => $edges,
            'pageInfo' => [
                'hasNextPage' => $hasNext,
                'endCursor' => $end['cursor'] ?? null,
            ],
            'totalCount' => $total,
        ];
    }
}
