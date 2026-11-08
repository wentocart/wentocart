<?php

namespace App\Domain\Settings;

use App\Domain\Auth\SocialLogin;
use App\Domain\Customer\Models\CustomerGroup;

final class CustomerSettings
{
    /** @var list<string> */
    public const FIELD_MODES = ['required', 'optional', 'hidden'];

    /** @var list<string> */
    public const EMAIL_VERIFICATION_OPTIONS = ['required', 'optional', 'disabled'];

    /** @var list<string> */
    public const ACTIVATION_OPTIONS = ['automatic', 'admin_approval'];

    /** @var list<string> */
    public const STATE_MODES = ['country_rules', 'required', 'optional'];

    /** @var list<string> */
    public const POSTAL_MODES = ['country_rules', 'required', 'optional'];

    // ── Login methods (email+password, mobile+password, mobile+OTP) ──

    public static function loginEmailPassword(): bool
    {
        return ShopSetting::bool('auth.login_email_password', true);
    }

    public static function loginMobilePassword(): bool
    {
        return ShopSetting::bool('auth.login_mobile_password', false);
    }

    public static function loginMobileOtp(): bool
    {
        return ShopSetting::bool('auth.login_mobile_otp', false);
    }

    public static function mobileLoginEnabled(): bool
    {
        return self::loginMobilePassword() || self::loginMobileOtp();
    }

    /**
     * @return list<string>
     */
    public static function loginMethods(): array
    {
        $methods = [];
        if (self::loginEmailPassword()) {
            $methods[] = 'email_password';
        }
        if (self::loginMobilePassword()) {
            $methods[] = 'mobile_password';
        }
        if (self::loginMobileOtp()) {
            $methods[] = 'mobile_otp';
        }

        return $methods !== [] ? $methods : ['email_password'];
    }

    public static function otpExpiryMinutes(): int
    {
        return min(10, max(2, (int) ShopSetting::get('auth.otp_expiry_minutes', 5)));
    }

    public static function otpMaxAttempts(): int
    {
        return min(10, max(1, (int) ShopSetting::get('auth.otp_max_attempts', 5)));
    }

    public static function otpResendSeconds(): int
    {
        return min(600, max(0, (int) ShopSetting::get('auth.otp_resend_seconds', 60)));
    }

    /**
     * Canonical international format (+<country><number>) used for login
     * matching and uniqueness. Returns null when the value is not a
     * plausible international number.
     */
    public static function canonicalPhone(mixed $phone): ?string
    {
        $digits = preg_replace('/[^\d+]/', '', trim((string) $phone)) ?? '';
        if (! str_starts_with($digits, '+')) {
            return null;
        }
        $digits = '+' . ltrim(substr($digits, 1), '0');
        if (! preg_match('/^\+\d{7,15}$/', $digits)) {
            return null;
        }

        return $digits;
    }

    // ── Accounts ─────────────────────────────────────────────────────

    public static function registrationEnabled(): bool
    {
        return ShopSetting::bool('customers.registration_enabled', true);
    }

    public static function guestCheckout(): bool
    {
        if (ShopSetting::get('customers.guest_checkout', null) !== null) {
            return ShopSetting::bool('customers.guest_checkout', true);
        }

        // Legacy Order Settings key (one authoritative home is Customers).
        return ShopSetting::bool('orders.guest_checkout', true);
    }

    public static function emailVerification(): string
    {
        $raw = trim((string) ShopSetting::get('customers.email_verification', 'optional'));

        return in_array($raw, self::EMAIL_VERIFICATION_OPTIONS, true) ? $raw : 'optional';
    }

    public static function accountActivation(): string
    {
        $raw = trim((string) ShopSetting::get('customers.account_activation', 'automatic'));

        return in_array($raw, self::ACTIVATION_OPTIONS, true) ? $raw : 'automatic';
    }

    public static function allowAccountDeletion(): bool
    {
        return ShopSetting::bool('customers.allow_account_deletion', false);
    }

    public static function allowProfileEditing(): bool
    {
        return ShopSetting::bool('customers.allow_profile_editing', true);
    }

