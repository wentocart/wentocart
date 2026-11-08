<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Localization\Models\Country;
use App\Domain\Ops\JumpShortcut;
use App\Domain\Security\AdminPath;
use App\Domain\Localization\Models\Language;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Ops\Operator;
use App\Domain\Settings\SessionLifetimes;
use App\Domain\Settings\ShopSetting;
use App\Domain\Settings\StorePurpose;
use App\Domain\Store\ShopIdentity;
use App\Domain\Store\StoreBootstrap;
use App\Domain\Store\StoreContext;
use App\Domain\Store\StoreService;
use App\Support\WebBase;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class StoreSettingsController extends Controller
{
    public function __construct(protected StoreService $shops) {}

    public function general(Request $request): Response
    {
        $shopChord = JumpShortcut::shopChord();

        return Inertia::render('Settings/General', [
            'adminAddress' => [
                'segment' => AdminPath::segment(),
                'origin' => rtrim($request->getSchemeAndHttpHost().$request->getBasePath(), '/'),
            ],
            'sessions' => SessionLifetimes::forAdmin(),
            'searchShortcut' => [
                'shortcut' => $shopChord ?? '',
                'default' => JumpShortcut::default(),
                'label' => JumpShortcut::label($shopChord ?? JumpShortcut::default()),
                'effective' => JumpShortcut::resolve($request->user('admin')),
            ],
        ]);
    }

    public function updateSearchShortcut(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'shortcut' => ['nullable', 'string', 'max:32'],
            'reset' => ['sometimes', 'boolean'],
        ]);

        if ($request->boolean('reset') || trim((string) ($data['shortcut'] ?? '')) === '') {
            JumpShortcut::putShop(null);

            return back()->with('success', 'Search shortcut reset to Ctrl+F.');
        }

        try {
            JumpShortcut::putShop((string) $data['shortcut']);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['shortcut' => $e->getMessage()]);
        }

        return back()->with('success', 'Search shortcut saved.');
    }

    public function updateSessions(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'admin_minutes' => ['required', 'integer', 'min:'.SessionLifetimes::MIN_MINUTES, 'max:'.SessionLifetimes::ADMIN_MAX_MINUTES],
            'customer_minutes' => ['required', 'integer', 'min:'.SessionLifetimes::MIN_MINUTES, 'max:'.SessionLifetimes::CUSTOMER_MAX_MINUTES],
        ]);
        SessionLifetimes::save($data);

        return back()->with('success', 'Cookie lifetimes saved.');
    }

    public function updateAdminPath(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'segment' => ['required', 'string', 'max:40'],
        ]);
        $previous = AdminPath::segment();
        AdminPath::write($data['segment']);
        $base = WebBase::path();
        $webPath = $base === '' ? '/' : $base;
        $oldPath = $webPath === '/' ? '/'.$previous : $webPath.'/'.$previous;
        if ($oldPath !== $webPath) {
            cookie()->queue(cookie()->forget((string) config('session.admin_cookie'), $oldPath));
        }

        return back()->with([
            'success' => 'Admin address saved.',
            'admin_path_next' => AdminPath::publicAbsolute('/general'),
        ]);
    }

    public function edit(): Response
    {
        $context = app(StoreContext::class);
        $store = $context->store() ?? StoreBootstrap::ensure();
        $store->loadMissing('urls');

        $identity = ShopIdentity::forAdmin($store);
        $defaultLang = Language::defaultCode();

        return Inertia::render('Settings/Store', [
            'store' => [
                'shop_name' => $identity['names'][$defaultLang] ?? ($store->name ?: (string) config('app.name')),
                'shop_email' => $identity['email'] ?: ($store->email ?: ''),
                'shop_url' => $store->primaryUrl()?->root() ?: (string) config('app.url'),
                'theme' => $store->theme ?: 'default',
                'is_default' => (bool) $store->is_default,
                'identity' => $identity,
            ],
            'countries' => Country::optionsForAddresses(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'shop_name' => ['required', 'string', 'max:120'],
            'shop_email' => ['required', 'email'],
            'names' => ['nullable', 'array'],
            'names.*' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'line1' => ['nullable', 'string', 'max:255'],
            'line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'postal' => ['nullable', 'string', 'max:20'],
            'country_code' => ['nullable', 'string', 'max:2'],
            'business_number' => ['nullable', 'string', 'max:64'],
            'footer' => ['nullable', 'array'],
            'footer.name' => ['nullable', 'boolean'],
            'footer.email' => ['nullable', 'boolean'],
            'footer.phone' => ['nullable', 'boolean'],
            'footer.address' => ['nullable', 'boolean'],
            'footer.business_number' => ['nullable', 'boolean'],
        ]);

        $store = app(StoreContext::class)->store() ?? StoreBootstrap::ensure();
        $defaultLang = Language::defaultCode();
        $names = is_array($data['names'] ?? null) ? $data['names'] : [];
        $names[$defaultLang] = $data['shop_name'];
        ShopIdentity::save($store, [
            'names' => $names,
            'shop_name' => $data['shop_name'],
            'email' => $data['shop_email'],
            'phone' => $data['phone'] ?? '',
            'line1' => $data['line1'] ?? '',
            'line2' => $data['line2'] ?? '',
            'city' => $data['city'] ?? '',
            'region' => $data['region'] ?? '',
            'postal' => $data['postal'] ?? '',
            'country_code' => $data['country_code'] ?? '',
            'vat' => '',
            'gst' => '',
            'business_number' => $data['business_number'] ?? '',
            'footer' => $data['footer'] ?? [],
        ]);
        $this->shops->update($store->fresh(), [
            'name' => $data['shop_name'],
            'email' => $data['shop_email'],
            'theme' => $store->theme,
        ]);
        $this->shops->writeDefaultEnv($store->fresh());

        return back()->with('success', 'Store details saved.');
    }

    public function toggleMultishop(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        try {
            if ($data['enabled']) {
                $this->shops->enable();
            } else {
                $this->shops->disable();
            }
        } catch (HttpException $e) {
            throw ValidationException::withMessages(['enabled' => $e->getMessage()]);
        }

        return back()->with('success', $data['enabled'] ? 'Multishop is on.' : 'Multishop is off.');
    }

    public function toggleMultivendor(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        MarketplaceSettings::setEnabled((bool) $data['enabled']);

        return back()->with('success', $data['enabled'] ? 'Multi-vendor is on.' : 'Multi-vendor is off.');
    }

    public function toggleCommunity(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', Rule::in([
                'contact_form', 'product_video',
                'meilisearch', 'sms_otp', 'sms_webhook',
                'login_email_password', 'login_mobile_password', 'login_mobile_otp',
                'sms_driver', 'sms_twilio_sid', 'sms_twilio_from', 'sms_twilio_token',
                'otp_expiry_minutes', 'otp_max_attempts', 'otp_resend_seconds',
            ])],
            'enabled' => ['required_unless:key,sms_webhook,sms_driver,sms_twilio_sid,sms_twilio_from,sms_twilio_token,otp_expiry_minutes,otp_max_attempts,otp_resend_seconds', 'nullable', 'boolean'],
            'value' => ['nullable', 'string', 'max:500'],
        ]);
        if ($data['key'] === 'sms_webhook') {
            $url = trim((string) ($data['value'] ?? ''));
            if ($url !== '') {
                try {
                    \App\Domain\Security\SafeUrl::assertPublicHttp($url, true);
                } catch (\InvalidArgumentException) {
                    return back()->withErrors(['value' => 'SMS webhook must be a public https URL.']);
                }
            }
            ShopSetting::put('auth.sms_webhook', $url);

            return back()->with('success', 'SMS webhook saved.');
        }
        if ($data['key'] === 'sms_driver') {
            $driver = (string) ($data['value'] ?? '');
            if (! in_array($driver, ['log', 'webhook', 'twilio'], true)) {
                return back()->withErrors(['value' => 'Unknown SMS provider.']);
            }
            ShopSetting::put('auth.sms_driver', $driver);

            return back()->with('success', 'SMS provider saved.');
        }
        if (in_array($data['key'], ['sms_twilio_sid', 'sms_twilio_from'], true)) {
            $value = trim((string) ($data['value'] ?? ''));
            ShopSetting::put('auth.'.$data['key'], $value);

            return back()->with('success', 'Twilio sender saved.');
        }
        if ($data['key'] === 'sms_twilio_token') {
            // Write-only: a blank value keeps the stored token.
            $value = trim((string) ($data['value'] ?? ''));
            if ($value !== '') {
                ShopSetting::put('auth.sms_twilio_token', $value);
            }

            return back()->with('success', 'Twilio token saved.');
        }
        if (in_array($data['key'], ['otp_expiry_minutes', 'otp_max_attempts', 'otp_resend_seconds'], true)) {
            $limits = [
                'otp_expiry_minutes' => ['auth.otp_expiry_minutes', 2, 10],
                'otp_max_attempts' => ['auth.otp_max_attempts', 3, 10],
                'otp_resend_seconds' => ['auth.otp_resend_seconds', 0, 300],
            ];
            [$setting, $min, $max] = $limits[$data['key']];
            $value = (int) ($data['value'] ?? $min);
            ShopSetting::put($setting, min($max, max($min, $value)));

            return back()->with('success', 'One-time-code policy saved.');
        }
        $map = [
            'contact_form' => 'shop.contact_form',
            'product_video' => 'catalog.product_video',
            'meilisearch' => 'search.meilisearch',
            'sms_otp' => 'auth.sms_otp',
            'login_email_password' => 'auth.login_email_password',
            'login_mobile_password' => 'auth.login_mobile_password',
            'login_mobile_otp' => 'auth.login_mobile_otp',
        ];
        if (str_starts_with($data['key'], 'login_') && ! $data['enabled']) {
            $remaining = array_filter([
                'login_email_password' => $data['key'] === 'login_email_password' ? false : \App\Domain\Settings\CustomerSettings::loginEmailPassword(),
                'login_mobile_password' => $data['key'] === 'login_mobile_password' ? false : \App\Domain\Settings\CustomerSettings::loginMobilePassword(),
                'login_mobile_otp' => $data['key'] === 'login_mobile_otp' ? false : \App\Domain\Settings\CustomerSettings::loginMobileOtp(),
            ]);
            if ($remaining === []) {
                return back()->withErrors(['enabled' => 'Keep at least one sign-in method enabled.']);
            }
        }
        ShopSetting::put($map[$data['key']], (bool) $data['enabled']);

        return back()->with('success', 'Store feature saved.');
    }

    public function applyPurpose(Request $request, Operator $operator): RedirectResponse
    {
        $data = $request->validate([
            'purpose' => ['required', 'string', Rule::in(StorePurpose::OPTIONS)],
        ]);

        try {
            $operator->execute('settings.bulk', [
                'values' => StorePurpose::planned($data['purpose']),
            ], [
                'source' => 'admin',
                'reason' => 'Store purpose: '.$data['purpose'],
            ]);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Recommended settings for this store purpose were applied.');
    }
}
