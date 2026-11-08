<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sanctum only starts a cookie session when Origin/Referer match SANCTUM_STATEFUL_DOMAINS.
 * Some browsers omit those on same-origin XHR; if the SPA already has CSRF/session cookies,
 * treat the request as first-party so login/register can regenerate the session.
 */
class EnsureStorefrontApiStateful
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->headers->has('Origin') && ! $request->headers->has('Referer')) {
            $sessionCookie = (string) config('session.storefront_cookie', config('session.cookie'));
            $hasShopSession = false;
            if ($sessionCookie !== '') {
                foreach (array_keys($request->cookies->all()) as $name) {
                    if ($name === $sessionCookie || str_starts_with((string) $name, $sessionCookie.'-s')) {
                        $hasShopSession = true;
                        break;
                    }
                }
            }
            if ($hasShopSession || $request->cookies->has('XSRF-TOKEN')) {
                $request->headers->set('Origin', $request->getSchemeAndHttpHost());
            }
        }

        return $next($request);
    }
}
