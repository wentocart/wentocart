<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Customer\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Optional Bearer token auth for headless GraphQL.
 * Session/cookie auth via Sanctum stateful API continues to work unchanged.
 */
final class GraphqlCustomerAuth
{
    public static function bind(Request $request): void
    {
        $plain = $request->bearerToken();
        if ($plain === null || $plain === '') {
            return;
        }

        // Sanctum tokens contain "|" — UUID cart tokens do not.
        if (! str_contains($plain, '|')) {
            return;
        }

        $access = PersonalAccessToken::findToken($plain);
        // ShopOwned scopes can hide the morph when store context is mid-boot.
        $user = $access?->tokenable;
        if (! $user && $access) {
            $user = Customer::withoutGlobalScopes()->find($access->tokenable_id);
        }
        if ($user instanceof Customer && $access) {
            $user->withAccessToken($access);
            Auth::guard('customer')->setUser($user);
        }
    }

    /** Delete the personal access token presented as Bearer, if any. */
    public static function revokeBearer(Request $request): bool
    {
        $plain = $request->bearerToken();
        if ($plain === null || $plain === '') {
            return false;
        }
        $access = PersonalAccessToken::findToken($plain);
        if (! $access) {
            return false;
        }
        $access->delete();

        return true;
    }
}
