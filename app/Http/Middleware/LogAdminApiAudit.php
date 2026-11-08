<?php

namespace App\Http\Middleware;

use App\Domain\Api\Graphql\AdminApiAuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Record style Admin API write audits (Integration → History).
 */
final class LogAdminApiAudit
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        AdminApiAuditLogger::maybeLog($request, $response);

        return $response;
    }
}
