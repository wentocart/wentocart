<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\IntegrationTokenPermissions;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

/**
 * Admin REST REST auth: /api/v1/console/{login|logout|forgot-password|get|update}
 */
class AdminAuthRestController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $email = strtolower(trim((string) $request->input('email', '')));
        $password = (string) $request->input('password', '');
        $device = trim((string) ($request->input('device_name') ?? $request->input('deviceName') ?? 'compat-admin'));
        if ($email === '' || $password === '') {
            return response()->json(['message' => 'Email and password are required.', 'error' => 'validation'], 422);
        }
        $admin = Admin::query()->where('email', $email)->first();
        if (! $admin || ! Hash::check($password, (string) $admin->password)) {
            return response()->json(['message' => 'Invalid credentials.', 'error' => 'credential_error'], 401);
        }
        if (! $admin->is_enabled) {
            return response()->json(['message' => 'Account is disabled.', 'error' => 'disabled'], 403);
        }
        $admin->tokens()->where('name', 'compat-admin-login')->delete();
        // same_as_web: follow the admin role/profile ACL (login token parity).
        $abilities = IntegrationTokenPermissions::merge(['admin:graphql', 'role:admin'], 'same');
        $token = $admin->createToken(mb_substr($device !== '' ? $device : 'compat-admin-login', 0, 120), $abilities);

        return response()->json([
            'message' => 'Logged in successfully.',
            'token' => $token->plainTextToken,
            'data' => $this->adminRow($admin),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin) {
            return response()->json(['message' => 'Unauthenticated.', 'error' => 'unauthenticated'], 401);
        }
        $access = $admin->currentAccessToken();
        if ($access) {
            $access->delete();
        } else {
            Auth::guard('admin')->logout();
        }

        return response()->json(['message' => 'Logged out successfully.']);
    }

    public function get(): JsonResponse
    {
        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin) {
            return response()->json(['message' => 'Unauthenticated.', 'error' => 'unauthenticated'], 401);
        }

        return response()->json(['data' => $this->adminRow($admin)]);
    }

    public function update(Request $request): JsonResponse
    {
        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin) {
            return response()->json(['message' => 'Unauthenticated.', 'error' => 'unauthenticated'], 401);
        }
        $payload = [];
        if ($request->filled('name')) {
            $payload['name'] = trim((string) $request->input('name'));
        }
        if ($request->filled('first_name') || $request->filled('firstName')) {
            $payload['first_name'] = trim((string) ($request->input('first_name') ?? $request->input('firstName')));
        }
        if ($request->filled('last_name') || $request->filled('lastName')) {
            $payload['last_name'] = trim((string) ($request->input('last_name') ?? $request->input('lastName')));
        }
        if ($request->filled('email')) {
            $email = strtolower(trim((string) $request->input('email')));
            if ($email !== '' && Admin::query()->where('email', $email)->where('id', '!=', $admin->id)->exists()) {
                return response()->json(['message' => 'Email already in use.', 'error' => 'validation'], 422);
            }
            $payload['email'] = $email;
        }
        if ($request->filled('password')) {
            $payload['password'] = (string) $request->input('password');
        }
        if ($payload !== []) {
            $admin->fill($payload)->save();
        }

        return response()->json([
            'message' => 'Account updated successfully.',
            'data' => $this->adminRow($admin->fresh() ?? $admin),
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $email = strtolower(trim((string) $request->input('email', '')));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['message' => 'A valid email is required.', 'error' => 'validation'], 422);
        }
        $status = Password::broker('admins')->sendResetLink(['email' => $email]);
        // Always 200 when link would be sent OR user unknown (no email enumeration).
        if ($status === Password::RESET_LINK_SENT || $status === Password::INVALID_USER) {
            return response()->json(['message' => 'If the account exists, a reset link has been sent.']);
        }

        return response()->json(['message' => __($status)], 400);
    }

    /**
     * @return array<string, mixed>
     */
    private function adminRow(Admin $admin): array
    {
        return [
            'id' => (int) $admin->id,
            'name' => (string) ($admin->name ?? ''),
            'firstName' => (string) ($admin->first_name ?? ''),
            'lastName' => (string) ($admin->last_name ?? ''),
            'email' => (string) $admin->email,
            'status' => $admin->is_enabled ? 1 : 0,
            'createdAt' => optional($admin->created_at)?->toIso8601String(),
        ];
    }
}
