<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Api\Models\StorefrontApiKey;
use App\Domain\Settings\ShopSetting;
use Illuminate\Http\Request;

/**
 *
 * Default: optional (BC). When ShopSetting api.require_storefront_key is true,
 * or when a key is presented, validation is enforced.
 */
final class GraphqlStorefrontKey
{
    public static function requireStorefrontKey(): bool
    {
        return ShopSetting::bool('api.require_storefront_key', false);
    }

    /**
     * @return array{ok: bool, status?: int, body?: array<string, mixed>}
     */
    public static function guard(Request $request): array
    {
        $presented = trim((string) $request->header('X-STOREFRONT-KEY', ''));
        $required = self::requireStorefrontKey();

        if ($presented === '') {
            if ($required) {
                return [
                    'ok' => false,
                    'status' => 401,
                    'body' => [
                        'message' => 'Unauthenticated.',
                        'error' => 'unauthenticated',
                        'errors' => [['message' => 'X-STOREFRONT-KEY is required.']],
                    ],
                ];
            }

            return ['ok' => true];
        }

        $row = StorefrontApiKey::findEnabledByPlain($presented);
        if (! $row) {
            return [
                'ok' => false,
                'status' => 401,
                'body' => [
                    'message' => 'Unauthenticated.',
                    'error' => 'unauthenticated',
                    'errors' => [['message' => 'Invalid X-STOREFRONT-KEY.']],
                ],
            ];
        }

        $row->forceFill(['last_used_at' => now()])->save();
        $request->attributes->set('wento.storefront_api_key', $row);

        return ['ok' => true];
    }
}
