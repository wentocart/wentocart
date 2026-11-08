<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\ShopSchema;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Services\CustomerAnonymizer;
use App\Domain\Security\CustomerPassword;
use App\Domain\Settings\CustomerSettings;
use App\Domain\Store\StoreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

/**
 * Shop REST customer auth REST: login / logout / register / profile.
 */
class ShopCustomerAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        if (! CustomerSettings::loginEmailPassword()) {
            return response()->json([
                'success' => false,
                'message' => 'Email and password sign-in is turned off.',
            ], 403);
        }

        $email = strtolower(trim((string) $request->input('email', '')));
        $password = (string) $request->input('password', '');
        if ($email === '' || $password === '') {
            return response()->json([
                'success' => false,
                'message' => 'The email and password fields are required.',
            ], 400);
        }

        $key = 'shop-login:'.$request->ip().'|'.$email;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['success' => false, 'message' => 'Too many login attempts.'], 429);
        }

        $customer = Customer::query()
            ->inCurrentShop()
            ->where('email', $email)
            ->first();

        if (! $customer || ! Hash::check($password, (string) $customer->password)) {
            RateLimiter::hit($key, 60);

            return response()->json([
                'success' => false,
                'message' => 'Invalid Email or Password',
            ], 401);
        }

        if (! $customer->is_enabled) {
            return response()->json(['success' => false, 'message' => 'Account disabled.'], 403);
        }

        if (CustomerSettings::emailVerification() === 'required' && ! $customer->email_verified_at) {
            return response()->json([
                'success' => false,
                'message' => 'Please verify your email before signing in.',
            ], 403);
        }

        if ($customer->totp_confirmed_at || $customer->hasPasskeys()) {
            return response()->json([
                'success' => false,
                'message' => 'Multi-factor authentication is required. Use /api/v1/auth/login.',
                'mfa_required' => true,
            ], 403);
        }

        RateLimiter::clear($key);
        $plain = $customer->createToken('shop')->plainTextToken;

        return response()->json([
            'id' => (int) $customer->id,
            '_id' => (int) $customer->id,
            'token' => $plain,
            'apiToken' => hash('sha256', $plain),
            'success' => true,
            'message' => 'Customer login successfully.',
        ], 201);
    }

    /**
     * POST /api/v1/store/social-logins
     */
    public function socialLogin(Request $request): JsonResponse
    {
        $payload = ShopSchema::createSocialLoginMutation([
            'provider' => $request->input('provider'),
            'idToken' => $request->input('idToken') ?? $request->input('id_token'),
            'accessToken' => $request->input('accessToken') ?? $request->input('access_token'),
            'deviceToken' => $request->input('deviceToken') ?? $request->input('device_token'),
        ]);

        $status = ! empty($payload['success']) ? 201 : 400;

        return response()->json([
            'id' => isset($payload['_id']) ? (int) $payload['_id'] : (int) ($payload['id'] ?? 0),
            '_id' => isset($payload['_id']) ? (int) $payload['_id'] : null,
            'token' => $payload['token'] ?? null,
            'apiToken' => $payload['apiToken'] ?? null,
            'firstName' => $payload['firstName'] ?? null,
            'lastName' => $payload['lastName'] ?? null,
            'email' => $payload['email'] ?? null,
            'phone' => $payload['phone'] ?? null,
            'isNewCustomer' => (bool) ($payload['isNewCustomer'] ?? false),
            'success' => (bool) ($payload['success'] ?? false),
            'message' => (string) ($payload['message'] ?? ''),
            'code' => $payload['code'] ?? null,
        ], $status);
    }

    /**
     * Resolves only the Authorization Bearer (not session) so a stale cookie cannot fake validity.
     */
    public function verifyToken(Request $request): JsonResponse
    {
        $customer = null;
        $bearer = trim((string) $request->bearerToken());
        if ($bearer !== '') {
            $token = \Laravel\Sanctum\PersonalAccessToken::findToken($bearer);
            $tokenable = $token?->tokenable;
            if ($tokenable instanceof Customer && $tokenable->is_enabled) {
                $customer = $tokenable;
            }
        }

        if (! $customer instanceof Customer) {
            return response()->json([
                'id' => 0,
                'firstName' => '',
                'lastName' => '',
                'email' => '',
                'isValid' => false,
                'message' => 'Unauthenticated. Please login to perform this action',
            ], 201);
        }

        $first = trim((string) ($customer->first_name ?? ''));
        $last = trim((string) ($customer->last_name ?? ''));
        if ($first === '' && $last === '') {
            $parts = preg_split('/\s+/', trim((string) $customer->name), 2) ?: [];
            $first = (string) ($parts[0] ?? '');
            $last = (string) ($parts[1] ?? '');
        }

        return response()->json([
            'id' => (int) $customer->id,
            'firstName' => $first,
            'lastName' => $last,
            'email' => (string) $customer->email,
            'isValid' => true,
            'message' => 'Token is valid',
        ], 201);
    }

    public function logout(Request $request): JsonResponse
    {
        $customer = $request->user('customer');
        if (! $customer instanceof Customer) {
            return response()->json([
                'detail' => 'Unauthenticated. Please login to perform this action',
                'message' => 'Unauthenticated. Please login to perform this action',
            ], 403);
        }

        \App\Domain\Api\Graphql\GraphqlCustomerAuth::revokeBearer($request);
        auth('customer')->logout();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    public function register(Request $request): JsonResponse
    {
        if (! CustomerSettings::registrationEnabled()) {
            return response()->json(['success' => false, 'message' => 'Customer registration is disabled.'], 403);
        }

        $home = app(StoreContext::class)->customerHomeId() ?: app(StoreContext::class)->requireId();

        // Map camelCase → Wento fields
        $request->merge([
            'first_name' => $request->input('first_name', $request->input('firstName')),
            'last_name' => $request->input('last_name', $request->input('lastName')),
            'password_confirmation' => $request->input('password_confirmation', $request->input('confirmPassword')),
            'date_of_birth' => $request->input('date_of_birth', $request->input('dateOfBirth')),
            'newsletter' => $request->boolean('newsletter')
                || $request->boolean('subscribedToNewsLetter')
                || $request->boolean('subscribed_to_news_letter'),
        ]);

        if ($request->filled('gender')) {
            $g = strtolower((string) $request->input('gender'));
            $request->merge(['gender' => $g]);
        }

        try {
            $data = $request->validate([
                'first_name' => ['required', 'string', 'max:80'],
                'last_name' => ['required', 'string', 'max:80'],
                'email' => [
                    'required',
                    'email',
                    Rule::unique('customers', 'email')->where(fn ($q) => $q->where('store_id', $home)),
                ],
                'password' => CustomerPassword::rules(),
                'phone' => ['nullable', 'string', 'max:40'],
                'gender' => ['nullable', 'string', Rule::in(['male', 'female', 'other'])],
                'date_of_birth' => ['nullable', 'date'],
                'newsletter' => ['nullable', 'boolean'],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first() ?: 'Validation failed.',
                'errors' => $e->errors(),
            ], 400);
        }

        $first = trim((string) $data['first_name']);
        $last = trim((string) $data['last_name']);
        $full = trim($first.' '.$last);
        $needsVerify = CustomerSettings::emailVerification() === 'required';

        $customer = Customer::createTrusted([
            'store_id' => $home,
            'name' => $full,
            'first_name' => $first,
            'last_name' => $last,
            'email' => strtolower((string) $data['email']),
            'password' => (string) $data['password'],
            'phone' => $data['phone'] ?? null,
            'gender' => $data['gender'] ?? null,
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'newsletter' => (bool) ($data['newsletter'] ?? false),
            'is_enabled' => true,
            'email_verified_at' => $needsVerify ? null : now(),
        ]);

        $plain = $customer->createToken('shop')->plainTextToken;
        $storeId = (int) (app(StoreContext::class)->id() ?: $home);

        return response()->json([
            'id' => (int) $customer->id,
            'firstName' => $first,
            'lastName' => $last,
            'email' => (string) $customer->email,
            'phone' => $customer->phone,
            'gender' => $customer->gender,
            'dateOfBirth' => $customer->date_of_birth?->toDateString(),
            'apiToken' => hash('sha256', $plain),
            'token' => $plain,
            'status' => 1,
            'subscribedToNewsLetter' => (bool) $customer->newsletter,
            'isVerified' => $needsVerify ? 0 : 1,
            'isSuspended' => 0,
            'name' => $full,
            'customerGroupId' => $customer->customer_group_id ? (int) $customer->customer_group_id : null,
            'storeId' => $storeId,
            'rememberToken' => null,
            'success' => true,
            'message' => 'Customer registered successfully.',
        ], 201);
    }

    public function profile(Request $request): JsonResponse
    {
        $customer = $request->user('customer');
        if (! $customer instanceof Customer) {
            return response()->json([
                'detail' => 'Unauthenticated. Please login to perform this action',
                'message' => 'Unauthenticated. Please login to perform this action',
            ], 403);
        }

        return response()->json([$this->profileRow($customer)]);
    }

    /**
     */
    public function updateProfileSelf(Request $request): JsonResponse
    {
        $customer = $request->user('customer');
        if (! $customer instanceof Customer) {
            return response()->json([
                'detail' => 'Unauthenticated. Please login to perform this action',
                'message' => 'Unauthenticated. Please login to perform this action',
            ], 403);
        }

        return $this->updateProfile($request, (int) $customer->id);
    }

    /**
     */
    public function updateProfile(Request $request, int|string $id): JsonResponse
    {
        $customer = $request->user('customer');
        if (! $customer instanceof Customer) {
            return response()->json([
                'detail' => 'Unauthenticated. Please login to perform this action',
                'message' => 'Unauthenticated. Please login to perform this action',
            ], 403);
        }

        if ((int) $id !== (int) $customer->id) {
            return response()->json([
                'detail' => 'Customer profile not found',
                'message' => 'Customer profile not found',
            ], 404);
        }

        if (! CustomerSettings::allowProfileEditing()) {
            return response()->json(['success' => false, 'message' => 'Profile editing is disabled.'], 403);
        }

        $input = $request->all();
        $wantsPassword = $request->filled('password')
            || $request->filled('confirmPassword')
            || $request->filled('currentPassword');

        if ($wantsPassword) {
            $current = (string) $request->input('currentPassword', $request->input('current_password', ''));
            $password = (string) $request->input('password', '');
            $confirm = (string) $request->input('confirmPassword', $request->input('password_confirmation', ''));
            if ($current === '' || $password === '' || $confirm === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'currentPassword, password, and confirmPassword are required to change password.',
                ], 400);
            }
            if ($password !== $confirm) {
                return response()->json(['success' => false, 'message' => 'password and confirmPassword must match.'], 400);
            }
            if (! Hash::check($current, (string) $customer->password)) {
                return response()->json(['success' => false, 'message' => 'The current password is incorrect.'], 400);
            }
            $customer->password = $password;
        }

        if (array_key_exists('firstName', $input) || array_key_exists('first_name', $input)) {
            $customer->first_name = trim((string) ($input['firstName'] ?? $input['first_name'] ?? ''));
        }
        if (array_key_exists('lastName', $input) || array_key_exists('last_name', $input)) {
            $customer->last_name = trim((string) ($input['lastName'] ?? $input['last_name'] ?? ''));
        }
        if ($customer->isDirty(['first_name', 'last_name']) || ($customer->first_name || $customer->last_name)) {
            $customer->name = trim(((string) $customer->first_name).' '.((string) $customer->last_name));
        }

        if (array_key_exists('email', $input)) {
            $email = strtolower(trim((string) $input['email']));
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return response()->json(['success' => false, 'message' => 'A valid email is required.'], 400);
            }
            $taken = Customer::query()
                ->where('store_id', $customer->store_id)
                ->where('email', $email)
                ->where('id', '!=', $customer->id)
                ->exists();
            if ($taken) {
                return response()->json(['success' => false, 'message' => 'The email has already been taken.'], 400);
            }
            $customer->email = $email;
        }

        if (array_key_exists('phone', $input)) {
            $phone = trim((string) $input['phone']);
            if ($phone !== '' && preg_match('/^\d+$/', $phone) !== 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'Mobile number can only contain digits. Special characters are not allowed',
                ], 400);
            }
            $customer->phone = $phone !== '' ? $phone : null;
        }

        if (array_key_exists('gender', $input)) {
            $g = strtolower(trim((string) $input['gender']));
            $map = [
                'm' => 'male', 'male' => 'male',
                'f' => 'female', 'female' => 'female',
                'o' => 'other', 'other' => 'other',
            ];
            if ($g !== '' && ! isset($map[$g])) {
                return response()->json(['success' => false, 'message' => 'gender must be Male, Female, or Other.'], 400);
            }
            $customer->gender = $g === '' ? null : $map[$g];
        }

        if (array_key_exists('dateOfBirth', $input) || array_key_exists('date_of_birth', $input)) {
            $dob = trim((string) ($input['dateOfBirth'] ?? $input['date_of_birth'] ?? ''));
            $customer->date_of_birth = $dob !== '' ? $dob : null;
        }

        if (array_key_exists('subscribedToNewsLetter', $input)
            || array_key_exists('subscribed_to_news_letter', $input)
            || array_key_exists('newsletter', $input)) {
            $customer->newsletter = (bool) ($input['subscribedToNewsLetter']
                ?? $input['subscribed_to_news_letter']
                ?? $input['newsletter']);
        }

        $customer->save();

        $row = $this->profileRow($customer->fresh());
        $row['id'] = (string) $customer->id;
        $row['_id'] = (string) $customer->id;
        $row['isVerified'] = $customer->email_verified_at ? 'true' : 'false';
        $row['isSuspended'] = 'false';
        $row['success'] = true;
        $row['message'] = 'Customer profile updated successfully.';

        return response()->json($row);
    }

    /**
     */
    public function deleteProfile(Request $request, int|string $id): JsonResponse
    {
        $customer = $request->user('customer');
        if (! $customer instanceof Customer) {
            return response()->json([
                'detail' => 'Unauthenticated. Please login to perform this action',
                'message' => 'Unauthenticated. Please login to perform this action',
            ], 403);
        }

        if ((int) $id !== (int) $customer->id) {
            return response()->json([
                'detail' => 'Customer profile not found',
                'message' => 'Customer profile not found',
            ], 404);
        }

        if (! CustomerSettings::allowAccountDeletion()) {
            return response()->json(['success' => false, 'message' => 'Account deletion is disabled.'], 403);
        }

        // Revoke Sanctum tokens (Note: all access tokens revoked)
        if (method_exists($customer, 'tokens')) {
            $customer->tokens()->delete();
        }

        app(CustomerAnonymizer::class)->erase($customer);
        $response = new JsonResponse;
        $response->setStatusCode(201);
        $response->headers->set('Content-Type', 'application/json');
        $response->setContent('null');

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function profileRow(Customer $customer): array
    {
        $row = [
            'id' => (string) $customer->id,
            'firstName' => (string) ($customer->first_name ?? ''),
            'lastName' => (string) ($customer->last_name ?? ''),
            'email' => (string) $customer->email,
            'phone' => $customer->phone,
            'status' => $customer->is_enabled ? '1' : '0',
            'subscribedToNewsLetter' => (bool) $customer->newsletter,
            'isVerified' => $customer->email_verified_at ? '1' : '0',
            'isSuspended' => '0',
            'image' => null,
            'password' => null,
            'confirmPassword' => null,
            'success' => null,
            'message' => null,
        ];
        if ($customer->gender) {
            $row['gender'] = ucfirst((string) $customer->gender);
        }
        if ($customer->date_of_birth) {
            $row['dateOfBirth'] = $customer->date_of_birth->toDateString();
        }

        return $row;
    }
}
