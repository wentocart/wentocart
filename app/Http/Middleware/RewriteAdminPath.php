<?php

namespace App\Http\Middleware;

use App\Domain\Security\AdminPath;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RewriteAdminPath
{
    public function handle(Request $request, Closure $next): Response
    {
        $request = AdminPath::rewriteInbound($request);
        app()->instance('request', $request);

        return AdminPath::rewriteOutbound($next($request));
    }
}
