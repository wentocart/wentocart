<?php

namespace App\Http\Middleware;

use App\Domain\Installer\Models\ShopUrl;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class RewriteShopVirtualUri
{
    public function handle(Request $request, Closure $next): Response
    {
        $canonical = ShopUrl::canonicalVirtualRedirect($request);
        if (is_string($canonical) && $canonical !== '') {
            return redirect()->away($request->getSchemeAndHttpHost().$canonical, 301);
        }

        $request = ShopUrl::rewriteVirtualInbound($request);
        $matched = $request->attributes->get('wento.shop_url');
        if ($matched instanceof ShopUrl) {
            URL::forceRootUrl($matched->root());
        }

        return $next($request);
    }
}
