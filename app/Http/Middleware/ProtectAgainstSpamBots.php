<?php

namespace App\Http\Middleware;

use App\Domain\Security\Honeypot;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProtectAgainstSpamBots
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('POST') || $request->isMethod('PUT') || $request->isMethod('PATCH')) {
            Honeypot::assert($request);
        }

        return $next($request);
    }
}
