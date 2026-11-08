<?php

namespace App\Http\Middleware;

use App\Domain\Installer\Models\ShopUrl;
use App\Domain\Store\StoreContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class UseShopUrl
{
    public function handle(Request $request, Closure $next): Response
    {
        $root = rtrim($request->getSchemeAndHttpHost().$request->getBasePath(), '/');
        $matched = $request->attributes->get('wento.shop_url');
        if ($matched instanceof ShopUrl) {
            URL::forceRootUrl($matched->root());
        } elseif ($root !== '') {
            URL::forceRootUrl($root);
        }

        if (config('wento.installed') && ! $request->is('install') && ! $request->is('install/*')) {
            try {
                $context = app(StoreContext::class);
                if (! $context->enabled()) {
                    ShopUrl::syncFromRequest($request);
                }
            } catch (Throwable) {
                // shop_urls / stores may not exist yet on an older database.
            }
        }

        return $next($request);
    }
}
