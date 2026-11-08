<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Catalog\Services\StorefrontCatalogCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Short-TTL cache for anonymous Shop GraphQL catalog reads.
 * Response cache for headless clients. No edge proxy required.
 * Also emits Cache-Control / ETag / Vary so CDNs and clients can short-circuit with 304.
 */
final class GraphqlShopResponseCache
{
    public const TTL_SECONDS = 60;

    public const HEADER = 'X-Wento-GQL-Cache';

    /**
     * Mutations, authenticated customers, and introspection are never cached.
     */
    public static function eligible(Request $request, string $query): bool
    {
        if (Auth::guard('customer')->check()) {
            return false;
        }
        $trimmed = ltrim($query);
        if ($trimmed === '' || str_starts_with($trimmed, 'mutation') || str_contains($query, 'mutation ')) {
            return false;
        }
        if (str_contains($query, '__schema') || str_contains($query, '__type')) {
            return false;
        }

        return true;
    }

    public static function key(Request $request, string $query, ?array $variables, ?string $operation): string
    {
        $locale = app()->getLocale();
        $currency = (string) $request->attributes->get('wento.currency', $request->session()->get('currency', ''));
        $storeKey = (string) $request->attributes->get('wento.store', '');
        $storefront = (string) $request->header('X-STOREFRONT-KEY', '');

        return 'gql.shop.'.hash('xxh128', json_encode([
            'q' => $query,
            'v' => $variables ?? [],
            'o' => $operation ?? '',
            'l' => $locale,
            'c' => $currency,
            'st' => $storeKey,
            'k' => substr(hash('sha256', $storefront), 0, 16),
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  callable(): array<string, mixed>  $callback
     * @return array{0: array<string, mixed>, 1: string}
     */
    public static function remember(Request $request, string $query, ?array $variables, ?string $operation, callable $callback): array
    {
        if (! self::eligible($request, $query)) {
            return [$callback(), 'BYPASS'];
        }

        $suffix = self::key($request, $query, $variables, $operation);
        $cacheKey = StorefrontCatalogCache::key($suffix);
        $hit = Cache::has($cacheKey);
        $payload = StorefrontCatalogCache::remember($suffix, self::TTL_SECONDS, $callback);

        return [$payload, $hit ? 'HIT' : 'MISS'];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function applyHttpCacheHeaders(JsonResponse $response, Request $request, string $query, array $payload, string $cacheState): JsonResponse
    {
        if ($cacheState === 'BYPASS' || ! self::eligible($request, $query)) {
            $response->headers->set('Cache-Control', 'private, no-store');

            return $response;
        }

        $etag = '"'.hash('xxh128', json_encode($payload, JSON_UNESCAPED_UNICODE)).'"';
        $response->headers->set('ETag', $etag);
        $response->headers->set('Cache-Control', 'public, max-age='.self::TTL_SECONDS.', stale-while-revalidate=30');
        $response->headers->set('Vary', 'Accept-Encoding, X-STOREFRONT-KEY, X-Wento-Locale, X-Wento-Currency, X-Store');

        $clientEtag = trim((string) $request->header('If-None-Match', ''));
        if ($clientEtag !== '' && hash_equals($etag, $clientEtag)) {
            return response()->json(null, 304)
                ->header(self::HEADER, $cacheState)
                ->header('ETag', $etag)
                ->header('Cache-Control', 'public, max-age='.self::TTL_SECONDS.', stale-while-revalidate=30')
                ->header('Vary', 'Accept-Encoding, X-STOREFRONT-KEY, X-Wento-Locale, X-Wento-Currency, X-Store');
        }

        return $response;
    }
}
