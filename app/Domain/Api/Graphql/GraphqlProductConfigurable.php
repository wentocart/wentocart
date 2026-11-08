<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Catalog\Models\ProductTemplate;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Services\CombinationService;
use App\Domain\Store\Models\Store;

/**
 */
final class GraphqlProductConfigurable
{
    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function variants(int $productId, array $args): array
    {
        $empty = self::emptyRelay();
        if ($productId < 1) {
            return $empty;
        }
        $product = Product::query()->find($productId);
        if (! $product || ! $product->isCombination()) {
            return $empty;
        }

        $first = max(1, min(100, (int) ($args['first'] ?? $args['limit'] ?? 30)));
        $afterId = self::cursorId((string) ($args['after'] ?? ''), 'var');

        $q = $product->variants()->where('is_enabled', true)->orderBy('position')->orderBy('id');
        $total = (clone $q)->count();
        if ($afterId > 0) {
            $q->where('id', '>', $afterId);
        }
        $rows = $q->with(['attributeValues.attribute.translations', 'attributeValues.translations', 'coverImage'])
            ->limit($first + 1)
            ->get();
        $hasNext = $rows->count() > $first;
        $slice = $rows->take($first);
        $locale = app()->getLocale();
        $nodes = $slice->map(fn (ProductVariant $v) => self::mapVariantAsProduct($v, $product, $locale))->values()->all();
        $edges = $slice->map(fn (ProductVariant $v) => [
            'cursor' => base64_encode('var:'.$v->id),
            'node' => self::mapVariantAsProduct($v, $product, $locale),
        ])->values()->all();
        $end = $slice->last();

        return [
            'edges' => $edges,
            'nodes' => $nodes,
            'items' => $nodes,
            'pageInfo' => [
                'hasNextPage' => $hasNext,
                'hasPreviousPage' => $afterId > 0,
                'startCursor' => $edges[0]['cursor'] ?? null,
                'endCursor' => $end ? base64_encode('var:'.$end->id) : null,
            ],
            'total' => $total,
            'totalCount' => $total,
            'totalItems' => $total,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{edges: list<array{cursor: string, node: array<string, mixed>}>, pageInfo: array<string, mixed>, totalCount: int}
     */
    public static function variantAxes(int $productId, array $args): array
    {
        $empty = [
            'edges' => [],
            'pageInfo' => [
                'hasNextPage' => false,
                'hasPreviousPage' => false,
                'startCursor' => null,
                'endCursor' => null,
            ],
            'totalCount' => 0,
        ];
        if ($productId < 1) {
            return $empty;
        }
        $product = Product::query()->find($productId);
        if (! $product || ! $product->isCombination()) {
            return $empty;
        }

        $locale = app()->getLocale();
        try {
            $axes = app(CombinationService::class)->presentConfig($product);
        } catch (\Throwable) {
            return $empty;
        }

        $first = max(1, min(100, (int) ($args['first'] ?? $args['limit'] ?? 30)));
        $afterId = self::cursorId((string) ($args['after'] ?? ''), 'sa');
        $filtered = collect($axes)
            ->filter(fn (array $axis) => $afterId < 1 || (int) ($axis['attribute_id'] ?? 0) > $afterId)
            ->values();
        $total = count($axes);
        $slice = $filtered->take($first);
        $hasNext = $filtered->count() > $first;

        $edges = $slice->map(function (array $axis) use ($locale) {
            $id = (int) ($axis['attribute_id'] ?? 0);
            $code = (string) ($axis['code'] ?? '');
            $name = (string) ($axis['name'] ?? $code);
            $options = collect($axis['values'] ?? [])->map(fn (array $v) => [
                'id' => (string) ($v['id'] ?? ''),

                'adminName' => (string) ($v['value'] ?? $v['code'] ?? ''),
                'sortOrder' => 0,
                'swatchValue' => $v['color_code'] ?? ($axis['type'] === 'color' ? ($v['code'] ?? null) : null),
                'swatchValueUrl' => null,
                'translation' => [
                    'locale' => $locale,
                    'label' => (string) ($v['value'] ?? $v['code'] ?? ''),
                ],
            ])->values()->all();

            return [
                'cursor' => base64_encode('sa:'.$id),
                'node' => [
                    'id' => (string) $id,

                    'code' => $code,
                    'adminName' => $name,
                    'type' => (string) ($axis['type'] ?? 'select'),
                    'swatchType' => ($axis['type'] ?? '') === 'color' ? 'color' : null,
                    'position' => (int) ($axis['position'] ?? 0),
                    'isRequired' => '1',
                    'isUnique' => '0',
                    'isFilterable' => '1',
                    'isComparable' => '1',
                    'isConfigurable' => '1',
                    'isUserDefined' => '1',
                    'isVisibleOnFront' => '1',
                    'valuePerLocale' => '1',
                    'valuePerStore' => '0',
                    'enableWysiwyg' => '0',
                    'minPrice' => null,
                    'maxPrice' => null,
                    'translation' => [
                        'locale' => $locale,
                        'name' => $name,
                    ],
                    'options' => [
                        'edges' => array_map(fn (array $opt) => [
                            'cursor' => base64_encode('opt:'.$opt['id']),
                            'node' => $opt,
                        ], $options),
                        'pageInfo' => [
                            'hasNextPage' => false,
                            'endCursor' => $options !== [] ? base64_encode('opt:'.$options[array_key_last($options)]['id']) : null,
                        ],
                        'totalCount' => count($options),
                    ],
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
                'endCursor' => $end ? base64_encode('sa:'.(int) ($end['attribute_id'] ?? 0)) : null,
            ],
            'totalCount' => $total,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function productTemplate(int $productId): ?array
    {
        if ($productId < 1) {
            return null;
        }
        $family = ProductTemplate::query()->where('is_default', true)->first()
            ?? ProductTemplate::query()->orderBy('id')->first();
        if (! $family) {
            return [
                'id' => '0',

                'code' => 'default',
                'name' => 'Default',
                'adminName' => 'Default',
            ];
        }

        return [
            'id' => (string) $family->id,

            'code' => (string) $family->code,
            'name' => (string) $family->name,
            'adminName' => (string) $family->name,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{edges: list<array{cursor: string, node: array<string, mixed>}>, pageInfo: array<string, mixed>, totalCount: int}
     */
    public static function stores(int $productId, array $args): array
    {
        $empty = [
            'edges' => [],
            'pageInfo' => [
                'hasNextPage' => false,
                'hasPreviousPage' => false,
                'startCursor' => null,
                'endCursor' => null,
            ],
            'totalCount' => 0,
        ];
        if ($productId < 1) {
            return $empty;
        }
        $product = Product::query()->find($productId);
        if (! $product) {
            return $empty;
        }

        $first = max(1, min(100, (int) ($args['first'] ?? $args['limit'] ?? 30)));
        $afterId = self::cursorId((string) ($args['after'] ?? ''), 'ch');

        $storeIds = $product->storeAssignments()->pluck('store_id')->map(fn ($id) => (int) $id)->all();
        if ($storeIds === []) {
            $default = Store::query()->where('is_enabled', true)->orderByDesc('is_default')->orderBy('id')->first();
            if ($default) {
                $storeIds = [(int) $default->id];
            }
        }

        $q = Store::query()->whereIn('id', $storeIds)->where('is_enabled', true)->orderBy('id');
        $total = (clone $q)->count();
        if ($afterId > 0) {
            $q->where('id', '>', $afterId);
        }
        $rows = $q->limit($first + 1)->get();
        $hasNext = $rows->count() > $first;
        $slice = $rows->take($first);
        $edges = $slice->map(fn (Store $s) => [
            'cursor' => base64_encode('ch:'.$s->id),
            'node' => [
                'id' => (string) $s->id,

                'code' => (string) ($s->code ?? ''),
                'name' => (string) ($s->name ?? ''),
                'hostname' => null,
            ],
        ])->values()->all();
        $end = $slice->last();

        return [
            'edges' => $edges,
            'pageInfo' => [
                'hasNextPage' => $hasNext,
                'hasPreviousPage' => $afterId > 0,
                'startCursor' => $edges[0]['cursor'] ?? null,
                'endCursor' => $end ? base64_encode('ch:'.$end->id) : null,
            ],
            'totalCount' => $total,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapVariantAsProduct(ProductVariant $v, Product $parent, string $locale): array
    {
        $parentName = $parent->translation($locale)?->name ?? $parent->sku;
        $labels = $v->attributeValues
            ->map(fn ($av) => $av->translation($locale)?->value ?: $av->code)
            ->filter()
            ->values()
            ->all();
        $name = $labels !== [] ? $parentName.' - '.implode(' / ', $labels) : ($v->sku ?: $parentName);
        $price = null;
        try {
            $price = (float) $v->sellingPrice();
        } catch (\Throwable) {
            $price = (float) ($v->price ?? $parent->price ?? 0);
        }
        $cover = $v->coverImage?->url ?? ($v->cover_path ? '/storage/'.ltrim((string) $v->cover_path, '/') : null);

        return [
            'id' => (string) $v->id,

            'sku' => (string) ($v->sku ?? ''),
            'name' => $name,
            'slug' => null,
            
            'url' => null,
            'price' => $price,
            'compare_price' => null,
            'specialPrice' => $v->sale_price !== null ? (float) $v->sale_price : null,
            'in_stock' => $v->inStockFor(1),
            'image' => $cover,
            'baseImageUrl' => $cover,
            'description' => null,
            'short_description' => null,
            'shortDescription' => null,
            'type' => 'simple',
            'status' => $v->is_enabled ? 1 : 0,
            'metaTitle' => null,
            'metaDescription' => null,
            'metaKeywords' => null,
            'new' => false,
            'featured' => false,
            'isInWishlist' => '0',
            'isInCompare' => '0',
            'listing' => [
                'id' => (string) $v->id,
                'url' => null,
                'metaTitle' => null,
                'metaDescription' => null,
                'metaKeywords' => null,
                'new' => false,
                'featured' => false,
                'status' => $v->is_enabled ? 1 : 0,
            ],
            'images' => [
                'edges' => $cover ? [[
                    'cursor' => base64_encode('vimg:'.$v->id),
                    'node' => [
                        'id' => (string) $v->id,
                        'url' => $cover,
                        'path' => $v->cover_path,
                        'publicPath' => $v->cover_path,
                        'alt' => $name,
                        'position' => 0,
                        'type' => 'image',
                    ],
                ]] : [],
                'totalCount' => $cover ? 1 : 0,
            ],
            'variantAxisOptions' => null,
            'combinations' => null,
            'bookingType' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function emptyRelay(): array
    {
        return [
            'edges' => [],
            'nodes' => [],
            'items' => [],
            'pageInfo' => [
                'hasNextPage' => false,
                'hasPreviousPage' => false,
                'startCursor' => null,
                'endCursor' => null,
            ],
            'total' => 0,
            'totalCount' => 0,
            'totalItems' => 0,
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
