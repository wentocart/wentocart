<?php

namespace App\Http\Middleware;

use App\Domain\Settings\RequestProfiler;
use App\Domain\Settings\ShopSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class CaptureProfiler
{
    public function handle(Request $request, Closure $next): Response
    {
        $enabled = false;
        try {
            $enabled = ShopSetting::bool('advanced.profiler', false);
        } catch (Throwable) {
            $enabled = false;
        }

        if ($enabled) {
            app(RequestProfiler::class)->start();
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            if (ShopSetting::bool('advanced.profiler', false)) {
                app(RequestProfiler::class)->store($request);
            }
        } catch (Throwable) {
            // Profiler must never break the response.
        }
    }
}