    public static function phoneMode(): string
    {
        return self::fieldMode('customers.phone_mode', 'optional');
    }

    /**
     * A dial code alone is not a phone number. Letters stay as-is so validation can reject them.
     */
    public static function blankPhone(mixed $phone): bool
    {
        $phone = trim((string) $phone);
        if ($phone === '') {
            return true;
        }
        if (preg_match('/[A-Za-z]/', $phone)) {
            return false;
        }
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return strlen($digits) < 6;
    }

    public static function normalizePhone(mixed $phone): ?string
    {
        if (self::blankPhone($phone)) {
            return null;
        }

        $phone = trim((string) $phone);
        if (preg_match('/[A-Za-z]/', $phone)) {
            return $phone;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return str_starts_with($phone, '+') ? '+'.$digits : $digits;
    }

    /**
     * @return list<string|\Illuminate\Contracts\Validation\ValidationRule>
     */
    public static function phoneRules(?string $mode = null): array
    {
        $mode ??= self::phoneMode();
        $format = ['string', 'max:40', 'regex:/^(\+\d{1,4}\s?)?\d{6,15}$/'];

        return match ($mode) {
            'hidden' => ['prohibited'],
            'required' => array_merge(['required'], $format),
            default => array_merge(['nullable'], $format),
        };
    }

    public static function dateOfBirthMode(): string
    {
        return self::fieldMode('customers.dob_mode', 'hidden');
    }

    public static function newsletterOptIn(): bool
    {
        return ShopSetting::bool('customers.newsletter_opt_in', true);
    }

    public static function defaultGroupCode(): string
    {
        $raw = trim((string) ShopSetting::get('customers.default_group_code', 'retailer'));
        if ($raw === '') {
            return 'retailer';
        }

        return $raw;
    }

    // ── Groups engine ────────────────────────────────────────────────

    public static function groupsEnabled(): bool
    {
        return ShopSetting::bool('customers.groups_enabled', true);
    }

    public static function groupSpecificPricing(): bool
    {
        return self::groupsEnabled() && ShopSetting::bool('customers.group_pricing', false);
    }

    public static function groupSpecificDiscounts(): bool
    {
        return self::groupsEnabled() && ShopSetting::bool('customers.group_discounts', true);
    }

    // ── Addresses ────────────────────────────────────────────────────

    public static function requireBillingAddress(): bool
    {
        return ShopSetting::bool('customers.address.require_billing', true);
    }

    public static function requireShippingAddress(): bool
    {
        return ShopSetting::bool('customers.address.require_shipping', true);
    }

    public static function billingSameAsShippingDefault(): bool
    {
        return ShopSetting::bool('customers.address.billing_same_default', true);
    }

    public static function allowMultipleAddresses(): bool
    {
        return ShopSetting::bool('customers.address.allow_multiple', true);
    }

    public static function maxSavedAddresses(): ?int
    {
        if (! self::allowMultipleAddresses()) {
            return 1;
        }
        $raw = ShopSetting::get('customers.address.max_saved', null);
        if ($raw === null || $raw === '' || (int) $raw < 1) {
            return null;
        }

        return min(100, (int) $raw);
    }

    public static function addressPhoneMode(): string
    {
        return self::fieldMode('customers.address.phone_mode', 'optional');
    }

    public static function addressCompanyMode(): string
    {
        return self::fieldMode('customers.address.company_mode', 'optional');
    }

    public static function addressTaxIdMode(): string
    {
        return self::fieldMode('customers.address.tax_id_mode', 'hidden');
    }

    public static function addressLine2Mode(): string
    {
        return self::fieldMode('customers.address.line2_mode', 'optional');
    }

    public static function addressStateMode(): string
    {
        $raw = trim((string) ShopSetting::get('customers.address.state_mode', 'country_rules'));

        return in_array($raw, self::STATE_MODES, true) ? $raw : 'country_rules';
    }

    public static function addressPostalMode(): string
    {
        $raw = trim((string) ShopSetting::get('customers.address.postal_mode', 'country_rules'));

        return in_array($raw, self::POSTAL_MODES, true) ? $raw : 'country_rules';
    }

    /**
     * @return list<array{id:int,code:string,name:string}>
     */
    public static function groupOptions(): array
    {
        return CustomerGroup::catalog(true)
            ->map(fn (CustomerGroup $g) => [
                'id' => (int) $g->id,
                'code' => (string) $g->code,
                'name' => (string) $g->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(): array
    {
        return [
            'accounts' => [
                'registration_enabled' => self::registrationEnabled(),
                'guest_checkout' => self::guestCheckout(),
                'email_verification' => self::emailVerification(),
                'account_activation' => self::accountActivation(),
                'default_group_code' => self::defaultGroupCode(),
                'allow_account_deletion' => self::allowAccountDeletion(),
                'allow_profile_editing' => self::allowProfileEditing(),
                'phone_mode' => self::phoneMode(),
                'dob_mode' => self::dateOfBirthMode(),
                'newsletter_opt_in' => self::newsletterOptIn(),
            ],
            'groups' => [
                'groups_enabled' => self::groupsEnabled(),
                'default_group_code' => self::defaultGroupCode(),
                'group_pricing' => self::groupSpecificPricing() || ShopSetting::bool('customers.group_pricing', false),
                'group_discounts' => ShopSetting::bool('customers.group_discounts', true),
            ],
            'addresses' => [
                'require_billing' => self::requireBillingAddress(),
                'require_shipping' => self::requireShippingAddress(),
                'billing_same_default' => self::billingSameAsShippingDefault(),
                'allow_multiple' => self::allowMultipleAddresses(),
                'max_saved' => self::maxSavedAddresses(),
                'phone_mode' => self::addressPhoneMode(),
                'company_mode' => self::addressCompanyMode(),
                'tax_id_mode' => self::addressTaxIdMode(),
                'line2_mode' => self::addressLine2Mode(),
                'state_mode' => self::addressStateMode(),
                'postal_mode' => self::addressPostalMode(),
            ],
            'groupOptions' => self::groupOptions(),
            'fieldModes' => self::FIELD_MODES,
            'emailVerificationOptions' => self::EMAIL_VERIFICATION_OPTIONS,
            'activationOptions' => self::ACTIVATION_OPTIONS,
            'stateModes' => self::STATE_MODES,
            'postalModes' => self::POSTAL_MODES,
            'social' => SocialLogin::adminPayload(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function storefrontPayload(): array
    {
        return [
            'registration_enabled' => self::registrationEnabled(),
            'guest_checkout' => self::guestCheckout(),
            'email_verification' => self::emailVerification(),
            'account_activation' => self::accountActivation(),
            'allow_account_deletion' => self::allowAccountDeletion(),
            'allow_profile_editing' => self::allowProfileEditing(),
            'phone_mode' => self::phoneMode(),
            'dob_mode' => self::dateOfBirthMode(),
            'newsletter_opt_in' => self::newsletterOptIn(),
            'login_methods' => self::loginMethods(),
            'otp' => [
                'expiry_minutes' => self::otpExpiryMinutes(),
                'max_attempts' => self::otpMaxAttempts(),
                'resend_seconds' => self::otpResendSeconds(),
            ],
            'groups_enabled' => self::groupsEnabled(),
            'addresses' => [
                'require_billing' => self::requireBillingAddress(),
                'require_shipping' => self::requireShippingAddress(),
                'billing_same_default' => self::billingSameAsShippingDefault(),
                'allow_multiple' => self::allowMultipleAddresses(),
                'max_saved' => self::maxSavedAddresses(),
                'phone_mode' => self::addressPhoneMode(),
                'company_mode' => self::addressCompanyMode(),
                'tax_id_mode' => self::addressTaxIdMode(),
                'line2_mode' => self::addressLine2Mode(),
                'state_mode' => self::addressStateMode(),
                'postal_mode' => self::addressPostalMode(),
            ],
            'social' => SocialLogin::publicFlags(),
        ];
    }

    /**
     * Laravel validation rule fragment for a field mode.
     *
     * @return list<string>
     */
    public static function modeRules(string $mode, string $base = 'string', int $max = 120): array
    {
        return match ($mode) {
            'hidden' => ['prohibited'],
            'required' => ['required', $base, 'max:'.$max],
            default => ['nullable', $base, 'max:'.$max],
        };
    }

    public static function customerGroup(?\App\Domain\Customer\Models\Customer $customer): ?CustomerGroup
    {
        if (! $customer || ! self::groupsEnabled()) {
            return null;
        }

        $customer->loadMissing('group');
        if ($customer->group && $customer->group->is_enabled) {
            return $customer->group;
        }

        if ($customer->customer_group_id) {
            return CustomerGroup::query()
                ->whereKey($customer->customer_group_id)
                ->where('is_enabled', true)
                ->first();
        }

        return null;
    }

    public static function groupDiscountPercent(?\App\Domain\Customer\Models\Customer $customer): float
    {
        if (! self::groupSpecificPricing()) {
            return 0.0;
        }

        $percent = (float) (self::customerGroup($customer)?->discount_percent ?? 0);

        return max(0, min(100, $percent));
    }

    public static function applyGroupDiscount(float $price, ?\App\Domain\Customer\Models\Customer $customer): float
    {
        $percent = self::groupDiscountPercent($customer);
        if ($percent <= 0) {
            return max(0, $price);
        }

        return max(0, round($price * (1 - ($percent / 100)), 2));
    }

    public static function resolveDefaultGroup(): ?CustomerGroup
    {
        $code = self::defaultGroupCode();
        $group = CustomerGroup::query()->where('code', $code)->where('is_enabled', true)->first();
        if ($group) {
            return $group;
        }

        return CustomerGroup::query()->where('is_enabled', true)->orderBy('position')->orderBy('name')->first();
    }

    /**
     * Validation rules for one address payload (billing/shipping/saved).
     *
     * @return array<string, list<string>>
     */
    public static function addressFieldValidation(string $prefix = '', ?string $countryCode = null, bool $enforceRequired = true): array
    {
        $p = $prefix === '' ? '' : rtrim($prefix, '.').'.';
        $country = $countryCode
            ? \App\Domain\Localization\Models\Country::query()->where('code', strtoupper($countryCode))->first()
            : null;

        $stateMode = self::addressStateMode();
        $stateRules = match ($stateMode) {
            'required' => ['required', 'string', 'max:120'],
            'optional' => ['nullable', 'string', 'max:120'],
            default => ($country && $country->has_regions
                ? ['required', 'string', 'max:120']
                : ['nullable', 'string', 'max:120']),
        };

        $postalMode = self::addressPostalMode();
        $postalRules = \App\Domain\Localization\PostalCode::rules(
            $postalMode,
            $country?->code,
        );

        $rules = [
            $p.'phone' => self::phoneRules(self::addressPhoneMode()),
            $p.'line2' => self::modeRules(self::addressLine2Mode(), 'string', 190),
            $p.'company' => self::modeRules(self::addressCompanyMode(), 'string', 120),
            $p.'tax_id' => self::modeRules(self::addressTaxIdMode(), 'string', 64),
            $p.'state' => $stateRules,
            $p.'postal_code' => $postalRules,
        ];

        if ($enforceRequired || $prefix === '') {
            return $rules;
        }

        $parent = rtrim($prefix, '.');
        foreach ($rules as $key => $set) {
            $rules[$key] = array_map(
                fn ($rule) => $rule === 'required' ? 'required_with:'.$parent : $rule,
                $set,
            );
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public static function addressValidationMessages(string $prefix = ''): array
    {
        $p = $prefix === '' ? '' : rtrim($prefix, '.').'.';

        return [
            $p.'state.required' => 'Select a state.',
            $p.'postal_code.required' => 'Enter a postal code.',
        ];
    }

    protected static function fieldMode(string $key, string $default): string
    {
        $raw = trim((string) ShopSetting::get($key, $default));

        return in_array($raw, self::FIELD_MODES, true) ? $raw : $default;
    }
}
