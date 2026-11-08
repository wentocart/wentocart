<?php

namespace App\Http\Middleware;

use App\Domain\Ai\AiSettings;
use App\Domain\Localization\Models\Language;
use App\Domain\Notify\AdminInbox;
use App\Domain\Ops\JumpShortcut;
use App\Domain\Security\AdminPath;
use App\Domain\Localization\Money;
use App\Domain\Localization\UiI18n;
use App\Domain\Plugin\Models\InstalledPlugin;
use App\Domain\Plugin\Support\ModuleBuses;
use App\Domain\Seo\SeoFixLive;
use App\Domain\Settings\Models\Setting;
use App\Domain\Settings\ShopSetting;
use App\Domain\Store\StoreContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Middleware;
use Throwable;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'admin';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * The request was rewritten to /admin before this runs. Tell the browser the public address,
     * or it replaces the URL with /admin and the next load 404s.
     */
    public function urlResolver()
    {
        return function (Request $request) {
            $url = Str::start(Str::after($request->fullUrl(), $request->getSchemeAndHttpHost()), '/');

            return AdminPath::toPublicReference($url);
        };
    }

    public function share(Request $request): array
    {
        $admin = $request->user('admin');
        $admin?->loadMissing(['roles', 'team', 'profile']);

        $currency = config('wento.default_currency', 'USD');
        try {
            if (config('wento.installed')) {
                $currency = Money::defaultCode();
            }
        } catch (Throwable) {
            // DB may be unavailable during install.
        }

        return [
            ...parent::share($request),
            'shop' => $this->shopShare($currency),
            'app' => [
                'name' => (string) config('wento.name', config('app.name')),
            ],
            'i18n' => $this->i18nShare($admin !== null),
            'auth' => [
                'admin' => $admin ? [
                    'id' => $admin->id,
                    'name' => $admin->name,
                    'email' => $admin->email,
                    'permissions' => $admin->permissionSlugs(),
                    'is_super' => $admin->isSuperAdmin(),
                    'job_title' => $admin->job_title,
                    'team' => $admin->team?->name,
                    'profile' => $admin->profile?->name,
                    // 'avatar_url' => $admin->avatarUrl(),
                    'has_avatar' => (bool) $admin->avatar_path,
                ] : null,
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'warning' => $request->session()->get('warning'),
                'info' => $request->session()->get('info'),
                'toast' => (bool) $request->session()->get('toast'),
                'admin_path_next' => $request->session()->get('admin_path_next'),
                'customer_id' => $request->session()->get('customer_id'),
                'import_batch_id' => $request->session()->get('import_batch_id'),
            ],
            'tools' => $this->toolsShare(),
            'ai' => $this->aiShare(),
            'appNav' => $this->appNavShare(),
            'moduleNav' => $this->moduleNavShare(),
            'fixRun' => $this->fixRunShare(),
            'notifications' => [
                'unread' => $admin ? AdminInbox::unreadCount($admin) : 0,
            ],
            'jump' => JumpShortcut::resolve($admin),
        ];
    }

    /**
     * @return array{content_enabled: bool, configured: bool}
     */
    protected function aiShare(): array
    {
        try {
            return AiSettings::forInertia();
        } catch (Throwable) {
            return ['content_enabled' => false, 'configured' => false];
        }
    }

    protected function shopShare(string $currency): array
    {
        try {
            return app(StoreContext::class)->inertiaShop($currency);
        } catch (Throwable) {
            return [
                'name' => $this->shopName(),
                'currency' => $currency,
                'multishop' => false,
                'multivendor' => false,
                'stores' => [],
                'view_all' => false,
                'url' => rtrim((string) config('app.url'), '/').'/',
            ];
        }
    }

    protected function shopName(): string
    {
        $fallback = (string) config('app.name');
        if (! config('wento.installed')) {
            return $fallback;
        }

        try {
            return (string) (Setting::getValue('shop_name', $fallback) ?: $fallback);
        } catch (Throwable) {
            return $fallback;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function fixRunShare(): ?array
    {
        try {
            return app(SeoFixLive::class)->chrome();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{profiler: bool, maintenance: bool}
     */
    protected function toolsShare(): array
    {
        try {
            return [
                'profiler' => ShopSetting::bool('advanced.profiler', false),
                'maintenance' => ShopSetting::bool('advanced.maintenance', false),
            ];
        } catch (Throwable) {
            return ['profiler' => false, 'maintenance' => false];
        }
    }

    /**
     * Enabled Safe apps that declared admin.page appear under Extensions.
     *
     * @return list<array{href: string, label: string, permission: string}>
     */
    protected function appNavShare(): array
    {
        try {
            $registry = app(\App\Domain\Plugin\PluginRegistry::class);

            return InstalledPlugin::query()
                ->where('is_enabled', true)
                ->orderBy('display_name')
                ->get()
                ->filter(fn (InstalledPlugin $row) => in_array('admin.page', $row->capabilities ?? [], true))
                ->map(function (InstalledPlugin $row) use ($registry) {
                    $manifest = $registry->find($row->vendor, $row->name);
                    $category = $manifest?->category;
                    if (! $category) {
                        $capabilities = $row->capabilities ?? [];
                        if (in_array('shipping.driver', $capabilities, true)) {
                            $category = 'shipping';
                        } elseif (in_array('payment.driver', $capabilities, true)) {
                            $category = 'payment';
                        }
                    }

                    return [
                        'href' => '/admin/apps/'.$row->vendor.'/'.$row->name,
                        'label' => $row->display_name,
                        'permission' => 'apps.manage',
                        'category' => $category,
                    ];
                })
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function moduleNavShare(): array
    {
        try {
            return ModuleBuses::menus();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array{locale: string, current: string, default: string, default_name: string, languages: list<array{code: string, name: string, is_default?: bool}>, messages: mixed}
     */
    protected function i18nShare(bool $authenticated): array
    {
        $empty = [
            'locale' => (string) config('wento.default_language', 'en'),
            'current' => (string) config('wento.default_language', 'en'),
            'default' => (string) config('wento.default_language', 'en'),
            'default_name' => 'English',
            'languages' => [],
            'messages' => [],
        ];

        if (! config('wento.installed')) {
            return $empty;
        }

        try {
            $current = Language::currentCode();
            $default = Language::query()->where('code', Language::defaultCode())->first();
            $languages = Language::enabledList()->map(fn (Language $language) => [
                'code' => $language->code,
                'name' => $language->name,
                'is_default' => $language->is_default,
            ])->values()->all();

            // Guests (login): English fallbacks in t() — skip ~270KB when locale is en.
            // Non-English guests need the pack so the login language switcher actually translates.
            // Authenticated: defer+once keyed by locale so switching language re-fetches messages.
            $messages = match (true) {
                $authenticated => Inertia::defer(fn () => UiI18n::messages($current, 'admin'))
                    ->once(true, 'admin-i18n-'.$current),
                $current === 'en' => [],
                default => UiI18n::messages($current, 'admin'),
            };

            return [
                'locale' => $current,
                'current' => $current,
                'default' => $default?->code ?? Language::defaultCode(),
                'default_name' => $default?->name ?? 'English',
                'languages' => $languages,
                'messages' => $messages,
            ];
        } catch (Throwable) {
            return $empty;
        }
    }
}
