<?php

namespace App\Http\Middleware;

use App\Domain\Store\StoreBootstrap;
use App\Domain\Store\StoreContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ResolveStore
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            if (config('wento.installed')) {
                StoreBootstrap::ensure();
                app(StoreContext::class)->resolveFromRequest($request);
            }
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (Throwable) {
            // Installer / missing tables.
        }

        return $next($request);
    }
}
