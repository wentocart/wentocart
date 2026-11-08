<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Api\Models\StorefrontApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Window is the clock hour (storefront key guide).
 */
final class StorefrontApiKeyRateLimit
{
    public static function enforce(Request $request): ?JsonResponse
    {
        $key = $request->attributes->get('wento.storefront_api_key');
        if (! $key instanceof StorefrontApiKey) {
            return null;
        }

        $max = $key->rate_limit;
        if ($max === null || (int) $max <= 0) {
            return null;
        }

        $max = (int) $max;
        $bucket = 'wento:sfkey:hour:'.$key->id.':'.now()->format('YmdH');
        $secondsToHour = max(1, now()->endOfHour()->diffInSeconds(now()) + 1);

        if (RateLimiter::tooManyAttempts($bucket, $max)) {
            $retry = $secondsToHour;

            return response()->json([
                'message' => 'Rate limit exceeded',
                'error' => 'rate_limit_exceeded',
                'retry_after' => $retry,
            ], 429)
                ->header('Retry-After', (string) $retry)
                ->header('X-RateLimit-Limit', (string) $max)
                ->header('X-RateLimit-Remaining', '0')
                ->header('X-RateLimit-Reset', (string) $retry);
        }

        RateLimiter::hit($bucket, $secondsToHour);
        $request->attributes->set('wento.storefront_rate_bucket', $bucket);
        $request->attributes->set('wento.storefront_rate_max', $max);
        $request->attributes->set('wento.storefront_rate_reset', $secondsToHour);

        return null;
    }

    public static function withHeaders(JsonResponse $response, Request $request): JsonResponse
    {
        $max = $request->attributes->get('wento.storefront_rate_max');
        $bucket = $request->attributes->get('wento.storefront_rate_bucket');
        $reset = $request->attributes->get('wento.storefront_rate_reset');
        if ($max === null || $bucket === null) {
            return $response;
        }

        $max = (int) $max;
        $remaining = max(0, $max - RateLimiter::attempts((string) $bucket));
        $response->headers->set('X-RateLimit-Limit', (string) $max);
        $response->headers->set('X-RateLimit-Remaining', (string) $remaining);
        $response->headers->set('X-RateLimit-Reset', (string) max(1, (int) $reset));

        return $response;
    }
}
