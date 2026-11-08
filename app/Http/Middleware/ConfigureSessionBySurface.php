<?php

namespace App\Http\Middleware;

use App\Domain\Installer\Models\ShopUrl;
use App\Domain\Settings\SessionLifetimes;
use App\Support\WebBase;
use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin and each storefront shop use different session cookies and idle timeouts.
 * A cookie on /shop must not sign the visitor into /shop/mobile and /shop/electronics.
 */
class ConfigureSessionBySurface
{
    public function handle(Request $request, Closure $next): Response
    {
        $base = WebBase::path();
        $webPath = $base === '' ? '/' : $base;

        if ($this->isAdmin($request)) {
            config([
                'session.cookie' => (string) config('session.admin_cookie'),
                'session.lifetime' => SessionLifetimes::adminMinutes(),
                // Shop folder, not the admin word. Changing that word must not drop the cookie.
                'session.path' => $webPath,
            ]);
        } else {
            config([
                'session.cookie' => $this->storefrontCookie($request),
                'session.lifetime' => SessionLifetimes::customerMinutes(),
                'session.path' => $webPath,
            ]);
        }

        $this->alignCookieJar();
        $this->renameExistingSession();

        $response = $next($request);
        if (! $this->isAdmin($request)) {
            $this->expireInstallWideCookies($response);
        }

        return $response;
    }

    protected function isAdmin(Request $request): bool
    {
        return $request->is('admin') || $request->is('admin/*');
    }

    protected function storefrontCookie(Request $request): string
    {
        $base = (string) config('session.storefront_cookie');
        if ($base === '') {
            $base = (string) config('session.cookie');
        }
        $matched = $request->attributes->get('wento.shop_url');
        $storeId = $matched instanceof ShopUrl ? (int) $matched->store_id : 0;
        if ($storeId < 1) {
            return $base;
        }

        return $base.'-s'.$storeId;
    }

    /**
     * The session store keeps the cookie name from the first time it was built.
     * A later request in the same process (tests, long-lived workers) must switch names
     * before StartSession reads the cookie.
     */
    protected function renameExistingSession(): void
    {
        if (! app()->resolved('session')) {
            return;
        }

        $session = app('session')->driver();
        $name = (string) config('session.cookie');
        if ($session->getName() === $name) {
            return;
        }

        $session->setName($name);
        $session->flush();
    }

    protected function alignCookieJar(): void
    {
        $domain = config('session.domain');
        if (! is_string($domain) || $domain === '' || strtolower($domain) === 'null') {
            $domain = null;
        }
        app('cookie')->setDefaultPathAndDomain(
            (string) config('session.path'),
            $domain,
            (bool) config('session.secure'),
            config('session.same_site'),
        );
    }

    /**
     * Drop the install-folder session, CSRF, and remember-me cookies.
     * Their path is a prefix of every virtual shop, so the browser would send one login to all of them.
     */
    protected function expireInstallWideCookies(Response $response): void
    {
        $install = rtrim(WebBase::installPath(), '/');
        $current = rtrim((string) config('session.path'), '/');
        if ($current === '' || $current === $install) {
            return;
        }

        $paths = array_values(array_unique(array_filter([
            $install === '' ? '/' : $install,
            $install === '' ? null : $install.'/',
        ])));
        $names = array_values(array_filter([
            (string) config('session.storefront_cookie'),
            'XSRF-TOKEN',
            $this->legacyRecallerName(),
        ]));
        $domain = config('session.domain');
        if (! is_string($domain) || $domain === '' || strtolower($domain) === 'null') {
            $domain = null;
        }

        foreach ($names as $name) {
            // XSRF-TOKEN is readable by the page. A HttpOnly delete does not remove it,
            // so the browser keeps the install-wide token and sign-in fails on the shop.
            $httpOnly = $name !== 'XSRF-TOKEN';
            foreach ($paths as $path) {
                $response->headers->setCookie(new Cookie($name, '', 1, $path, $domain, false, $httpOnly, false, 'lax'));
            }
        }
    }

    protected function legacyRecallerName(): string
    {
        return 'remember_customer_'.sha1(SessionGuard::class);
    }
}
