<?php

namespace App\Http\Middleware;

use App\Domain\Api\Graphql\IntegrationTokenRateLimit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared per-token minute/day buckets across Admin GraphQL + REST (parity).
 */
final class EnforceIntegrationTokenRateLimit
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($limited = IntegrationTokenRateLimit::enforce($request)) {
            return $limited;
        }

        return IntegrationTokenRateLimit::withHeaders($next($request), $request);
    }
}
