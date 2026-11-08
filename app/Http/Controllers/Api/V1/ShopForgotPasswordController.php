<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Customer\Models\Customer;
use App\Domain\Security\CustomerPassword;
use App\Domain\Security\SessionRevoker;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Shop REST forgot-password REST + headless reset (has no reset API).
 */
class ShopForgotPasswordController extends Controller
{
    /**
     * POST /api/v1/store/forgot-passwords
     * Always HTTP 200. Anti-enumeration: same success body whether or not the email exists
     * Do not reveal whether the email exists.
     */
    public function forgot(Request $request): JsonResponse
    {
        $email = strtolower(trim((string) $request->input('email', '')));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json([
                'success' => false,
                'message' => 'Email address not found',
            ]);
        }

        $throttleKey = 'shop-forgot:'.$request->ip().'|'.$email;
        if (RateLimiter::tooManyAttempts($throttleKey, 1)) {
            // Same shape as throttle path, without revealing which case it was.
            return response()->json([
                'success' => false,
                'message' => 'Email address not found',
            ]);
        }
        RateLimiter::hit($throttleKey, 60);

        $exists = Customer::query()
            ->inCurrentShop()
            ->where('email', $email)
            ->exists();

        if ($exists) {
            Password::broker('customers')->sendResetLink(['email' => $email]);
        }

        // Always claim success for valid emails — no account-enumeration side channel.
        return response()->json([
            'success' => true,
            'message' => 'Reset link sent successfully to your email',
        ]);
    }

    /**
     * POST /api/v1/store/reset-passwords — headless reset (REST has no counterpart).
     */
    public function reset(Request $request): JsonResponse
    {
        $email = strtolower(trim((string) $request->input('email', '')));
        $token = (string) $request->input('token', '');
        $password = (string) $request->input('password', '');
        $confirm = (string) ($request->input('confirmPassword')
            ?? $request->input('passwordConfirmation')
            ?? $request->input('password_confirmation')
            ?? '');

        if ($email === '' || $token === '' || $password === '' || $confirm === '') {
            return response()->json([
                'success' => false,
                'message' => 'token, email, password, and confirmPassword are required.',
            ], 400);
        }
        if ($password !== $confirm) {
            return response()->json([
                'success' => false,
                'message' => 'password and confirmPassword must match.',
            ], 400);
        }

        try {
            validator(
                [
                    'email' => $email,
                    'token' => $token,
                    'password' => $password,
                    'password_confirmation' => $confirm,
                ],
                [
                    'email' => ['required', 'email'],
                    'token' => ['required', 'string'],
                    'password' => CustomerPassword::rules(),
                ],
                CustomerPassword::messages()
            )->validate();
        } catch (\Illuminate\Validation\ValidationException $e) {
            $msg = collect($e->errors())->flatten()->first() ?: 'Validation failed.';

            return response()->json(['success' => false, 'message' => (string) $msg], 422);
        }

        $status = Password::broker('customers')->reset(
            [
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $confirm,
                'token' => $token,
            ],
            function (Customer $customer, string $plain) {
                $customer->forceFill([
                    'password' => $plain,
                    'remember_token' => Str::random(60),
                ])->save();
                SessionRevoker::revokeOthers('customer', $customer->id);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'success' => false,
                'message' => 'This reset link is invalid or expired.',
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'Password reset successfully',
        ]);
    }
}
