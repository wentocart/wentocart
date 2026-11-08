<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\GraphqlCustomerAuth;
use App\Domain\Api\Graphql\GraphqlSecurityRules;
use App\Domain\Api\Graphql\GraphqlShopResponseCache;
use App\Domain\Api\Graphql\GraphqlTiming;
use App\Domain\Api\Graphql\ShopSchema;
use App\Domain\Localization\Models\Currency;
use App\Domain\Localization\Models\Language;
use App\Http\Controllers\Controller;
use GraphQL\Error\DebugFlag;
use GraphQL\GraphQL;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Throwable;

class GraphqlController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $started = GraphqlTiming::start();

        // X-STOREFRONT-KEY validation + hourly rate limits run in route middleware.
        GraphqlCustomerAuth::bind($request);
        $this->applyStoreContextHeaders($request);

        if ($batchError = GraphqlSecurityRules::assertNotBatched($request)) {
            return GraphqlTiming::withHeaders(response()->json(['errors' => [['message' => $batchError]]], 400), $started);
        }

        $query = (string) $request->input('query', '');
        if ($query === '' && $request->isJson()) {
            $query = (string) $request->json('query', '');
        }
        if ($query === '') {
            return GraphqlTiming::withHeaders(response()->json([
                'errors' => [['message' => 'GraphQL query is required.']],
            ], 400), $started);
        }
        if ($sizeError = GraphqlSecurityRules::assertQuerySize($query)) {
            return GraphqlTiming::withHeaders(response()->json(['errors' => [['message' => $sizeError]]], 400), $started);
        }

        $variables = $request->input('variables', $request->json('variables'));
        if (! is_array($variables)) {
            $variables = null;
        }
        $operation = $request->input('operationName', $request->json('operationName'));
        $opName = is_string($operation) ? $operation : null;

        try {
            $flags = config('app.debug')
                ? (DebugFlag::INCLUDE_DEBUG_MESSAGE | DebugFlag::INCLUDE_TRACE)
                : DebugFlag::NONE;

            [$payload, $cacheState] = GraphqlShopResponseCache::remember(
                $request,
                $query,
                $variables,
                $opName,
                function () use ($query, $variables, $opName, $flags) {
                    $result = GraphQL::executeQuery(
                        ShopSchema::build(),
                        $query,
                        null,
                        null,
                        $variables,
                        $opName,
                        null,
                        GraphqlSecurityRules::rules(),
                    );

                    return $result->toArray($flags);
                }
            );

            $response = response()->json($payload)->header(GraphqlShopResponseCache::HEADER, $cacheState);
            $response = GraphqlShopResponseCache::applyHttpCacheHeaders($response, $request, $query, $payload, $cacheState);

            return GraphqlTiming::withHeaders($response, $started, $opName);
        } catch (Throwable $e) {
            return GraphqlTiming::withHeaders(response()->json([
                'errors' => [['message' => config('app.debug') ? $e->getMessage() : 'GraphQL execution failed.']],
            ], 500), $started, $opName);
        }
    }

    /** X-Wento-Locale / X-Wento-Currency / X-Store storefront context headers. */
    protected function applyStoreContextHeaders(Request $request): void
    {
        $locale = trim((string) $request->header('X-Wento-Locale', ''));
        if ($locale !== '' && Language::query()->where('code', $locale)->where('is_enabled', true)->exists()) {
            App::setLocale($locale);
        }

        $currency = strtoupper(trim((string) $request->header('X-Wento-Currency', '')));
        if ($currency !== '' && Currency::query()->where('code', $currency)->where('is_enabled', true)->exists()) {
            $request->attributes->set('wento.currency', $currency);
            if ($request->hasSession()) {
                $request->session()->put('currency', $currency);
            }
        }

        $storeKey = trim((string) ($request->header('X-Store') ?: $request->header('X-Wento-Store') ?: ''));
        if ($storeKey !== '') {
            $request->attributes->set('wento.store', $storeKey);
        }
    }
}
