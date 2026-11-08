<?php

namespace App\Http\Middleware;

use App\Domain\Settings\ShopSetting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guest-only HTTP + body cache for public storefront API GETs.
 * Signed-in shoppers, carts, and account routes never enter the cache.
 */
class CachePublicStorefrontApi
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->shouldAttempt($request)) {
            return $next($request);
        }

        $key = $this->cacheKey($request);
        $hit = Cache::get($key);
        if (is_array($hit) && isset($hit['body'], $hit['status'], $hit['content_type'])) {
            return response($hit['body'], (int) $hit['status'], [
                'Content-Type' => (string) $hit['content_type'],
                'X-Wento-Cache' => 'HIT',
                'Cache-Control' => 'public, max-age='.$this->ttl(),
            ]);
        }

        /** @var Response $response */
        $response = $next($request);
        if ($response->getStatusCode() === 200 && $this->isJson($response)) {
            Cache::put($key, [
                'body' => $response->getContent(),
                'status' => $response->getStatusCode(),
                'content_type' => $response->headers->get('Content-Type', 'application/json'),
            ], $this->ttl());
            $response->headers->set('X-Wento-Cache', 'MISS');
            $response->headers->set('Cache-Control', 'public, max-age='.$this->ttl());
        }

        return $response;
    }

    private function shouldAttempt(Request $request): bool
    {
        if (! ShopSetting::bool('performance.api_cache', true)) {
            return false;
        }
        if ($this->ttl() <= 0) {
            return false;
        }
        if (! $request->isMethod('GET')) {
            return false;
        }
        if (Auth::guard('customer')->check() || Auth::guard('admin')->check()) {
            return false;
        }
        if ($request->hasSession() && (
            $request->session()->has('customer.mfa_pending_id')
            || $request->session()->has('customer.sms_pending_id')
        )) {
            return false;
        }
        $path = trim($request->path(), '/');
        if (! str_starts_with($path, 'api/v1/')) {
            return false;
        }
        // Never cache anything that can carry personal or cart state.
        foreach ([
            'api/v1/cart', 'api/v1/checkout', 'api/v1/account', 'api/v1/auth',
            'api/v1/wishlist', 'api/v1/compare',
            // Store REST paths that carry cart / checkout / account state.
            'api/v1/store/cart', 'api/v1/store/checkout', 'api/v1/store/payment-methods',
            'api/v1/store/customer',
            'api/v1/store/wishlists', 'api/v1/store/compare', 'api/v1/store/gdpr',
            'api/v1/store/eu-withdrawal', 'api/v1/store/add-product', 'api/v1/store/create-cart',
        ] as $blocked) {
            if (str_starts_with($path, $blocked)) {
                return false;
            }
        }

        return (bool) preg_match(
            '#^api/v1/(store|catalog|cms|brands|search|deals|blog|feeds)(/|$)#',
            $path
        );
    }

    private function cacheKey(Request $request): string
    {
        $store = (string) ($request->attributes->get('store_id') ?? '');
        $locale = (string) ($request->header('X-Wento-Locale') ?: $request->query('locale') ?: app()->getLocale());

        return 'sf.api.'.sha1($store.'|'.$locale.'|'.$request->getRequestUri());
    }

    private function ttl(): int
    {
        $choice = (string) ShopSetting::get('performance.catalog_ttl', 'auto');
        if ($choice === '0') {
            return 0;
        }
        if ($choice === '600' || $choice === '3600') {
            return (int) $choice;
        }

        return 120;
    }

    private function isJson(Response $response): bool
    {
        $type = (string) $response->headers->get('Content-Type', '');

        return str_contains($type, 'json') || str_starts_with((string) $response->getContent(), '{') || str_starts_with((string) $response->getContent(), '[');
    }
}
