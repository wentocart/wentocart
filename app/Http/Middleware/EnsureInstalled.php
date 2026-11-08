<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('setup') || $request->is('setup/*') || $request->is('install') || $request->is('install/*') || $request->is('up')) {
            if (($request->is('install') || $request->is('install/*')) && ! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
                $lock = config('wento.lock_path');
                if (is_string($lock) && $lock !== '' && file_exists($lock)) {
                    abort(404);
                }
            }

            return $next($request);
        }

        if (config('wento.installed')) {
            return $next($request);
        }

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json(['message' => 'Store is not installed.'], 503);
        }

        return redirect('/setup/index.php');
    }
}
