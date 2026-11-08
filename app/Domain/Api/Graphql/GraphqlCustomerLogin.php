<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Customer\Models\Customer;
use GraphQL\Error\UserError;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Headless customer login for GraphQL (Shop REST API login parity).
 */
final class GraphqlCustomerLogin
{
    /**
     * @param  array<string, mixed>  $args
     * @return array{access_token: string, token_type: string, customer: array<string, mixed>}
     */
    public static function run(array $args): array
    {
        $email = strtolower(trim((string) ($args['email'] ?? '')));
        $password = (string) ($args['password'] ?? '');
        $device = trim((string) ($args['deviceName'] ?? 'graphql'));
        if ($device === '') {
            $device = 'graphql';
        }

        $ip = (string) request()->ip();
        $key = 'gql-customer-login:'.$ip.'|'.$email;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw new UserError('Too many login attempts.');
        }

        if (! \App\Domain\Settings\CustomerSettings::loginEmailPassword()) {
            throw new UserError('Email and password sign-in is turned off.');
        }

        $customer = Customer::query()
            ->inCurrentShop()
            ->where('email', $email)
            ->first();

        if (! $customer || ! Hash::check($password, $customer->password)) {
            RateLimiter::hit($key, 60);
            throw new UserError('Invalid credentials.');
        }
        if (! $customer->is_enabled) {
            RateLimiter::hit($key, 60);
            throw new UserError('Account disabled.');
        }
        if ($customer->totp_confirmed_at || $customer->hasPasskeys()) {
            throw new UserError('MFA is required for this account. Use the REST login flow.');
        }

        RateLimiter::clear($key);
        Auth::guard('customer')->login($customer);
        $token = $customer->createToken(mb_substr($device, 0, 80));

        $plain = $token->plainTextToken;

        return [
            'id' => (string) $customer->id,

            'access_token' => $plain,
            'accessToken' => $plain,
            'token' => $plain,
            'apiToken' => $plain,
            'token_type' => 'Bearer',
            'success' => true,
            'message' => 'Logged in successfully.',
            'customer' => [
                'id' => (string) $customer->id,
                'email' => $customer->email,
                'name' => $customer->displayName(),
                'firstName' => $customer->first_name,
                'lastName' => $customer->last_name,
                'phone' => $customer->phone,
            ],
        ];
    }
}
