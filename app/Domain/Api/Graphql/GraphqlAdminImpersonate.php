<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Auth\Models\AdminImpersonation;
use App\Domain\Customer\Models\Customer;
use App\Models\Admin;
use GraphQL\Error\UserError;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\NewAccessToken;

/**
 * Admin REST Impersonate — short-lived customer Sanctum token (1 hour).
 */
final class GraphqlAdminImpersonate
{
    public const TOKEN_NAME = 'admin-impersonation';

    public const TTL_MINUTES = 60;

    /**
     * @return array{
     *   token: string,
     *   customerId: string,
     *   customerEmail: string|null,
     *   customerName: string|null,
     *   impersonatedByAdminId: string,
     *   expiresAt: string,
     *   success: bool,
     *   message: string
     * }
     */
    public static function issue(int $customerId): array
    {
        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin) {
            throw new UserError('Forbidden.');
        }
        if (! $admin->hasPermission('customers.manage')) {
            throw new UserError('Forbidden.');
        }

        $customer = Customer::query()->find($customerId);
        if (! $customer) {
            throw new UserError('Customer not found.');
        }
        if (isset($customer->is_enabled) && ! $customer->is_enabled) {
            throw new UserError('Customer is disabled.');
        }

        // Drop prior impersonation tokens for this customer so only one live headless session.
        $customer->tokens()
            ->where('name', self::TOKEN_NAME)
            ->delete();

        $expiresAt = now()->addMinutes(self::TTL_MINUTES);
        /** @var NewAccessToken $issued */
        $issued = $customer->createToken(self::TOKEN_NAME, ['*'], $expiresAt);

        AdminImpersonation::query()->create([
            'admin_id' => $admin->id,
            'customer_id' => $customer->id,
            'started_at' => now(),
            'ip' => request()?->ip(),
        ]);

        $name = trim((string) ($customer->name ?: trim(($customer->first_name ?? '').' '.($customer->last_name ?? ''))));

        return [
            'token' => $issued->plainTextToken,
            'customerId' => (string) $customer->id,
            'customerEmail' => $customer->email,
            'customerName' => $name !== '' ? $name : null,
            'impersonatedByAdminId' => (string) $admin->id,
            'expiresAt' => $expiresAt->toIso8601String(),
            'success' => true,
            'message' => 'Impersonation token issued.',
        ];
    }
}
