<?php

namespace App\Domain\Api\Graphql;

use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Optional Bearer token auth for admin GraphQL (Integration token parity).
 * Session cookie auth continues to work unchanged.
 */
final class GraphqlAdminAuth
{
    public static function bind(Request $request): void
    {
        $plain = $request->bearerToken();
        if ($plain === null || $plain === '') {
            // Session cookie auth continues to work when no Bearer is present.
            return;
        }

        // Bearer Integration tokens take precedence (parity + IP allowlists).
        Auth::guard('admin')->logout();

        $access = PersonalAccessToken::findToken($plain);
        $user = $access?->tokenable;
        if ($user instanceof Admin && $access) {
            if (! $user->is_enabled) {
                return;
            }
            if ($access->expires_at !== null && $access->expires_at->isPast()) {
                return;
            }
            if (! IntegrationTokenAllowlist::allows($access, $request->ip())) {
                return;
            }
            $user->withAccessToken($access);
            Auth::guard('admin')->setUser($user);
        }
    }
}
