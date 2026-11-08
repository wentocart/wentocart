<?php

namespace App\Providers;

use App\Domain\Ai\AiGateway;
use App\Domain\Customer\Models\CustomerAddress;
use App\Domain\Customer\ShopSessionGuard;
use App\Domain\Installer\ApacheHtaccess;
use App\Domain\Ops\AbilityRegistry;
use App\Domain\Ops\EventBus;
use App\Domain\Ops\Operator;
use App\Domain\Ops\StaffChangeJournal;
use App\Domain\Order\Models\Order;
use App\Domain\Payment\PaymentGatewayRegistry;
use App\Domain\Promo\DiscountEngine;
use App\Domain\Seo\SeoFixJournal;
use App\Domain\Settings\MailRuntime;
use App\Domain\Settings\ObjectStorageSettings;
use App\Domain\Settings\RequestProfiler;
use App\Domain\Shipping\ShippingCarrierRegistry;
use App\Domain\Store\StoreContext;
use App\Domain\Theme\ThemeInstaller;
use App\Policies\CustomerAddressPolicy;
use App\Policies\OrderPolicy;
use App\Support\AdminPagination;
use App\Support\WebBase;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(StoreContext::class);
        // One engine per request so catalogRules()/cartRules() are loaded once, not per product.
        $this->app->scoped(DiscountEngine::class);
        $this->app->singleton(RequestProfiler::class);
        $this->app->singleton(PaymentGatewayRegistry::class);
        $this->app->singleton(ShippingCarrierRegistry::class);
        $this->app->singleton(AiGateway::class);
        $this->app->singleton(AbilityRegistry::class);
        $this->app->singleton(EventBus::class);
        $this->app->singleton(Operator::class);
        $this->app->singleton(SeoFixJournal::class);
        $this->app->singleton(StaffChangeJournal::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Auth::extend('shop-session', function ($app, $name, array $config) {
            $guard = new ShopSessionGuard(
                $name,
                Auth::createUserProvider($config['provider'] ?? null),
                $app['session.store'],
                $app['request'],
            );
            $guard->setCookieJar($app['cookie']);
            $guard->setDispatcher($app['events']);
            $app->refresh('request', $guard, 'setRequest');

            return $guard;
        });

        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(CustomerAddress::class, CustomerAddressPolicy::class);

        $adminPaginate = function (?int $default = 10) {
            /** @var EloquentBuilder|QueryBuilder $this */
            $perPage = AdminPagination::perPage(request(), $default ?? 10);

            return $this->paginate($perPage)->withQueryString();
        };
        EloquentBuilder::macro('adminPaginate', $adminPaginate);
        QueryBuilder::macro('adminPaginate', $adminPaginate);

        // Catch N+1 and silent mass-assignment bugs in local/staging (not during PHPUnit).
        if (! $this->app->runningUnitTests()) {
            Model::preventLazyLoading(! $this->app->isProduction());
            Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
        }
        // Same-origin relative paths. Host-root installs stay `/build/...`.
        // Folder installs (a folder install) must prefix `/shop/build/...`.
        Vite::createAssetPathsUsing(function (string $path, ?bool $secure = null) {
            return WebBase::url($path);
        });
        $appUrl = rtrim((string) config('app.url'), '/');
        $appPath = parse_url($appUrl, PHP_URL_PATH);
        if (is_string($appPath) && $appPath !== '' && $appPath !== '/') {
            URL::forceRootUrl($appUrl);
        }
        // modulepreload of a large hashed file failing should not blank the admin.
        Vite::usePreloadTagAttributes(false);
        if ($this->app->environment('production')) {
            config(['app.debug' => false]);
        }
        // Only force HTTPS when this request is already HTTPS. An installer
        // APP_URL of https://… must not rewrite http://127.0.0.1:8099 links.
        if ($this->app->runningInConsole()) {
            if (str_starts_with((string) config('app.url'), 'https://')) {
                URL::forceScheme('https');
            }
        } elseif (request()->isSecure()) {
            URL::forceScheme('https');
        }
        $this->app->terminating(function () {
            if ($this->app->bound(StaffChangeJournal::class)) {
                $this->app->make(StaffChangeJournal::class)->flush();
            }
        });
        if (! $this->app->runningUnitTests() && config('wento.installed')) {
            try {
                MailRuntime::apply();
                ObjectStorageSettings::bootDisk();
            } catch (\Throwable) {
                // Settings table may be missing during migrate / broken installs.
            }
        }
        // public/themes is gitignored. After git pull, copy any new Classic files
        // (footer icons, CSS) so the other machine serves the same assets.
        if (! $this->app->runningUnitTests()) {
            try {
                $this->app->make(ThemeInstaller::class)->publishBundled(false);
            } catch (\Throwable) {
                // public/ may be read-only during package:discover or a broken checkout.
            }
            try {
                \App\Domain\Installer\PublicStorageLink::ensure();
            } catch (\Throwable) {
                // Uploads still load through /media when the link cannot be created.
            }
        }
        // Committed .htaccess uses RewriteBase /. Folder installs (XAMPP /www/…)
        // need APP_URL's path or Apache sends /admin to the XAMPP dashboard.
        if (! $this->app->runningUnitTests() && config('wento.installed')) {
            try {
                ApacheHtaccess::ensure(base_path(), (string) config('app.url'));
            } catch (\Throwable) {
                // .htaccess may be read-only during package:discover or a broken checkout.
            }
        }
    }
}
