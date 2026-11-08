<?php

namespace App\Http\Controllers\Storefront;

use App\Domain\Auth\SocialLogin;
use App\Domain\Customer\Models\Customer;
use App\Domain\Settings\CustomerSettings;
use App\Domain\Store\StoreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Throwable;

class SocialAuthController extends Controller
{
    public function redirect(string $provider): RedirectResponse
    {
        if (! in_array($provider, SocialLogin::PROVIDERS, true) || ! SocialLogin::ready($provider)) {
            return $this->fail('off');
        }

        $state = Str::random(40);
        session([
            'social.oauth' => [
                'provider' => $provider,
                'state' => $state,
            ],
        ]);

        return redirect()->away(SocialLogin::authorizeUrl($provider, $state));
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        $saved = $request->session()->pull('social.oauth');
        $state = (string) $request->query('state', '');
        $savedState = is_array($saved) ? (string) ($saved['state'] ?? '') : '';
        if (! in_array($provider, SocialLogin::PROVIDERS, true)
            || ! is_array($saved)
            || ($saved['provider'] ?? '') !== $provider
            || $savedState === ''
            || ! hash_equals($savedState, $state)
            || $request->filled('error')
            || ! SocialLogin::ready($provider)
        ) {
            return $this->fail('denied');
        }

        try {
            $profile = SocialLogin::exchange($provider, (string) $request->query('code', ''));
        } catch (Throwable) {
            return $this->fail('denied');
        }

        if ($profile['id'] === '' || $profile['email'] === '' || ! filter_var($profile['email'], FILTER_VALIDATE_EMAIL)) {
            return $this->fail('email');
        }

        $customer = $this->resolveCustomer($provider, $profile);
        if (! $customer->is_enabled) {
            if ($customer->wasRecentlyCreated) {
                return redirect()->to(url('/register/pending'));
            }

            return $this->fail('disabled');
        }

        if ($customer->totp_confirmed_at || $customer->hasPasskeys()) {
            $request->session()->put('customer.mfa_pending_id', $customer->id);
            $request->session()->put('customer.mfa_remember', true);
            $passkey = $customer->hasPasskeys() ? '&passkey=1' : '';

            return redirect()->to(url('/login?social=mfa'.$passkey));
        }

        Auth::guard('customer')->login($customer, true);
        $request->session()->regenerate();

        return redirect()->to(url('/account'));
    }

    /**
     * @param  array{id: string, email: string, first_name: string, last_name: string}  $profile
     */
    protected function resolveCustomer(string $provider, array $profile): Customer
    {
        $column = SocialLogin::idColumn($provider);
        $linked = Customer::query()
            ->inCurrentShop()
            ->where($column, $profile['id'])
            ->first();
        if ($linked) {
            return $linked;
        }

        $existing = Customer::query()
            ->inCurrentShop()
            ->where('email', $profile['email'])
            ->first();
        if ($existing) {
            $existing->forceFill([
                $column => $profile['id'],
                'email_verified_at' => $existing->email_verified_at ?: now(),
            ])->save();

            return $existing;
        }

        $group = CustomerSettings::resolveDefaultGroup();
        $home = app(StoreContext::class)->customerHomeId() ?: app(StoreContext::class)->requireId();
        $full = trim($profile['first_name'].' '.$profile['last_name']);

        return Customer::createTrusted([
            'name' => $full !== '' ? $full : $profile['email'],
            'first_name' => $profile['first_name'],
            'last_name' => $profile['last_name'],
            'email' => $profile['email'],
            'password' => Str::password(40),
            $column => $profile['id'],
            'store_id' => $home,
            'is_enabled' => CustomerSettings::accountActivation() === 'automatic',
            'email_verified_at' => now(),
            'newsletter' => false,
            'customer_group' => $group?->code,
            'customer_group_id' => $group?->id,
        ]);
    }

    protected function fail(string $reason): RedirectResponse
    {
        return redirect()->to(url('/login?social='.urlencode($reason)));
    }
}
