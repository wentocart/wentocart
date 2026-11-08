<?php

namespace App\Http\Middleware;

use App\Domain\Api\Graphql\GraphqlAdminAuth;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bind style Integration Bearer tokens onto the admin guard.
 */
final class BindAdminIntegrationAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        GraphqlAdminAuth::bind($request);

        return $next($request);
    }
}
