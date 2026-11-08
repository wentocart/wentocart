<?php

namespace App\Domain\Api\Graphql;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Retry-After / X-RateLimit-* headers on Admin GraphQL.
 */
final class IntegrationTokenRateLimit
{
    public const MINUTE_PREFIX = 'rlm:';

    public const DAY_PREFIX = 'rld:';

    /** default when creating a new token. */
    public const DEFAULT_PER_MINUTE = 60;

    /** default when creating a new token. */
    public const DEFAULT_PER_DAY = 10000;

    /**
     * @param  list<string>|null  $abilities
     * @return array{per_minute: ?int, per_day: ?int} null = unlimited
     */
    public static function extract(?array $abilities): array
    {
        $perMinute = null;
        $perDay = null;
        foreach ($abilities ?? [] as $ability) {
            $ability = (string) $ability;
            if (str_starts_with($ability, self::MINUTE_PREFIX)) {
                $n = (int) substr($ability, strlen(self::MINUTE_PREFIX));
                $perMinute = $n > 0 ? $n : null;
            } elseif (str_starts_with($ability, self::DAY_PREFIX)) {
                $n = (int) substr($ability, strlen(self::DAY_PREFIX));
                $perDay = $n > 0 ? $n : null;
            }
        }

        return ['per_minute' => $perMinute, 'per_day' => $perDay];
    }

    /**
     * @param  list<string>  $abilities
     * @return list<string>
     */
    public static function mergeAbilities(array $abilities, ?int $perMinute, ?int $perDay): array
    {
        $kept = [];
        foreach ($abilities as $ability) {
            $ability = (string) $ability;
            if (str_starts_with($ability, self::MINUTE_PREFIX) || str_starts_with($ability, self::DAY_PREFIX)) {
                continue;
            }
            $kept[] = $ability;
        }
        if ($perMinute !== null && $perMinute > 0) {
            $kept[] = self::MINUTE_PREFIX.$perMinute;
        }
        if ($perDay !== null && $perDay > 0) {
            $kept[] = self::DAY_PREFIX.$perDay;
        }

        return array_values(array_unique($kept));
    }

    /**
     * Normalize create args: null uses defaults; 0 = unlimited.
     *
     * @return array{0: ?int, 1: ?int}
     */
    public static function normalizeCreateArgs(mixed $perMinute, mixed $perDay): array
    {
        $minute = $perMinute === null ? self::DEFAULT_PER_MINUTE : (int) $perMinute;
        $day = $perDay === null ? self::DEFAULT_PER_DAY : (int) $perDay;

        return [
            $minute > 0 ? min(100000, $minute) : null,
            $day > 0 ? min(10_000_000, $day) : null,
        ];
    }

    public static function enforce(Request $request): ?JsonResponse
    {
        $user = $request->user('admin') ?? auth('admin')->user();
        $token = $user?->currentAccessToken();
        if (! $token instanceof PersonalAccessToken) {
            return null;
        }

        $limits = self::extract(is_array($token->abilities) ? $token->abilities : []);
        $tokenId = (string) $token->id;

        if ($limits['per_minute'] !== null) {
            $key = 'wento:inttok:min:'.$tokenId;
            $max = $limits['per_minute'];
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return self::tooMany($max, RateLimiter::availableIn($key), now()->addSeconds(RateLimiter::availableIn($key))->getTimestamp());
            }
            RateLimiter::hit($key, 60);
        }

        if ($limits['per_day'] !== null) {
            $key = 'wento:inttok:day:'.$tokenId;
            $max = $limits['per_day'];
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $retry = RateLimiter::availableIn($key);

                return self::tooMany($max, $retry, now()->addSeconds($retry)->getTimestamp());
            }
            RateLimiter::hit($key, 86400);
        }

        $request->attributes->set('wento.integration_token', $token);
        $request->attributes->set('wento.integration_rate_limits', $limits);

        return null;
    }

    /**
     * @template T of Response
     * @param  T  $response
     * @return T
     */
    public static function withHeaders(Response $response, Request $request): Response
    {
        $token = $request->attributes->get('wento.integration_token');
        if (! $token instanceof PersonalAccessToken) {
            return $response;
        }

        $limits = $request->attributes->get('wento.integration_rate_limits');
        if (! is_array($limits)) {
            $limits = self::extract(is_array($token->abilities) ? $token->abilities : []);
        }

        // Prefer minute bucket for success headers (clients read these every response).
        if ($limits['per_minute'] !== null) {
            $key = 'wento:inttok:min:'.$token->id;
            $max = (int) $limits['per_minute'];
            $remaining = max(0, $max - RateLimiter::attempts($key));
            $retry = RateLimiter::availableIn($key);
            $response->headers->set('X-RateLimit-Limit', (string) $max);
            $response->headers->set('X-RateLimit-Remaining', (string) $remaining);
            $response->headers->set('X-RateLimit-Reset', (string) now()->addSeconds($retry > 0 ? $retry : 60)->getTimestamp());
        } elseif ($limits['per_day'] !== null) {
            $key = 'wento:inttok:day:'.$token->id;
            $max = (int) $limits['per_day'];
            $remaining = max(0, $max - RateLimiter::attempts($key));
            $retry = RateLimiter::availableIn($key);
            $response->headers->set('X-RateLimit-Limit', (string) $max);
            $response->headers->set('X-RateLimit-Remaining', (string) $remaining);
            $response->headers->set('X-RateLimit-Reset', (string) now()->addSeconds($retry > 0 ? $retry : 86400)->getTimestamp());
        }

        return $response;
    }

    private static function tooMany(int $limit, int $retryAfter, int $resetAt): JsonResponse
    {
        $retryAfter = max(1, $retryAfter);

        return response()->json([
            'message' => 'Too Many Attempts.',
            'error' => 'rate_limit_exceeded',
            'retry_after' => $retryAfter,
        ], 429)
            ->header('Retry-After', (string) $retryAfter)
            ->header('X-RateLimit-Limit', (string) $limit)
            ->header('X-RateLimit-Remaining', '0')
            ->header('X-RateLimit-Reset', (string) $resetAt);
    }
}
