<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductQtyPrice;
use App\Domain\Catalog\Models\ProductTranslation;
use App\Domain\Catalog\Models\WarehouseStock;

/**
 * inventories / customerGroupPrices / translations.
 */
final class GraphqlAdminProductDetail
{
    /**
     * @param  array<string, mixed>  $args
     * @return array{edges: list<array{cursor: string, node: array<string, mixed>}>, pageInfo: array<string, mixed>, totalCount: int}
     */
    public static function inventories(int $productId, array $args): array
    {
        $empty = self::emptyConnection();
        if ($productId < 1 || ! Product::query()->whereKey($productId)->exists()) {
            return $empty;
        }

        $first = max(1, min(100, (int) ($args['first'] ?? $args['limit'] ?? 30)));
        $afterId = self::cursorId((string) ($args['after'] ?? ''), 'inv');

        $q = WarehouseStock::query()
            ->with('warehouse')
            ->where('product_id', $productId)
            ->orderBy('id');
        $total = (clone $q)->count();
        if ($afterId > 0) {
            $q->where('id', '>', $afterId);
        }
        $rows = $q->limit($first + 1)->get();
        $hasNext = $rows->count() > $first;
        $slice = $rows->take($first);
        $edges = $slice->map(function (WarehouseStock $row) {
            $qty = (float) $row->qty;
            $reserved = (float) $row->reserved_qty;

            return [
                'cursor' => base64_encode('inv:'.$row->id),
                'node' => [
                    'id' => (string) $row->id,

                    'sourceId' => (int) $row->warehouse_id,
                    'sourceCode' => $row->warehouse?->code,
                    'qty' => $qty,
                    'quantity' => $qty,
                    'product_id' => (string) $row->product_id,
                    'warehouse_id' => (string) $row->warehouse_id,
                    'warehouse_code' => $row->warehouse?->code,
                    'reserved_qty' => $reserved,
                    'available_qty' => max(0, $qty - $reserved),
                ],
            ];
        })->values()->all();
        $end = $slice->last();

        return [
            'edges' => $edges,
            'pageInfo' => [
                'hasNextPage' => $hasNext,
                'hasPreviousPage' => $afterId > 0,
                'startCursor' => $edges[0]['cursor'] ?? null,
                'endCursor' => $end ? base64_encode('inv:'.$end->id) : null,
            ],
            'totalCount' => $total,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{edges: list<array{cursor: string, node: array<string, mixed>}>, pageInfo: array<string, mixed>, totalCount: int}
     */
    public static function customerGroupPrices(int $productId, array $args): array
    {
        $empty = self::emptyConnection();
        if ($productId < 1 || ! Product::query()->whereKey($productId)->exists()) {
            return $empty;
        }

        $first = max(1, min(100, (int) ($args['first'] ?? $args['limit'] ?? 30)));
        $afterId = self::cursorId((string) ($args['after'] ?? ''), 'cgp');

        $q = ProductQtyPrice::query()->where('product_id', $productId)->orderBy('id');
        $total = (clone $q)->count();
        if ($afterId > 0) {
            $q->where('id', '>', $afterId);
        }
        $rows = $q->limit($first + 1)->get();
        $hasNext = $rows->count() > $first;
        $slice = $rows->take($first);
        $edges = $slice->map(fn (ProductQtyPrice $row) => [
            'cursor' => base64_encode('cgp:'.$row->id),
            'node' => [
                'id' => (string) $row->id,

                'customerGroupId' => null,
                'customerGroupName' => null,
                'qty' => (float) $row->min_qty,
                'valueType' => 'fixed',
                'value' => (float) $row->price,
                'uniqueId' => (string) $row->id,
                'minQty' => (float) $row->min_qty,
                'maxQty' => $row->max_qty !== null ? (float) $row->max_qty : null,
                'price' => (float) $row->price,
                'position' => (int) ($row->position ?? 0),
            ],
        ])->values()->all();
        $end = $slice->last();

        return [
            'edges' => $edges,
            'pageInfo' => [
                'hasNextPage' => $hasNext,
                'hasPreviousPage' => $afterId > 0,
                'startCursor' => $edges[0]['cursor'] ?? null,
                'endCursor' => $end ? base64_encode('cgp:'.$end->id) : null,
            ],
            'totalCount' => $total,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{edges: list<array{cursor: string, node: array<string, mixed>}>, pageInfo: array<string, mixed>, totalCount: int}
     */
    public static function translations(int $productId, array $args): array
    {
        $empty = self::emptyConnection();
        if ($productId < 1) {
            return $empty;
        }
        $product = Product::query()->with('translations')->find($productId);
        if (! $product) {
            return $empty;
        }

        $first = max(1, min(100, (int) ($args['first'] ?? $args['limit'] ?? 30)));
        $afterId = self::cursorId((string) ($args['after'] ?? ''), 'ptr');

        $all = $product->translations->sortBy('id')->values();
        $total = $all->count();
        $filtered = $all->filter(fn (ProductTranslation $t) => $afterId < 1 || (int) $t->id > $afterId)->values();
        $slice = $filtered->take($first);
        $hasNext = $filtered->count() > $first;
        $edges = $slice->map(fn (ProductTranslation $t) => [
            'cursor' => base64_encode('ptr:'.$t->id),
            'node' => [
                'id' => (string) $t->id,

                'locale' => (string) $t->language_code,
                'name' => $t->name,
                'description' => $t->description,
                'shortDescription' => $t->short_description,
                'slug' => $t->slug,
                'metaTitle' => $t->meta_title,
                'metaDescription' => $t->meta_description,
                'metaKeywords' => $t->meta_keywords,
            ],
        ])->values()->all();
        $end = $slice->last();

        return [
            'edges' => $edges,
            'pageInfo' => [
                'hasNextPage' => $hasNext,
                'hasPreviousPage' => $afterId > 0,
                'startCursor' => $edges[0]['cursor'] ?? null,
                'endCursor' => $end ? base64_encode('ptr:'.$end->id) : null,
            ],
            'totalCount' => $total,
        ];
    }

    /**
     * @return array{edges: list<mixed>, pageInfo: array<string, mixed>, totalCount: int}
     */
    protected static function emptyConnection(): array
    {
        return [
            'edges' => [],
            'pageInfo' => [
                'hasNextPage' => false,
                'hasPreviousPage' => false,
                'startCursor' => null,
                'endCursor' => null,
            ],
            'totalCount' => 0,
        ];
    }

    protected static function cursorId(string $after, string $prefix): int
    {
        if ($after === '') {
            return 0;
        }
        $decoded = base64_decode($after, true);
        if (is_string($decoded) && preg_match('/^'.preg_quote($prefix, '/').':(\d+)$/', $decoded, $m)) {
            return (int) $m[1];
        }

        return 0;
    }
}
