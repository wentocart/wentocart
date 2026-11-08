<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tag redirects from list-level admin actions so the UI can show growl toasts.
 * Form store/update/save keep plain session flash (inline banners) — no toast flag.
 */
class MarkAdminListToast
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof RedirectResponse) {
            return $response;
        }

        if (! $this->isListToastRoute($request->route()?->getName())) {
            return $response;
        }

        return $response->with('toast', true);
    }

    protected function isListToastRoute(?string $name): bool
    {
        if ($name === null || $name === '' || ! str_starts_with($name, 'admin.')) {
            return false;
        }

        // Localization import is a dedicated wizard form, not a list toolbar action.
        if (str_contains($name, 'localization.import')) {
            return false;
        }

        if (preg_match('/\.(toggle|toggle-visibility|import|resend)$/', $name) === 1) {
            return true;
        }

        if (preg_match('/\.bulk(-[a-z0-9]+)?$/', $name) === 1) {
            return true;
        }

        if (str_contains($name, '.bulk-')) {
            return true;
        }

        // Row delete from a list (form delete that returns to the list is included).
        if (preg_match('/\.destroy$/', $name) === 1) {
            return true;
        }

        if (str_ends_with($name, '.reorder') || str_contains($name, '.reorder')) {
            return true;
        }

        return false;
    }
}
