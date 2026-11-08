<?php

namespace App\Http\Middleware;

use App\Domain\Settings\PrivacySettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When customer MFA is required, block account APIs until TOTP or a passkey is enrolled.
 * Enroll/confirm/passkey-register routes stay open so the shopper can finish setup.
 */
class EnsureCustomerMfa
{
    public function handle(Request $request, Closure $next): Response
    {
        $customer = $request->user('customer');
        if (! $customer) {
            return $next($request);
        }

        if (! PrivacySettings::customerMfaRequired()) {
            return $next($request);
        }

        if ($customer->totp_confirmed_at || $customer->hasPasskeys()) {
            return $next($request);
        }

        $path = trim($request->path(), '/');
        $allowed = [
            'api/v1/auth/mfa/enroll',
            'api/v1/auth/mfa/confirm',
            'api/v1/auth/passkey/register-options',
            'api/v1/auth/passkey/register',
            'api/v1/auth/logout',
            'api/v1/account/stop-impersonation',
        ];
        if (in_array($path, $allowed, true)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Authenticator enrollment is required.',
            'enroll_required' => true,
        ], 403);
    }
}
