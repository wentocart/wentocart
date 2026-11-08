<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Settings\ProductSettings;
use GraphQL\Error\UserError;

/**
 * Shop REST categories + compareItems cursor connections.
 */
final class GraphqlCatalogConnections
{
    /**
     * @param  array<string, mixed>  $args
     * @return array{edges: list<array{cursor: string, node: array<string, mixed>}>, pageInfo: array<string, mixed>, totalCount: int}
     */
    public static function categories(array $args): array
    {
        $locale = app()->getLocale();
        $first = max(1, min(100, (int) ($args['first'] ?? $args['limit'] ?? 24)));
        $afterId = 0;
        $after = trim((string) ($args['after'] ?? ''));
        if ($after !== '') {
            $decoded = base64_decode($after, true);
            if (is_string($decoded) && preg_match('/^cat:(\d+)$/', $decoded, $m)) {
                $afterId = (int) $m[1];
            }
        }

        $q = Category::query()
            ->where('is_enabled', true)
            ->with('translations')
            ->orderBy('position')
            ->orderBy('id');
        $total = (clone $q)->count();
        if ($afterId > 0) {
            $q->where('id', '>', $afterId);
        }
        $rows = $q->limit($first + 1)->get();
        $hasNext = $rows->count() > $first;
        $slice = $rows->take($first);
        $edges = $slice->map(function (Category $c) use ($locale) {
            return [
                'cursor' => base64_encode('cat:'.$c->id),
                'node' => ShopSchema::mapCategory($c, $locale),
            ];
        })->values()->all();
        $end = $slice->last();

        return [
            'edges' => $edges,
            'pageInfo' => [
                'hasNextPage' => $hasNext,
                'endCursor' => $end ? base64_encode('cat:'.$end->id) : null,
            ],
            'totalCount' => $total,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{edges: list<array{cursor: string, node: array<string, mixed>}>, pageInfo: array<string, mixed>, totalCount: int}
     */
    public static function compareItems(array $args): array
    {
        if (! ProductSettings::compareEnabled()) {
            return [
                'edges' => [],
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'totalCount' => 0,
            ];
        }

        $customer = auth('customer')->user();
        if (! $customer instanceof Customer) {
            throw new UserError('Authentication required.');
        }

        $locale = app()->getLocale();
        $first = max(1, min(100, (int) ($args['first'] ?? $args['limit'] ?? 20)));
        $afterId = 0;
        $after = trim((string) ($args['after'] ?? ''));
        if ($after !== '') {
            $decoded = base64_decode($after, true);
            if (is_string($decoded) && preg_match('/^cmp:(\d+)$/', $decoded, $m)) {
                $afterId = (int) $m[1];
            }
        }

        $idList = self::compareIds($customer);
        $total = count($idList);
        if ($afterId > 0) {
            $idList = array_values(array_filter($idList, fn (int $id) => $id > $afterId));
        }
        $sliceIds = array_slice($idList, 0, $first + 1);
        $hasNext = count($sliceIds) > $first;
        $sliceIds = array_slice($sliceIds, 0, $first);

        $products = Product::query()
            ->enabled()
            ->whereIn('id', $sliceIds)
            ->with(['translations', 'brand', 'images'])
            ->get()
            ->keyBy('id');

        $edges = [];
        foreach ($sliceIds as $pid) {
            $p = $products->get($pid);
            if (! $p) {
                continue;
            }
            $edges[] = [
                'cursor' => base64_encode('cmp:'.$pid),
                'node' => [
                    'id' => (string) $pid,

                    'product' => ShopSchema::mapProduct($p, $locale),
                    'customer' => [
                        'id' => (string) $customer->id,
                        'email' => $customer->email,
                        'firstName' => $customer->first_name,
                        'lastName' => $customer->last_name,
                    ],
                    'createdAt' => null,
                    'updatedAt' => null,
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

    /**
     * Shop REST → compareItem(id).
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>|null
     */
    public static function compareItem(array $args): ?array
    {
        if (! ProductSettings::compareEnabled()) {
            return null;
        }

        $customer = auth('customer')->user();
        if (! $customer instanceof Customer) {
            throw new UserError('Authentication required.');
        }

        $id = (int) ($args['id'] ?? $args['productId'] ?? 0);
        if ($id < 1) {
            return null;
        }
        if (! in_array($id, self::compareIds($customer), true)) {
            return null;
        }

        $locale = app()->getLocale();
        $product = Product::query()
            ->enabled()
            ->whereKey($id)
            ->with(['translations', 'brand', 'images'])
            ->first();
        if (! $product) {
            return null;
        }

        return [
            'id' => (string) $id,

            'product' => ShopSchema::mapProduct($product, $locale),
            'customer' => [
                'id' => (string) $customer->id,
                'email' => $customer->email,
                'firstName' => $customer->first_name,
                'lastName' => $customer->last_name,
            ],
            'createdAt' => null,
            'updatedAt' => null,
        ];
    }

    /**
     * @return list<int>
     */
    protected static function compareIds(Customer $customer): array
    {
        $raw = session()->get('wento_compare_ids', []);
        $profile = is_array($customer->profile) ? $customer->profile : [];
        if (is_array($profile['compare_ids'] ?? null) && $profile['compare_ids'] !== []) {
            $raw = $profile['compare_ids'];
        }
        if (! is_array($raw)) {
            return [];
        }

        return collect($raw)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }
}
