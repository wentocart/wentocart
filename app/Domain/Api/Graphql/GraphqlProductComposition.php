<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductComponent;
use App\Domain\Catalog\Models\ProductCustomizationField;
use App\Domain\Catalog\Services\CustomizationService;
use App\Domain\Catalog\Services\ServiceProductService;

/**
 */
final class GraphqlProductComposition
{
    /**
     * @param  array<string, mixed>  $args
     * @return array{edges: list<array{cursor: string, node: array<string, mixed>}>, pageInfo: array<string, mixed>, totalCount: int}
     */
    public static function customizableOptions(int $productId, array $args): array
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
        $afterId = 0;
        $after = trim((string) ($args['after'] ?? ''));
        if ($after !== '') {
            $decoded = base64_decode($after, true);
            if (is_string($decoded) && preg_match('/^co:(\d+)$/', $decoded, $m)) {
                $afterId = (int) $m[1];
            }
        }

        $q = $product->customizationFields()->orderBy('position')->orderBy('id');
        $total = (clone $q)->count();
        if ($afterId > 0) {
            $q->where('id', '>', $afterId);
        }
        $rows = $q->limit($first + 1)->get();
        $hasNext = $rows->count() > $first;
        $slice = $rows->take($first);
        $presenter = app(CustomizationService::class);

