<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Feature;
use App\Domain\Catalog\Models\FeatureValue;
use App\Domain\Catalog\Models\Product;
use GraphQL\Error\UserError;

/**
 * Shop REST attributes / attribute / categoryAttributeFilters surface
 * mapped from Wentocart Features (filterable facets).
 */
final class GraphqlShopAttributes
{
    /**
     * @param  array<string, mixed>  $args
     * @return array{edges: list<array{cursor: string, node: array<string, mixed>}>, pageInfo: array<string, mixed>, totalCount: int}
     */
    public static function attributes(array $args): array
    {
        $locale = app()->getLocale();
        $first = max(1, min(100, (int) ($args['first'] ?? $args['limit'] ?? 10)));
        $afterId = self::cursorId((string) ($args['after'] ?? ''), 'attr');

        $q = Feature::query()
            ->where('is_enabled', true)
            ->with(['translations', 'values' => fn ($v) => $v->where('is_enabled', true)->with('translations')])
            ->orderBy('position')
            ->orderBy('id');
        $total = (clone $q)->count();
        if ($afterId > 0) {
            $q->where('id', '>', $afterId);
        }
        $rows = $q->limit($first + 1)->get();
        $hasNext = $rows->count() > $first;
        $slice = $rows->take($first);
        $edges = $slice->map(fn (Feature $f) => [
            'cursor' => base64_encode('attr:'.$f->id),
            'node' => self::mapFeature($f, $locale),
        ])->values()->all();
        $end = $slice->last();

        return [
            'edges' => $edges,
            'pageInfo' => [
                'hasNextPage' => $hasNext,
                'hasPreviousPage' => $afterId > 0,
                'startCursor' => $edges[0]['cursor'] ?? null,
                'endCursor' => $end ? base64_encode('attr:'.$end->id) : null,
            ],
            'totalCount' => $total,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function attribute(array $args): array
    {
        $locale = app()->getLocale();
        $code = trim((string) ($args['code'] ?? ''));
        $id = (int) ($args['id'] ?? 0);
        $q = Feature::query()->where('is_enabled', true)
            ->with(['translations', 'values' => fn ($v) => $v->where('is_enabled', true)->with('translations')]);
        if ($code !== '') {
            $q->where('code', $code);
        } elseif ($id > 0) {
            $q->whereKey($id);
        } else {
            throw new UserError('Attribute code or id is required.');
        }
        $row = $q->first();
        if (! $row) {
            throw new UserError('Attribute not found.');
        }

        return self::mapFeature($row, $locale);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{edges: list<array{cursor: string, node: array<string, mixed>}>, pageInfo: array<string, mixed>, totalCount: int}
     */
    public static function categoryAttributeFilters(array $args): array
    {
        $locale = app()->getLocale();
        $first = max(1, min(100, (int) ($args['first'] ?? 30)));
        $afterId = self::cursorId((string) ($args['after'] ?? ''), 'caf');
        $categoryId = (int) ($args['categoryId'] ?? 0);
        $slug = trim((string) ($args['categorySlug'] ?? ''));

        $productScope = Product::query()->enabled();
        if ($slug !== '') {
            $category = Category::query()
                ->where('is_enabled', true)
                ->whereHas('translations', fn ($t) => $t->where('slug', $slug))
                ->first();
            if ($category) {
                $categoryId = (int) $category->id;
            }
        }
        if ($categoryId > 0) {
            $productScope->where(function ($inner) use ($categoryId) {
                $inner->where('default_category_id', $categoryId)
                    ->orWhereHas('categories', fn ($c) => $c->where('categories.id', $categoryId));
            });
        }

        $minPrice = (clone $productScope)->min('price');
        $maxPrice = (clone $productScope)->max('price');

        $q = Feature::query()
            ->where('is_enabled', true)
            ->where('is_filterable', true)
            ->with([
                'translations',
                'values' => function ($qv) use ($productScope) {
                    $qv->where('is_enabled', true)
                        ->whereHas('products', fn ($p) => $p->whereIn('products.id', (clone $productScope)->select('products.id')))
                        ->with('translations')
                        ->orderBy('position');
                },
            ])
            ->orderBy('position')
            ->orderBy('id');

        $all = $q->get()->filter(fn (Feature $f) => $f->values->isNotEmpty())->values();
        $total = $all->count();
        $filtered = $all->filter(fn (Feature $f) => $afterId < 1 || $f->id > $afterId)->values();
        $slice = $filtered->take($first + 1);
        $hasNext = $slice->count() > $first;
        $slice = $slice->take($first);

        $edges = $slice->map(function (Feature $f) use ($locale, $minPrice, $maxPrice) {
            $node = self::mapFeature($f, $locale);
            $node['minPrice'] = $minPrice !== null ? (string) $minPrice : null;
            $node['maxPrice'] = $maxPrice !== null ? (string) $maxPrice : null;

            return [
                'cursor' => base64_encode('caf:'.$f->id),
                'node' => $node,
            ];
        })->values()->all();
        $end = $slice->last();

        return [
            'edges' => $edges,
            'pageInfo' => [
                'hasNextPage' => $hasNext,
                'hasPreviousPage' => $afterId > 0,
                'startCursor' => $edges[0]['cursor'] ?? null,
                'endCursor' => $end ? base64_encode('caf:'.$end->id) : null,
            ],
            'totalCount' => $total,
        ];
    }

    /**
     * Apply products(filter: "{\"color\":\"1,2\",\"price_from\":10}") JSON.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Domain\Catalog\Models\Product>  $query
     * @param  array<string, mixed>  $args
     */
    public static function applyProductFilter($query, array $args): void
    {
        $raw = $args['filter'] ?? null;
        if (is_array($raw)) {
            $decoded = $raw;
        } elseif (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                throw new UserError('filter must be a JSON object string.');
            }
        } else {
            return;
        }

        if (isset($decoded['category_id']) || isset($decoded['categoryId'])) {
            $categoryId = (int) ($decoded['category_id'] ?? $decoded['categoryId']);
            if ($categoryId > 0) {
                $query->where(function ($inner) use ($categoryId) {
                    $inner->where('default_category_id', $categoryId)
                        ->orWhereHas('categories', fn ($c) => $c->where('categories.id', $categoryId));
                });
            }
        }

        $priceFrom = $decoded['price_from'] ?? $decoded['priceFrom'] ?? null;
        $priceTo = $decoded['price_to'] ?? $decoded['priceTo'] ?? null;
        if ($priceFrom !== null && $priceFrom !== '') {
            $query->where('price', '>=', (float) $priceFrom);
        }
        if ($priceTo !== null && $priceTo !== '') {
            $query->where('price', '<=', (float) $priceTo);
        }

        $reserved = [
            'category_id', 'categoryId', 'price_from', 'priceFrom', 'price_to', 'priceTo',
            'new', 'featured', 'status',
        ];
        foreach ($decoded as $code => $value) {
            if (in_array($code, $reserved, true) || ! is_string($code) || $code === '') {
                continue;
            }
            $ids = self::parseOptionIds($value);
            if ($ids === []) {
                continue;
            }
            $query->whereHas('featureValues', function ($fq) use ($code, $ids) {
                $fq->whereIn('feature_values.id', $ids)
                    ->whereHas('feature', fn ($f) => $f->where('code', $code));
            });
        }

        if (! empty($decoded['new'])) {
            $query->where('is_new', true);
        }
        if (! empty($decoded['featured'])) {
            // Wentocart uses is_best_seller / is_on_sale as “featured” signals when no is_featured column.
            $query->where(function ($inner) {
                $inner->where('is_best_seller', true)->orWhere('is_on_sale', true)->orWhere('is_new', true);
            });
        }
    }

    /**
     *
     * @param  array<string, mixed>  $args
     * @return array{edges: list<array{cursor: string, node: array<string, mixed>}>, pageInfo: array<string, mixed>, totalCount: int}
     */
    public static function productAttributeValues(int $productId, array $args): array
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

        $locale = app()->getLocale();
        $first = max(1, min(100, (int) ($args['first'] ?? $args['limit'] ?? 30)));
        $afterId = self::cursorId((string) ($args['after'] ?? ''), 'pav');

        $q = $product->featureValues()
            ->with(['translations', 'feature.translations'])
            ->orderBy('feature_values.id');
        $total = (clone $q)->count();
        if ($afterId > 0) {
            $q->where('feature_values.id', '>', $afterId);
        }
        $rows = $q->limit($first + 1)->get();
        $hasNext = $rows->count() > $first;
        $slice = $rows->take($first);
        $edges = $slice->map(function (FeatureValue $v) use ($locale) {
            $feature = $v->feature;
            $label = $v->translation($locale)?->value ?? $v->code;
            $adminName = $feature?->translation($locale)?->name ?? $feature?->code;

            return [
                'cursor' => base64_encode('pav:'.$v->id),
                'node' => [
                    'id' => (string) $v->id,

                    'value' => $label,
                    'attribute' => [
                        'id' => $feature ? (string) $feature->id : null,

                        'code' => $feature?->code,
                        'adminName' => $adminName,
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
                'endCursor' => $end ? base64_encode('pav:'.$end->id) : null,
            ],
            'totalCount' => $total,
        ];
    }

    /**
     *
     * @param  array<string, mixed>  $args
     * @return array{edges: list<array{cursor: string, node: array<string, mixed>}>, pageInfo: array<string, mixed>, totalCount: int}
     */
    public static function productAttributes(int $productId, array $args): array
    {
        $base = self::productAttributeValues($productId, $args);
        $base['edges'] = array_map(function (array $edge) {
            $node = $edge['node'] ?? [];
            $attr = is_array($node['attribute'] ?? null) ? $node['attribute'] : [];

            return [
                'cursor' => $edge['cursor'],
                'node' => [
                    'id' => (string) ($node['id'] ?? ''),

                    'code' => (string) ($attr['code'] ?? ''),
                    'label' => (string) ($attr['adminName'] ?? $attr['code'] ?? ''),
                    'value' => (string) ($node['value'] ?? ''),
                ],
            ];
        }, $base['edges']);

        return $base;
    }

    /**
     * @return array<string, mixed>
     */
    public static function mapFeature(Feature $f, string $locale): array
    {
        $t = $f->translation($locale);
        $name = $t?->name ?? $f->code;
        $swatchType = $f->type === 'color' ? 'color' : null;
        $options = $f->values->map(fn (FeatureValue $v) => self::mapOption($v, $locale, $swatchType))->values()->all();

        return [
            'id' => (string) $f->id,

            'code' => (string) $f->code,
            'adminName' => $name,
            'type' => $f->type === 'color' ? 'select' : (string) $f->type,
            'swatchType' => $swatchType,
            'position' => (int) ($f->position ?? 0),
            'isRequired' => '0',
            'isUnique' => '0',
            'isFilterable' => $f->is_filterable ? '1' : '0',
            'isComparable' => '1',
            'isConfigurable' => $f->used_for_combinations ? '1' : '0',
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
                    'cursor' => base64_encode('opt:'.$opt['_id']),
                    'node' => $opt,
                ], $options),
                'pageInfo' => [
                    'hasNextPage' => false,
                    'endCursor' => $options !== [] ? base64_encode('opt:'.$options[array_key_last($options)]['_id']) : null,
                ],
                'totalCount' => count($options),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapOption(FeatureValue $v, string $locale, ?string $swatchType): array
    {
        $label = $v->translation($locale)?->value ?? $v->code;

        return [
            'id' => (string) $v->id,

            'adminName' => $label,
            'sortOrder' => (int) ($v->position ?? 0),
            'swatchValue' => $swatchType === 'color' ? ($v->code ?: null) : null,
            'swatchValueUrl' => null,
            'translation' => [
                'locale' => $locale,
                'label' => $label,
            ],
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

    /**
     * @return list<int>
     */
    protected static function parseOptionIds(mixed $value): array
    {
        if (is_int($value) || is_float($value)) {
            return [(int) $value];
        }
        if (is_array($value)) {
            return collect($value)->map(fn ($v) => (int) $v)->filter(fn (int $id) => $id > 0)->values()->all();
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        return collect(explode(',', $value))
            ->map(fn ($v) => (int) trim($v))
            ->filter(fn (int $id) => $id > 0)
            ->values()
            ->all();
    }
}
