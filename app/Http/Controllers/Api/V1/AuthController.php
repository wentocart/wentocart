<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Customer\Models\Customer;
use App\Domain\Plugin\Support\ModuleBuses;
use App\Domain\Security\CustomerPassword;
use App\Domain\Security\SessionRevoker;
use App\Domain\Security\Totp;
use App\Domain\Settings\CustomerSettings;
use App\Domain\Settings\GdprSettings;
use App\Domain\Settings\PrivacySettings;
use App\Domain\Settings\ShopMailer;
use App\Domain\Store\StoreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function registerOptions(): JsonResponse
    {
        $fields = [];
        foreach (ModuleBuses::collectSlots('storefront.register.fields') as $block) {
            if (($block['type'] ?? '') === 'field' && ! empty($block['name'])) {
                $fields[] = $block;
            }
        }

        return response()->json([
            'fields' => $fields,
            'config' => ModuleBuses::registerConfig(),
            'customers' => CustomerSettings::storefrontPayload(),
            'gdpr' => GdprSettings::storefrontPayload(),
            'honeypot' => [
                'trap_field' => \App\Domain\Security\Honeypot::TRAP_FIELD,
                'time_field' => \App\Domain\Security\Honeypot::TIME_FIELD,
                'token' => \App\Domain\Security\Honeypot::generateToken(),
            ],
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        if (! CustomerSettings::registrationEnabled()) {
            abort(403, 'Customer registration is disabled.');
        }

        $home = app(StoreContext::class)->requireId();
        // Own / share-from customers: stamp and uniqueness follow the customer home shop.
        $home = app(StoreContext::class)->customerHomeId() ?: $home;

        $emailRules = [
            'required',
            'email',
            Rule::unique('customers', 'email')->where(fn ($q) => $q->where('store_id', $home)),
        ];

        if ($request->exists('phone')) {
            $request->merge(['phone' => CustomerSettings::normalizePhone($request->input('phone'))]);
        }
        if (CustomerSettings::mobileLoginEnabled() && $request->exists('phone') && filled($request->input('phone'))) {
            $canonical = CustomerSettings::canonicalPhone($request->input('phone'));
            if ($canonical === null) {
                throw ValidationException::withMessages(['phone' => 'Enter a valid mobile number with country code (e.g. +15551234567).']);
            }
            $request->merge(['phone' => $canonical]);
        }

        $gdprRules = GdprSettings::agreementEnabled()
            ? ['gdpr_agreed' => ['accepted']]
            : ['gdpr_agreed' => ['nullable', 'boolean']];

        $data = $request->validate(array_merge([
            ...$gdprRules,
            'first_name' => ['required_without:name', 'nullable', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'name' => ['required_without:first_name', 'nullable', 'string', 'max:120'],
            'email' => $emailRules,
            'password' => CustomerPassword::rules(),
            'gender' => ['nullable', 'string', Rule::in(['male', 'female', 'other'])],
            'newsletter' => ['nullable', 'boolean'],
            'extra' => ['nullable', 'array'],
            'extra.*' => ['nullable', 'string', 'max:500'],
            'phone_token' => ['nullable', 'string', 'max:80'],
        ], [
            'phone' => CustomerSettings::mobileLoginEnabled()
                ? ['required', 'string', 'max:40', Rule::unique('customers', 'phone')->where(fn ($q) => $q->where('store_id', $home))]
                : CustomerSettings::phoneRules(),
            'date_of_birth' => match (CustomerSettings::dateOfBirthMode()) {
                'hidden' => ['prohibited'],
                'required' => ['required', 'date'],
                default => ['nullable', 'date'],
            },
        ]), array_merge(CustomerPassword::messages(), [
            'first_name.required_without' => 'Enter your first name.',
            'first_name.max' => 'First name can be at most 80 characters.',
            'last_name.max' => 'Last name can be at most 80 characters.',
            'email.required' => 'Enter your email address.',
            'email.email' => 'Enter a valid email address.',
            'email.unique' => 'An account with this email already exists.',
            'gender.in' => 'Choose a valid gender option.',
            'phone.required' => 'Enter your phone number.',
            'phone.regex' => 'Enter a phone number using digits only.',
            'phone.max' => 'Phone number is too long.',
            'date_of_birth.required' => 'Enter your date of birth.',
            'date_of_birth.date' => 'Enter a valid date of birth.',
        ]));

        $first = trim((string) ($data['first_name'] ?? ''));
        $last = trim((string) ($data['last_name'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        $verifiedPhone = null;
        if (CustomerSettings::mobileLoginEnabled()) {
            $verifiedPhone = CustomerSettings::canonicalPhone($data['phone'] ?? '');
            $proof = $verifiedPhone !== null
                ? app(\App\Domain\Auth\SmsOtp::class)->consumeProof((string) ($data['phone_token'] ?? ''), 'register')
                : null;
            if ($proof === null || $proof !== $verifiedPhone) {
                throw ValidationException::withMessages(['phone' => 'Verify your mobile number with the SMS code first.']);
            }
        }
        if ($first === '' && $name !== '') {
            $parts = preg_split('/\s+/', $name, 2) ?: [];
            $first = $parts[0] ?? '';
            $last = $last !== '' ? $last : ($parts[1] ?? '');
        }
        if ($first === '') {
            throw ValidationException::withMessages(['first_name' => 'First name is required.']);
        }
        $full = trim($first.' '.$last);

        $config = ModuleBuses::registerConfig();
        $extra = [];
        foreach ((array) ($data['extra'] ?? []) as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            $field = preg_replace('/[^a-z0-9_]/', '', strtolower($key)) ?? '';
            if ($field === '' || strlen($field) > 48) {
                continue;
            }
            $extra[$field] = trim(strip_tags((string) $value));
        }

        $group = CustomerSettings::resolveDefaultGroup();
        $activation = CustomerSettings::accountActivation();
        $enabled = (bool) $config['is_enabled'] && $activation === 'automatic';
        $verification = CustomerSettings::emailVerification();

        $customer = Customer::createTrusted([
            'name' => $full,
            'first_name' => $first,
            'last_name' => $last,
            'email' => $data['email'],
            'password' => $data['password'],
            'phone' => $verifiedPhone ?? (CustomerSettings::phoneMode() === 'hidden' ? null : ($data['phone'] ?? null)),
            'date_of_birth' => CustomerSettings::dateOfBirthMode() === 'hidden' ? null : ($data['date_of_birth'] ?? null),
            'gender' => in_array($data['gender'] ?? null, ['male', 'female', 'other'], true) ? $data['gender'] : null,
            'newsletter' => CustomerSettings::newsletterOptIn() ? (bool) ($data['newsletter'] ?? false) : false,
            'store_id' => $home,
            'is_enabled' => $enabled,
            'customer_group' => $group?->code,
            'customer_group_id' => $group?->id,
            'email_verified_at' => $verification === 'disabled' ? now() : null,
        ]);

        if ($extra !== []) {
            $profile = [];
            foreach ($extra as $key => $value) {
                if (in_array($key, ['company', 'tax_id', 'website', 'job_title', 'notes'], true)) {
                    $profile[$key] = $value;
                } else {
                    $profile['custom_'.$key] = $value;
                }
            }
            $customer->update(['profile' => $profile]);
        }

        ModuleBuses::fireRegistered((int) $customer->id, $extra);
        app(ShopMailer::class)->customerCreated($customer);

        if ($config['auto_login'] && $enabled && $verification !== 'required') {
            Auth::guard('customer')->login($customer);
            $this->regenerateRequestSession($request);

            return response()->json(['customer' => $customer->toStorefrontArray(), 'pending' => false]);
        }

        $pending = ! $enabled || ! $config['auto_login'] || $verification === 'required';
        $reason = ! $enabled ? 'approval' : ($verification === 'required' ? 'verify' : 'signin');
        $message = $reason === 'approval'
            ? 'Your account was created and is waiting for admin approval.'
            : ($reason === 'verify'
                ? 'Your account was created. Please verify your email before signing in.'
                : 'Your account was created. Please sign in.');

        return response()->json([
            'customer' => null,
            'pending' => $pending,
            'reason' => $reason,
            'message' => $message,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        if ($request->filled('phone_token')) {
            return $this->loginWithPhoneToken($request);
        }

        $request->validate([
            'email' => ['required', 'string', 'max:190'],
            'password' => ['required'],
        ]);

        $raw = trim((string) $request->input('email'));
        $emailHint = strtolower(filter_var($raw, FILTER_VALIDATE_EMAIL) ? $raw : $raw);
        $key = 'customer-login:'.$request->ip().'|'.$emailHint;
        $emailKey = 'customer-login-email:'.$emailHint;
        if (RateLimiter::tooManyAttempts($key, 5) || RateLimiter::tooManyAttempts($emailKey, 15)) {
            abort(429, 'Too many login attempts.');
        }

        $email = ModuleBuses::resolveCustomerLogin($raw);
        $usedPhone = false;
        if ($email === null) {
            if (filter_var($raw, FILTER_VALIDATE_EMAIL)) {
                $email = $raw;
            } elseif (($canonical = CustomerSettings::canonicalPhone($raw)) && CustomerSettings::loginMobilePassword()) {
                $found = $this->findByPhone($canonical);
                if (! $found) {
                    RateLimiter::hit($key, 60);
                    RateLimiter::hit($emailKey, 60);
                    abort(422, 'Invalid credentials.');
                }
                $email = $found->email;
                $usedPhone = true;
            } else {
                RateLimiter::hit($key, 60);
                RateLimiter::hit($emailKey, 60);
                abort(422, 'Invalid credentials.');
            }
        }

        if ($usedPhone && ! CustomerSettings::loginMobilePassword()) {
            abort(403, 'Mobile and password sign-in is turned off.');
        }
        if (! $usedPhone && ! CustomerSettings::loginEmailPassword()) {
            abort(403, 'Email and password sign-in is turned off.');
        }

        $emailKey = 'customer-login-email:'.strtolower($email);
        $key = 'customer-login:'.$request->ip().'|'.strtolower($email);
        if (RateLimiter::tooManyAttempts($key, 5) || RateLimiter::tooManyAttempts($emailKey, 15)) {
            abort(429, 'Too many login attempts.');
        }

        $customer = Customer::query()
            ->inCurrentShop()
            ->where('email', $email)
            ->first();
        if (! $customer || ! Hash::check($request->input('password'), $customer->password)) {
            RateLimiter::hit($key, 60);
            RateLimiter::hit($emailKey, 60);
            abort(422, 'Invalid credentials.');
        }

        if (! $customer->is_enabled) {
            RateLimiter::hit($key, 60);
            RateLimiter::hit($emailKey, 60);
            abort(403, 'Account disabled.');
        }

        if (CustomerSettings::emailVerification() === 'required' && ! $customer->email_verified_at) {
            RateLimiter::hit($key, 60);
            RateLimiter::hit($emailKey, 60);
            abort(403, 'Please verify your email before signing in.');
        }

        if ($customer->totp_confirmed_at || $customer->hasPasskeys()) {
            // Fresh session id before the MFA step so a pre-login fixed
            // session id cannot ride through the MFA pending window.
            $request->session()->regenerate();
            $request->session()->put('customer.mfa_pending_id', $customer->id);
            $request->session()->put('customer.mfa_remember', (bool) $request->boolean('remember'));
            RateLimiter::clear($key);
            RateLimiter::clear($emailKey);

            return response()->json([
                'mfa_required' => true,
                'passkey_available' => $customer->hasPasskeys(),
                'customer' => null,
            ]);
        }

        if (\App\Domain\Settings\CommunityFeatures::smsOtp() && filled($customer->phone)) {
            $otp = app(\App\Domain\Auth\SmsOtp::class);
            $code = $otp->send($customer);
            $request->session()->regenerate();
            $request->session()->put('customer.sms_pending_id', $customer->id);
            $request->session()->put('customer.mfa_remember', (bool) $request->boolean('remember'));
            RateLimiter::clear($key);
            RateLimiter::clear($emailKey);

            return response()->json([
                'sms_required' => true,
                'customer' => null,
                'sandbox_code' => $otp->revealInResponse() ? $code : null,
            ]);
        }

        Auth::guard('customer')->login($customer, (bool) $request->boolean('remember'));

        RateLimiter::clear($key);
        RateLimiter::clear($emailKey);
        $this->regenerateRequestSession($request);

        $payload = [
            'customer' => $customer->toStorefrontArray(),
            'enroll_required' => PrivacySettings::customerMfaRequired()
                && ! $customer->totp_confirmed_at
                && ! $customer->hasPasskeys(),
        ];

        // Headless / mobile clients (GraphQL Bearer parity): opt-in via device_name.
        $device = trim((string) $request->input('device_name', ''));
        if ($device !== '') {
            $token = $customer->createToken(mb_substr($device, 0, 80));
            $payload['access_token'] = $token->plainTextToken;
            $payload['token_type'] = 'Bearer';
        }

        return response()->json($payload);
    }

    public function verifyMfa(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string']]);
        $id = (int) $request->session()->get('customer.mfa_pending_id');
        $customer = Customer::query()->find($id);
        if (! $customer || ! $customer->totp_secret || ! Totp::verify((string) $customer->totp_secret, $request->string('code')->toString())) {
            abort(422, 'Invalid authenticator code.');
        }

        Auth::guard('customer')->login($customer, (bool) $request->session()->get('customer.mfa_remember'));
        $request->session()->forget(['customer.mfa_pending_id', 'customer.mfa_remember']);
        $this->regenerateRequestSession($request);

        return response()->json(['customer' => $customer->toStorefrontArray()]);
    }

    public function sendSms(Request $request): JsonResponse
    {
        if (! \App\Domain\Settings\CommunityFeatures::smsOtp()) {
            abort(404);
        }

        $request->validate([
            'email' => ['required', 'string', 'max:190'],
        ]);

        $key = 'customer-sms:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            abort(429, 'Too many SMS attempts.');
        }
        RateLimiter::hit($key, 60);

        $customer = Customer::query()
            ->inCurrentShop()
            ->where('email', trim((string) $request->input('email')))
            ->first();
        if (! $customer || ! $customer->is_enabled || ! filled($customer->phone)) {
            return response()->json(['ok' => true]);
        }

        $otp = app(\App\Domain\Auth\SmsOtp::class);
        $code = $otp->send($customer);
        $request->session()->put('customer.sms_pending_id', $customer->id);
        $request->session()->put('customer.mfa_remember', (bool) $request->boolean('remember'));

        return response()->json([
            'ok' => true,
            'sms_required' => true,
            'sandbox_code' => $otp->revealInResponse() ? $code : null,
        ]);
    }

    public function verifySms(Request $request): JsonResponse
    {
        if (! \App\Domain\Settings\CommunityFeatures::smsOtp()) {
            abort(404);
        }

        $request->validate(['code' => ['required', 'string']]);
        $id = (int) $request->session()->get('customer.sms_pending_id');
        $customer = Customer::query()->find($id);
        if (! $customer || ! app(\App\Domain\Auth\SmsOtp::class)->verify($customer, $request->string('code')->toString())) {
            abort(422, 'Invalid SMS code.');
        }

        Auth::guard('customer')->login($customer, (bool) $request->session()->get('customer.mfa_remember'));
        $request->session()->forget(['customer.sms_pending_id', 'customer.mfa_remember']);
        $this->regenerateRequestSession($request);

        return response()->json([
            'customer' => $customer->toStorefrontArray(),
            'enroll_required' => PrivacySettings::customerMfaRequired()
                && ! $customer->totp_confirmed_at
                && ! $customer->hasPasskeys(),
        ]);
    }

    public function sendOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:40'],
            'purpose' => ['required', Rule::in(['login', 'register', 'profile'])],
        ]);
        $this->guardOtpPurpose($data['purpose']);

        $phone = CustomerSettings::canonicalPhone($data['phone']);
        if ($phone === null) {
            throw ValidationException::withMessages(['phone' => 'Enter a valid mobile number with country code (e.g. +15551234567).']);
        }

        $key = 'customer-otp-send:'.$request->ip().'|'.$phone;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            abort(429, 'Too many code requests. Try again later.');
        }
        RateLimiter::hit($key, 3600);

        $customer = $this->findByPhone($phone);
        if ($data['purpose'] === 'login' && (! $customer || ! $customer->is_enabled)) {
            throw ValidationException::withMessages(['phone' => 'No account is registered with this number.']);
        }
        if ($data['purpose'] === 'register' && $customer) {
            throw ValidationException::withMessages(['phone' => 'This number is already registered. Sign in instead.']);
        }

        $otp = app(\App\Domain\Auth\SmsOtp::class);
        $code = $otp->sendTo($data['purpose'], $phone, $data['purpose']);

        return response()->json([
            'ok' => true,
            'sandbox_code' => $otp->revealInResponse() ? $code : null,
        ]);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:40'],
            'code' => ['required', 'string', 'max:12'],
            'purpose' => ['required', Rule::in(['login', 'register', 'profile'])],
        ]);
        $this->guardOtpPurpose($data['purpose']);

        $phone = CustomerSettings::canonicalPhone($data['phone']);
        $otp = app(\App\Domain\Auth\SmsOtp::class);
        if ($phone === null || ! $otp->verify($data['purpose'], $data['code'], $phone)) {
            $left = $otp->attemptsLeft($data['purpose'], $phone ?? trim((string) $data['phone']));
            throw ValidationException::withMessages([
                'code' => $left > 0
                    ? 'Invalid or expired code. '.$left.' attempt'.($left === 1 ? '' : 's').' left.'
                    : 'No attempts left. Request a new code.',
            ]);
        }

        return response()->json(['phone_token' => $otp->issueProof($phone, $data['purpose'])]);
    }

    private function guardOtpPurpose(string $purpose): void
    {
        if ($purpose === 'login' && ! CustomerSettings::loginMobileOtp()) {
            abort(404);
        }
        if (in_array($purpose, ['register', 'profile'], true) && ! CustomerSettings::mobileLoginEnabled()) {
            abort(404);
        }
    }

    private function findByPhone(string $phone): ?Customer
    {
        return Customer::query()->inCurrentShop()->where('phone', $phone)->first();
    }

    /**
     * Passwordless sign-in with a verified phone proof token.
     */
    private function loginWithPhoneToken(Request $request): JsonResponse
    {
        if (! CustomerSettings::loginMobileOtp()) {
            abort(404);
        }
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:40'],
            'phone_token' => ['required', 'string', 'max:80'],
        ]);

        $key = 'customer-otp-login:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            abort(429, 'Too many login attempts.');
        }

        $phone = CustomerSettings::canonicalPhone($data['phone']);
        $otp = app(\App\Domain\Auth\SmsOtp::class);
        $proven = $phone !== null ? $otp->consumeProof((string) $data['phone_token'], 'login') : null;
        if ($proven === null || $proven !== $phone) {
            RateLimiter::hit($key, 300);
            abort(422, 'Invalid or expired code. Request a new one.');
        }

        $customer = $this->findByPhone($phone);
        if (! $customer) {
            RateLimiter::hit($key, 300);
            abort(422, 'Invalid credentials.');
        }
        if (! $customer->is_enabled) {
            abort(403, 'Account disabled.');
        }
        if (CustomerSettings::emailVerification() === 'required' && ! $customer->email_verified_at) {
            abort(403, 'Please verify your email before signing in.');
        }

        if ($customer->totp_confirmed_at || $customer->hasPasskeys()) {
            $request->session()->regenerate();
            $request->session()->put('customer.mfa_pending_id', $customer->id);
            $request->session()->put('customer.mfa_remember', (bool) $request->boolean('remember'));

            return response()->json([
                'mfa_required' => true,
                'passkey_available' => $customer->hasPasskeys(),
                'customer' => null,
            ]);
        }

        Auth::guard('customer')->login($customer, (bool) $request->boolean('remember'));
        RateLimiter::clear($key);
        $this->regenerateRequestSession($request);

        return response()->json(['customer' => $customer->toStorefrontArray()]);
    }

    public function enrollMfa(Request $request): JsonResponse
    {
        $customer = $request->user('customer');
        if (! $customer) {
            abort(401);
        }
        if (! $customer->totp_secret) {
            $customer->forceFill(['totp_secret' => Totp::secret()])->save();
        }
        $issuer = (string) config('wento.name', config('app.name'));

        return response()->json([
            'otpauth' => Totp::provisioningUri((string) $customer->totp_secret, (string) $customer->email, $issuer),
            'secret' => $customer->totp_secret,
        ]);
    }

    public function confirmMfa(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string']]);
        $customer = $request->user('customer');
        if (! $customer || ! $customer->totp_secret || ! Totp::verify((string) $customer->totp_secret, $request->string('code')->toString())) {
            abort(422, 'Invalid authenticator code.');
        }
        $customer->forceFill(['totp_confirmed_at' => now()])->save();

        return response()->json(['customer' => $customer->fresh()->toStorefrontArray()]);
    }

    public function disableMfa(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string']]);
        $customer = $request->user('customer');
        if (! $customer || ! $customer->totp_secret || ! Totp::verify((string) $customer->totp_secret, $request->string('code')->toString())) {
            abort(422, 'Invalid authenticator code.');
        }
        $customer->forceFill(['totp_secret' => null, 'totp_confirmed_at' => null])->save();

        return response()->json(['customer' => $customer->fresh()->toStorefrontArray()]);
    }

    public function passkeyRegisterOptions(Request $request): JsonResponse
    {
        $customer = $request->user('customer');
        abort_unless($customer, 401);

        return response()->json(app(\App\Domain\Security\WebAuthn::class)->registerOptions($customer));
    }

    public function passkeyRegister(Request $request): JsonResponse
    {
        $customer = $request->user('customer');
        abort_unless($customer, 401);
        app(\App\Domain\Security\WebAuthn::class)->register($customer, $request->all());

        return response()->json(['customer' => $customer->fresh()->toStorefrontArray()]);
    }

    public function passkeyOptions(Request $request): JsonResponse
    {
        $id = (int) $request->session()->get('customer.mfa_pending_id');
        $customer = $id > 0 ? Customer::query()->find($id) : $request->user('customer');
        abort_unless($customer, 401);

        return response()->json(app(\App\Domain\Security\WebAuthn::class)->assertionOptions($customer));
    }

    public function passkeyAssert(Request $request): JsonResponse
    {
        $id = (int) $request->session()->get('customer.mfa_pending_id');
        $customer = Customer::query()->find($id);
        abort_unless($customer, 401);
        app(\App\Domain\Security\WebAuthn::class)->assert($customer, $request->all());
        Auth::guard('customer')->login($customer, (bool) $request->session()->get('customer.mfa_remember'));
        $request->session()->forget(['customer.mfa_pending_id', 'customer.mfa_remember']);
        $this->regenerateRequestSession($request);

        return response()->json(['customer' => $customer->fresh()->toStorefrontArray()]);
    }

    public function logout(Request $request): JsonResponse
    {
        // Headless Bearer clients: drop the presented Sanctum token on logout.
        \App\Domain\Api\Graphql\GraphqlCustomerAuth::revokeBearer($request);

        Auth::guard('customer')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['ok' => true]);
    }

    public function me(Request $request): JsonResponse
    {
        $customer = $request->user('customer');
        if (! $customer) {
            return response()->json(['customer' => null]);
        }

        return response()->json(['customer' => $customer->toStorefrontArray() + [
            'impersonating' => (bool) $request->session()->get('impersonator_admin_id'),
        ]]);
    }

    public function forgot(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        Password::broker('customers')->sendResetLink($request->only('email'));

        return response()->json(['ok' => true]);
    }

    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => CustomerPassword::rules(),
        ], CustomerPassword::messages());

        $status = Password::broker('customers')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (Customer $customer, string $password) {
                $customer->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();
                // A reset proves email access, not possession of other devices.
                SessionRevoker::revokeOthers('customer', $customer->id);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            abort(422, 'This reset link is invalid or expired.');
        }

        return response()->json(['ok' => true]);
    }

    private function regenerateRequestSession(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
    }
}
