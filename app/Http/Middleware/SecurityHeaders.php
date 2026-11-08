<?php

namespace App\Http\Middleware;

use App\Domain\Security\CspPolicyRegistry;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = base64_encode(random_bytes(16));
        $request->attributes->set('csp_nonce', $nonce);
        View::share('cspNonce', $nonce);
        Vite::useCspNonce($nonce);

        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if (! $response->headers->has('Content-Security-Policy')) {
            $nonceSrc = "'nonce-{$nonce}'";
            $base = [
                'default-src' => ["'self'"],
                'base-uri' => ["'self'"],
                'form-action' => ["'self'"],
                'frame-ancestors' => ["'self'"],
                'frame-src' => ["'none'"],
                'child-src' => ["'none'"],
                'object-src' => ["'none'"],
                // Nonce-only scripts (no unsafe-inline / unsafe-eval).
                'script-src' => ["'self'", $nonceSrc],
                'style-src' => ["'self'", $nonceSrc, 'https://fonts.googleapis.com'],
                'style-src-elem' => ["'self'", $nonceSrc, 'https://fonts.googleapis.com'],
                // React and theme tokens still use style="" attributes.
                'style-src-attr' => ["'unsafe-inline'"],
                'img-src' => ["'self'", 'data:', 'blob:', 'https:'],
                'font-src' => ["'self'", 'data:', 'https://fonts.gstatic.com'],
                'connect-src' => ["'self'"],
            ];
            $extension = CspPolicyRegistry::collect();
            $directives = CspPolicyRegistry::mergePolicy($base, $extension);
            $response->headers->set('Content-Security-Policy', implode('; ', $directives));
        }

        if ($request->is('admin') || $request->is('admin/*') || $request->is('account') || $request->is('account/*')) {
            $response->headers->set('Cache-Control', 'private, no-store, no-cache, must-revalidate');
            $response->headers->set('Pragma', 'no-cache');
        }

        $appUrl = (string) config('app.url');
        if (str_starts_with($appUrl, 'https://') && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
