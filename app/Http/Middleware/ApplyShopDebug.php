<?php

namespace App\Http\Middleware;

use App\Domain\Settings\ShopSetting;
use App\Domain\Store\StoreContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * A shop setting can only lower APP_DEBUG, never raise it: on a production
 * shop debug stays off no matter what the setting says, so the admin UI can
 * never switch verbose errors on for live traffic. An empty setting leaves
 * the environment value alone.
 */
class ApplyShopDebug
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $choice = ShopSetting::get('performance.debug', null);
        } catch (Throwable) {
            return $next($request);
        }

        if ($choice !== null && $choice !== '') {
            config(['app.debug' => StoreContext::flag($choice) && ! app()->isProduction()]);
        }

        return $next($request);
    }
}
