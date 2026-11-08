<?php

namespace App\Http\Middleware;

use App\Domain\Plugin\PluginKernel;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class BootPlugins
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            if (Schema::hasTable('plugins')) {
                app(PluginKernel::class)->boot();
            }
        } catch (Throwable) {
            // Apps must never take the shop down.
        }

        return $next($request);
    }
}
