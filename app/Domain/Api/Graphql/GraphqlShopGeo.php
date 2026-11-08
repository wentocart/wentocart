<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Localization\Models\Country;
use App\Domain\Localization\Models\Currency;
use App\Domain\Localization\Models\Language;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Store\Models\Store;

/**
 * Shop REST countries / locales / paymentMethods Relay connections.
 */
final class GraphqlShopGeo
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function countriesList(): array
    {
        return collect(Country::optionsForAddresses())
            ->map(fn (array $row) => self::mapCountry($row))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{edges: list<array{cursor: string, node: array<string, mixed>}>, pageInfo: array<string, mixed>, totalCount: int}
     */
    public static function countriesConnection(array $args): array
    {
        $all = self::countriesList();
        $first = max(1, min(300, (int) ($args['first'] ?? $args['limit'] ?? 100)));
        $after = self::cursorIndex((string) ($args['after'] ?? ''), 'cty');
        $total = count($all);
        $slice = array_slice($all, $after, $first);
        $edges = [];
        foreach ($slice as $i => $node) {
            $idx = $after + $i + 1;
            $edges[] = [
                'cursor' => base64_encode('cty:'.$idx),
                'node' => $node,
            ];
        }

        return [
            'edges' => $edges,
            'pageInfo' => [
                'hasNextPage' => ($after + $first) < $total,
                'hasPreviousPage' => $after > 0,
                'startCursor' => $edges[0]['cursor'] ?? null,
                'endCursor' => $edges !== [] ? $edges[array_key_last($edges)]['cursor'] : null,
            ],
            'totalCount' => $total,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function localesList(): array
    {
        return Language::enabledList()->map(fn (Language $lang) => [
            'id' => (string) $lang->id,

            'code' => (string) $lang->code,
            'name' => $lang->name,
            'is_default' => (bool) $lang->is_default,
        ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{edges: list<array{cursor: string, node: array<string, mixed>}>, pageInfo: array<string, mixed>, totalCount: int}
     */
    public static function localesConnection(array $args): array
    {
        return self::listToConnection(self::localesList(), $args, 'loc');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function paymentMethodsList(): array
    {
        return PaymentMethod::query()
            ->where('is_enabled', true)
            ->whereNull('seller_id')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (PaymentMethod $m) => [
                'id' => (string) $m->id,

                'code' => $m->code,
                'title' => $m->name,
                'description' => $m->description,
                'driver' => $m->driver,
                'image' => null,
                'sortOrder' => (int) ($m->position ?? 0),
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{edges: list<array{cursor: string, node: array<string, mixed>}>, pageInfo: array<string, mixed>, totalCount: int}
     */
    public static function paymentMethodsConnection(array $args): array
    {
        return self::listToConnection(self::paymentMethodsList(), $args, 'pay');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function currenciesList(): array
    {
        return Currency::query()
            ->where('is_enabled', true)
            ->orderByDesc('is_default')
            ->orderBy('position')
            ->orderBy('code')
            ->get()
            ->map(fn (Currency $c) => [
                'id' => (string) $c->id,
                'code' => (string) $c->code,
                'name' => $c->name,
                'symbol' => $c->symbol,
                'exchange_rate' => $c->exchange_rate !== null ? (float) $c->exchange_rate : null,
                'is_default' => (bool) $c->is_default,
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{edges: list<array{cursor: string, node: array<string, mixed>}>, pageInfo: array<string, mixed>, totalCount: int}
     */
    public static function currenciesConnection(array $args): array
    {
        return self::listToConnection(self::currenciesList(), $args, 'cur');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function storesList(): array
    {
        return Store::query()
            ->where('is_enabled', true)
            ->with('urls')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get()
            ->map(function (Store $store) {
                return GraphqlHomeModules::store(['id' => (string) $store->id]);
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{edges: list<array{cursor: string, node: array<string, mixed>}>, pageInfo: array<string, mixed>, totalCount: int}
     */
    public static function storesConnection(array $args): array
    {
        return self::listToConnection(self::storesList(), $args, 'str');
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected static function mapCountry(array $row): array
    {
        $code = (string) $row['code'];
        $regions = array_values(array_map(
            fn (array $r) => [
                'id' => (string) ($r['code'] ?? ''),
                'code' => (string) ($r['code'] ?? ''),
                'name' => (string) ($r['name'] ?? ''),
                'defaultName' => (string) ($r['name'] ?? ''),
            ],
            $row['regions'] ?? []
        ));
        $stateEdges = array_map(fn (array $r, int $i) => [
            'cursor' => base64_encode('st:'.$code.':'.($i + 1)),
            'node' => $r,
        ], $regions, array_keys($regions));

        return [
            'id' => $code,

            'code' => $code,
            'name' => (string) $row['name'],
            'calling_code' => $row['calling_code'] ?? null,
            'has_regions' => (bool) ($row['has_regions'] ?? false),
            'regions' => $regions,
            'states' => [
                'edges' => $stateEdges,
                'pageInfo' => [
                    'hasNextPage' => false,
                    'hasPreviousPage' => false,
                    'startCursor' => $stateEdges[0]['cursor'] ?? null,
                    'endCursor' => $stateEdges !== [] ? $stateEdges[array_key_last($stateEdges)]['cursor'] : null,
                ],
                'totalCount' => count($regions),
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $all
     * @param  array<string, mixed>  $args
     * @return array{edges: list<array{cursor: string, node: array<string, mixed>}>, pageInfo: array<string, mixed>, totalCount: int}
     */
    protected static function listToConnection(array $all, array $args, string $prefix): array
    {
        $first = max(1, min(200, (int) ($args['first'] ?? $args['limit'] ?? 50)));
        $after = self::cursorIndex((string) ($args['after'] ?? ''), $prefix);
        $total = count($all);
        $slice = array_slice($all, $after, $first);
        $edges = [];
        foreach ($slice as $i => $node) {
            $idx = $after + $i + 1;
            $edges[] = [
                'cursor' => base64_encode($prefix.':'.$idx),
                'node' => $node,
            ];
        }

        return [
            'edges' => $edges,
            'pageInfo' => [
                'hasNextPage' => ($after + $first) < $total,
                'hasPreviousPage' => $after > 0,
                'startCursor' => $edges[0]['cursor'] ?? null,
                'endCursor' => $edges !== [] ? $edges[array_key_last($edges)]['cursor'] : null,
            ],
            'totalCount' => $total,
        ];
    }

    protected static function cursorIndex(string $after, string $prefix): int
    {
        if ($after === '') {
            return 0;
        }
        $decoded = base64_decode($after, true);
        if (is_string($decoded) && preg_match('/^'.preg_quote($prefix, '/').':(\d+)$/', $decoded, $m)) {
            return max(0, (int) $m[1]);
        }

        return 0;
    }
}
