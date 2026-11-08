<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Expose compatible + Wento GraphQL observability headers to browser SPAs.
 * Without this, fetch()/XHR cannot read X-RateLimit-* / Server-Timing from JS.
 */
final class ExposeApiRateLimitHeaders
{
    public const HEADERS = [
        'Retry-After',
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
        'X-RateLimit-Reset',
        'X-Wento-GQL-Cache',
        'X-Wento-GQL-Duration-Ms',
        'Server-Timing',
        'ETag',
        'Cache-Control',
        'X-Request-Id',
        'X-Wento-GQL-Op',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $existing = (string) $response->headers->get('Access-Control-Expose-Headers', '');
        $merged = array_values(array_unique(array_filter(array_merge(
            $existing !== '' ? array_map('trim', explode(',', $existing)) : [],
            self::HEADERS,
        ))));
        $response->headers->set('Access-Control-Expose-Headers', implode(', ', $merged));

        return $response;
    }
}
