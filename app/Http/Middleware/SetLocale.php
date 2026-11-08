<?php

namespace App\Http\Middleware;

use App\Domain\Localization\Models\Currency;
use App\Domain\Localization\Models\Language;
use App\Domain\Localization\Money;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('wento.installed')) {
            return $next($request);
        }

        try {
            $this->stripPathLocale($request);
            $locale = $this->resolve($request);
            if ($locale) {
                app()->setLocale($locale);
            }
        } catch (Throwable) {
            // Languages table may not exist during early install.
        }

        try {
            app()->instance('wento.currency', $this->resolveCurrency($request));
        } catch (Throwable) {
            // Currencies table may not exist during early install.
        }

        return $next($request);
    }

    /**
     * When seo.locale_url_mode=path, treat /{lang}/... as the locale prefix and
     * expose the catalog path without the prefix for StorefrontController.
     */
    protected function stripPathLocale(Request $request): void
    {
        $mode = 'cookie';
        try {
            $mode = (string) \App\Domain\Settings\ShopSetting::get('seo.locale_url_mode', config('wento.locale_url_mode', 'cookie'));
        } catch (Throwable) {
            $mode = (string) config('wento.locale_url_mode', 'cookie');
        }
        if ($mode !== 'path') {
            return;
        }
        if ($request->is('admin') || $request->is('admin/*') || $request->is('api') || $request->is('api/*')) {
            return;
        }

        $segments = $request->segments();
        if ($segments === []) {
            return;
        }

        $enabled = Language::query()->enabled()->pluck('code')->all();
        $first = $segments[0];
        if (! in_array($first, $enabled, true)) {
            return;
        }

        $catalog = implode('/', array_slice($segments, 1));
        $request->attributes->set('wento_path_locale_stripped', true);
        $request->attributes->set('wento_path_locale', $first);
        $request->attributes->set('wento_catalog_path', $catalog);
        $request->cookies->set('wento_locale', $first);
    }

    protected function resolve(Request $request): ?string
    {
        $enabled = Language::query()->enabled()->pluck('code')->all();
        if ($enabled === []) {
            return config('wento.default_language', 'en');
        }

        $isAdmin = $request->is('admin') || $request->is('admin/*');
        $candidates = [
            $request->attributes->get('wento_path_locale'),
            $isAdmin ? $request->session()->get('admin_locale') : null,
            $request->is('api/*') ? $request->header('X-Wento-Locale') : null,
            $request->cookie('wento_locale'),
            Language::defaultCode(),
        ];

        foreach ($candidates as $code) {
            if (is_string($code) && $code !== '' && in_array($code, $enabled, true)) {
                return $code;
            }
        }

        return $enabled[0];
    }

    protected function resolveCurrency(Request $request): string
    {
        $enabled = Currency::query()->where('is_enabled', true)->pluck('code')->all();
        $enabled = array_map(fn ($code) => strtoupper((string) $code), $enabled);
        if ($enabled === []) {
            return strtoupper((string) config('wento.default_currency', 'USD'));
        }

        $fromApi = null;
        if ($request->is('api/*')) {
            $fromApi = $request->header('X-Wento-Currency')
                ?: $request->query('currency')
                ?: $request->input('currency_code');
        }
        $candidates = [
            $fromApi,
            $request->cookie('wento_currency'),
            Money::defaultCode(),
        ];

        foreach ($candidates as $code) {
            if (is_string($code) && $code !== '' && in_array(strtoupper($code), $enabled, true)) {
                return strtoupper($code);
            }
        }

        return $enabled[0];
    }
}
