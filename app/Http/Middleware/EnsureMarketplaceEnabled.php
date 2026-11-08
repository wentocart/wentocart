<?php

namespace App\Http\Middleware;

use App\Domain\Marketplace\MarketplaceSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks Marketplace / Multi-Vendor routes when multivendor_enabled is off.
 * Does not alter core catalog/cart/checkout/order routes.
 */
class EnsureMarketplaceEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (MarketplaceSettings::enabled()) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            abort(403, 'Multi-vendor is disabled for this store.');
        }

        abort(404);
    }
}