        $edges = $slice->map(function (ProductCustomizationField $field) use ($presenter) {
            $p = $presenter->presentField($field);

            return [
                'cursor' => base64_encode('co:'.$field->id),
                'node' => [
                    'id' => (string) $field->id,

                    'type' => (string) ($p['type'] ?? $field->type),
                    'label' => (string) ($p['label'] ?? $field->label),
                    'isRequired' => (bool) ($p['is_required'] ?? $field->is_required),
                    'sortOrder' => (int) ($p['position'] ?? $field->position ?? 0),
                    'maxCharacters' => isset($p['max_length']) ? (int) $p['max_length'] : null,
                    'price' => (float) ($p['price_amount'] ?? 0),
                    'priceType' => (string) ($p['price_type'] ?? 'none'),
                    'options' => json_encode(array_values($p['options'] ?? []), JSON_UNESCAPED_SLASHES),
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
                'endCursor' => $end ? base64_encode('co:'.$end->id) : null,
            ],
            'totalCount' => $total,
        ];
    }

    /**
     *
     * @param  array<string, mixed>  $args
     * @return array{edges: list<array{cursor: string, node: array<string, mixed>}>, pageInfo: array<string, mixed>, totalCount: int}
     */
    public static function bookingProducts(int $productId, array $args): array
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
        $product = Product::query()->with(['serviceSetting', 'rentalSetting'])->find($productId);
        if (! $product || (! $product->isService() && ! $product->isRental())) {
            return $empty;
        }

        $type = 'default';
        $location = null;
        $qty = null;
        $duration = null;
        $breakTime = null;
        if ($product->isRental()) {
            $type = 'rental';
            $rs = $product->rentalSetting;
            $location = $rs?->return_location;
            $duration = $rs?->default_duration;
        } elseif ($product->isService()) {
            $ss = $product->serviceSetting;
            $stype = (string) ($ss?->service_type ?? 'appointment');
            $type = $stype === 'appointment' ? 'appointment' : 'default';
            $location = is_string($ss?->service_area) ? $ss->service_area : null;
            $duration = $ss?->duration !== null ? (float) $ss->duration : null;
        }

        $starting = null;
        try {
            $starting = (float) $product->sellingPrice();
        } catch (\Throwable) {
            $starting = (float) ($product->price ?? 0);
        }

        $node = [
            'id' => (string) $product->id,

            'type' => $type,
            'qty' => $qty,
            'location' => $location,
            'availableFrom' => null,
            'availableTo' => null,
            'startingPrice' => $starting,
            'formattedStartingPrice' => null,
            'startingRegularPrice' => (float) ($product->price ?? $starting),
            'formattedStartingRegularPrice' => null,
            'duration' => $duration,
            'breakTime' => $breakTime,
            'appointmentSlot' => $type === 'appointment' ? [
                'duration' => $duration,
                'breakTime' => $breakTime,
                'sameSlotAllDays' => true,
                'slots' => null,
            ] : null,
            'rentalSlot' => $type === 'rental' ? [
                'rentingType' => $product->rentalSetting?->rental_unit ?? 'day',
                'dailyPrice' => $type === 'rental' ? $starting : null,
                'hourlyPrice' => null,
                'slots' => null,
            ] : null,
            'defaultSlot' => $type === 'default' ? [
                'bookingType' => 'default',
                'duration' => $duration,
                'breakTime' => $breakTime,
                'slots' => null,
            ] : null,
            'tableSlot' => null,
            'eventTickets' => null,
        ];

        // Optional: enrich from ServiceProductService if available
        try {
            if ($product->isService() && class_exists(ServiceProductService::class)) {
                // leave slots null — clients use bookingSlots(id, date) for live windows
            }
        } catch (\Throwable) {
        }

        return [
            'edges' => [[
                'cursor' => base64_encode('bk:'.$product->id),
                'node' => $node,
            ]],
            'pageInfo' => [
                'hasNextPage' => false,
                'hasPreviousPage' => false,
                'startCursor' => base64_encode('bk:'.$product->id),
                'endCursor' => base64_encode('bk:'.$product->id),
            ],
            'totalCount' => 1,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{edges: list<array{cursor: string, node: array<string, mixed>}>, pageInfo: array<string, mixed>, totalCount: int}
     */
    public static function groupedProducts(int $productId, array $args): array
    {
        return self::componentsConnection($productId, $args, 'grouped');
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{edges: list<array{cursor: string, node: array<string, mixed>}>, pageInfo: array<string, mixed>, totalCount: int}
     */
    public static function bundleOptions(int $productId, array $args): array
    {
        return self::componentsConnection($productId, $args, 'bundle');
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{edges: list<array{cursor: string, node: array<string, mixed>}>, pageInfo: array<string, mixed>, totalCount: int}
     */
    protected static function componentsConnection(int $productId, array $args, string $mode): array
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
        $afterId = 0;
        $after = trim((string) ($args['after'] ?? ''));
        if ($after !== '') {
            $decoded = base64_decode($after, true);
            if (is_string($decoded) && preg_match('/^pc:(\d+)$/', $decoded, $m)) {
                $afterId = (int) $m[1];
            }
        }

        $q = ProductComponent::query()
            ->where('parent_product_id', $productId)
            ->with(['child.translations', 'child.images'])
            ->orderBy('position')
            ->orderBy('id');
        $total = (clone $q)->count();
        if ($afterId > 0) {
            $q->where('id', '>', $afterId);
        }
        $rows = $q->limit($first + 1)->get();
        $hasNext = $rows->count() > $first;
        $slice = $rows->take($first);
        $locale = app()->getLocale();

        $edges = $slice->map(function (ProductComponent $c) use ($locale, $mode) {
            $child = $c->child;
            $node = [
                'id' => (string) $c->id,

                'qty' => (int) ($c->quantity ?? 1),
                'sortOrder' => (int) ($c->position ?? 0),
                'isRequired' => (bool) ($c->is_required ?? false),
                'optionGroup' => $c->option_group,
                'associatedProduct' => $child ? ShopSchema::mapProduct($child, $locale) : null,
            ];
            if ($mode === 'bundle') {
                $node['type'] = $c->is_required ? 'checkbox' : 'select';
                $node['label'] = $c->option_group ?: ($child ? (string) ($child->translation($locale)?->name ?? $child->sku) : null);
            }

            return [
                'cursor' => base64_encode('pc:'.$c->id),
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
                'endCursor' => $end ? base64_encode('pc:'.$end->id) : null,
            ],
            'totalCount' => $total,
        ];
    }
}
