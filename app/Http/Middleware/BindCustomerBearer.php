<?php

namespace App\Http\Middleware;

use App\Domain\Api\Graphql\GraphqlCustomerAuth;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bind Sanctum Bearer tokens onto the customer guard (shop REST + GraphQL parity).
 */
final class BindCustomerBearer
{
    public function handle(Request $request, Closure $next): Response
    {
        GraphqlCustomerAuth::bind($request);

        return $next($request);
    }
}
