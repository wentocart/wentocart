<?php

namespace App\Http\Middleware;

use App\Domain\Api\Graphql\GraphqlStorefrontKey;
use App\Domain\Api\Graphql\StorefrontApiKeyRateLimit;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validate optional/required X-STOREFRONT-KEY and apply hourly quota (parity).
 */
final class EnforceStorefrontApiKeyRateLimit
{
    public function handle(Request $request, Closure $next): Response
    {
        $storefront = GraphqlStorefrontKey::guard($request);
        if (! ($storefront['ok'] ?? false)) {
            return response()->json(
                $storefront['body'] ?? ['message' => 'Unauthenticated.'],
                (int) ($storefront['status'] ?? 401)
            );
        }

        if ($limited = StorefrontApiKeyRateLimit::enforce($request)) {
            return $limited;
        }

        $response = $next($request);
        if ($response instanceof JsonResponse) {
            return StorefrontApiKeyRateLimit::withHeaders($response, $request);
        }

        return $response;
    }
}
