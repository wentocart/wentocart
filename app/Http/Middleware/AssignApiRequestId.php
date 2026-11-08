<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stable request correlation id for headless clients / support — docs
 * do not standardize this on GraphQL responses.
 */
final class AssignApiRequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = trim((string) $request->headers->get(self::HEADER, ''));
        $id = $incoming !== '' && strlen($incoming) <= 128
            ? $incoming
            : (string) Str::uuid();

        $request->attributes->set('wento.request_id', $id);
        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }
}
