<?php

namespace App\Http\Middleware;

use App\Domain\Settings\MaintenanceMessage;
use App\Domain\Settings\ShopSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ShopMaintenance
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldBypass($request)) {
            return $next($request);
        }

        $on = false;
        try {
            $on = ShopSetting::bool('advanced.maintenance', false);
        } catch (Throwable) {
            $on = false;
        }

        if (! $on) {
            return $next($request);
        }

        $allow = $this->allowList();
        $ip = (string) $request->ip();
        if ($ip !== '' && in_array($ip, $allow, true)) {
            return $next($request);
        }

        $message = MaintenanceMessage::sanitize(
            MaintenanceMessage::forEditor(
                (string) ShopSetting::get('advanced.maintenance_message', '')
            )
        );

        // SecurityHeaders sits after this middleware; apply it here so 503 pages still get CSP.
        return app(SecurityHeaders::class)->handle($request, function () use ($message) {
            return response()->view('storefront.maintenance', [
                'message' => $message,
                'header' => MaintenanceMessage::sanitizeOptional(
                    (string) ShopSetting::get('advanced.maintenance_header', '')
                ),
                'footer' => MaintenanceMessage::sanitizeOptional(
                    (string) ShopSetting::get('advanced.maintenance_footer', '')
                ),
                'banner' => MaintenanceMessage::sanitizeOptional(
                    (string) ShopSetting::get('advanced.maintenance_banner', '')
                ),
                'shop' => config('app.name'),
            ], 503);
        });
    }

    protected function shouldBypass(Request $request): bool
    {
        if ($request->is('admin') || $request->is('admin/*')) {
            return true;
        }
        if ($request->is('install') || $request->is('install/*') || $request->is('installed') || $request->is('up')) {
            return true;
        }
        if ($request->is('storage/*') || $request->is('build/*') || $request->is('livewire/*')) {
            return true;
        }

        return false;
    }

    /**
     * @return list<string>
     */
    protected function allowList(): array
    {
        $raw = (string) ShopSetting::get('advanced.maintenance_ips', '');
        $parts = preg_split('/[\s,]+/', $raw) ?: [];

        return array_values(array_filter(array_map('trim', $parts)));
    }
}
