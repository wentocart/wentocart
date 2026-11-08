<?php

namespace App\Http\Middleware;

use App\Domain\Settings\PrivacySettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminMfa
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('admin');
        if (! $admin) {
            return $next($request);
        }
        if ($request->routeIs('admin.logout', 'admin.mfa.*', 'admin.locale')) {
            return $next($request);
        }
        if (PrivacySettings::adminMfaRequired() && ! $admin->totp_confirmed_at && ! $admin->hasPasskeys()) {
            return redirect()->route('admin.mfa.enroll');
        }

        return $next($request);
    }
}
