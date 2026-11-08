<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Customer\Models\CustomerGroup;
use App\Domain\Settings\Models\FileChangeLog;
use App\Domain\Auth\SocialLogin;
use App\Domain\Settings\CustomerSettings;
use App\Domain\Settings\ReviewSettings;
use App\Domain\Settings\ShopSetting;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CustomerSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        $tab = $request->query('tab', 'accounts');
        if (! in_array($tab, ['accounts', 'groups', 'addresses', 'reviews'], true)) {
            $tab = 'accounts';
        }

        return Inertia::render('Settings/Customers', array_merge(CustomerSettings::payload(), [
            'tab' => $tab,
            'reviewSettings' => ReviewSettings::payload(),
        ]));
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'registration_enabled' => ['required', 'boolean'],
            'guest_checkout' => ['required', 'boolean'],
            'email_verification' => ['required', Rule::in(CustomerSettings::EMAIL_VERIFICATION_OPTIONS)],
            'account_activation' => ['required', Rule::in(CustomerSettings::ACTIVATION_OPTIONS)],
            'default_group_code' => ['required', 'string', 'max:64'],
            'allow_account_deletion' => ['required', 'boolean'],
            'allow_profile_editing' => ['required', 'boolean'],
            'phone_mode' => ['required', Rule::in(CustomerSettings::FIELD_MODES)],
            'dob_mode' => ['required', Rule::in(CustomerSettings::FIELD_MODES)],
            'newsletter_opt_in' => ['required', 'boolean'],
            'groups_enabled' => ['required', 'boolean'],
            'group_pricing' => ['required', 'boolean'],
            'group_discounts' => ['required', 'boolean'],
            'require_billing' => ['required', 'boolean'],
            'require_shipping' => ['required', 'boolean'],
            'billing_same_default' => ['required', 'boolean'],
            'allow_multiple' => ['required', 'boolean'],
            'max_saved' => ['nullable', 'integer', 'min:1', 'max:100'],
            'address_phone_mode' => ['required', Rule::in(CustomerSettings::FIELD_MODES)],
            'address_company_mode' => ['required', Rule::in(CustomerSettings::FIELD_MODES)],
            'address_tax_id_mode' => ['required', Rule::in(CustomerSettings::FIELD_MODES)],
            'address_line2_mode' => ['required', Rule::in(CustomerSettings::FIELD_MODES)],
            'address_state_mode' => ['required', Rule::in(CustomerSettings::STATE_MODES)],
            'address_postal_mode' => ['required', Rule::in(CustomerSettings::POSTAL_MODES)],
            'google_enabled' => ['sometimes', 'boolean'],
            'google_client_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'google_client_secret' => ['sometimes', 'nullable', 'string', 'max:255'],
            'facebook_enabled' => ['sometimes', 'boolean'],
            'facebook_client_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'facebook_client_secret' => ['sometimes', 'nullable', 'string', 'max:255'],
            'linkedin_enabled' => ['sometimes', 'boolean'],
            'linkedin_client_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'linkedin_client_secret' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $group = CustomerGroup::query()->where('code', $data['default_group_code'])->where('is_enabled', true)->first();
        if (! $group) {
            return back()->withErrors([
                'default_group_code' => 'Select an enabled customer group.',
            ]);
        }

        ShopSetting::put('customers.registration_enabled', (bool) $data['registration_enabled']);
        ShopSetting::put('customers.guest_checkout', (bool) $data['guest_checkout']);
        // Keep Order Settings consumers in sync until they fully migrate.
        ShopSetting::put('orders.guest_checkout', (bool) $data['guest_checkout']);
        ShopSetting::put('customers.email_verification', $data['email_verification']);
        ShopSetting::put('customers.account_activation', $data['account_activation']);
        ShopSetting::put('customers.default_group_code', $group->code);
        ShopSetting::put('customers.allow_account_deletion', (bool) $data['allow_account_deletion']);
        ShopSetting::put('customers.allow_profile_editing', (bool) $data['allow_profile_editing']);
        ShopSetting::put('customers.phone_mode', $data['phone_mode']);
        ShopSetting::put('customers.dob_mode', $data['dob_mode']);
        ShopSetting::put('customers.newsletter_opt_in', (bool) $data['newsletter_opt_in']);
        ShopSetting::put('customers.groups_enabled', (bool) $data['groups_enabled']);
        ShopSetting::put('customers.group_pricing', (bool) $data['group_pricing']);
        ShopSetting::put('customers.group_discounts', (bool) $data['group_discounts']);
        ShopSetting::put('customers.address.require_billing', (bool) $data['require_billing']);
        ShopSetting::put('customers.address.require_shipping', (bool) $data['require_shipping']);
        ShopSetting::put('customers.address.billing_same_default', (bool) $data['billing_same_default']);
        ShopSetting::put('customers.address.allow_multiple', (bool) $data['allow_multiple']);
        ShopSetting::put(
            'customers.address.max_saved',
            ! empty($data['max_saved']) ? (int) $data['max_saved'] : null
        );
        ShopSetting::put('customers.address.phone_mode', $data['address_phone_mode']);
        ShopSetting::put('customers.address.company_mode', $data['address_company_mode']);
        ShopSetting::put('customers.address.tax_id_mode', $data['address_tax_id_mode']);
        ShopSetting::put('customers.address.line2_mode', $data['address_line2_mode']);
        ShopSetting::put('customers.address.state_mode', $data['address_state_mode']);
        ShopSetting::put('customers.address.postal_mode', $data['address_postal_mode']);
        $this->saveSocial($data);
        FileChangeLog::record('updated', 'customers/settings');

        return back()->with('success', 'Customer settings saved.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function saveSocial(array $data): void
    {
        foreach (['google', 'facebook', 'linkedin'] as $provider) {
            if (! array_key_exists($provider.'_enabled', $data)) {
                continue;
            }
            ShopSetting::put('customers.social.'.$provider.'.enabled', (bool) $data[$provider.'_enabled']);
            ShopSetting::put('customers.social.'.$provider.'.client_id', trim((string) ($data[$provider.'_client_id'] ?? '')));
            $secret = trim((string) ($data[$provider.'_client_secret'] ?? ''));
            if ($secret !== '') {
                SocialLogin::putSecret($provider, $secret);
            }
        }
    }
}
