<?php

use App\Domain\Catalog\Support\AttachmentUploadValidator;
use App\Http\Middleware\ApplyShopDebug;
use App\Http\Middleware\AssignApiRequestId;
use App\Http\Middleware\AuthenticateWebservice;
use App\Http\Middleware\BootPlugins;
use App\Http\Middleware\CachePublicStorefrontApi;
use App\Http\Middleware\CaptureProfiler;
use App\Http\Middleware\ConfigureSessionBySurface;
use App\Http\Middleware\EnsureAdminPermission;
use App\Http\Middleware\EnsureApprovedSeller;
use App\Http\Middleware\EnsureCustomerMfa;
use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureMarketplaceEnabled;
use App\Http\Middleware\BindAdminIntegrationAuth;
use App\Http\Middleware\BindCustomerBearer;
use App\Http\Middleware\LogAdminApiAudit;
use App\Http\Middleware\EnforceIntegrationTokenRateLimit;
use App\Http\Middleware\EnforceStorefrontApiKeyRateLimit;
use App\Http\Middleware\EnsureStorefrontApiStateful;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureUploadPostSize;
use App\Http\Middleware\ExposeApiRateLimitHeaders;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\MarkAdminListToast;
use App\Http\Middleware\ProtectAgainstSpamBots;
use App\Http\Middleware\ResolveStore;
use App\Http\Middleware\RewriteAdminPath;
use App\Http\Middleware\RewriteShopVirtualUri;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\ShopMaintenance;
use App\Http\Middleware\UseShopUrl;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\ValidatePostSize;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $trusted = trim((string) env('TRUSTED_PROXIES', ''));
        if ($trusted === '*') {
            $middleware->trustProxies(at: '*');
        } elseif ($trusted !== '') {
            $middleware->trustProxies(at: array_values(array_filter(array_map('trim', explode(',', $trusted)))));
        }

        $middleware->prepend(ConfigureSessionBySurface::class);
        $middleware->prepend(RewriteAdminPath::class);
        $middleware->prepend(RewriteShopVirtualUri::class);

        $middleware->statefulApi();
        // Runs outside Sanctum so missing Origin/Referer still gets a cookie session when SPA cookies exist.
        $middleware->api(prepend: [
            EnsureStorefrontApiStateful::class,
        ]);

        $middleware->remove(ValidatePostSize::class);

        // Shop scope must run before auth and route-model binding. Otherwise a
        // customer who belongs to another shop in a shared group is looked up
        // as the default shop: auth returns Unauthenticated and bindings 404.
        // AuthenticatesRequests is already ahead of SubstituteBindings, so one
        // insert covers both.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, ResolveStore::class);
        // Bearer bind must win over auth:customer when Laravel sorts AuthenticatesRequests.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, BindCustomerBearer::class);

        $middleware->web(append: [
            EnsureUploadPostSize::class,
            UseShopUrl::class,
            ResolveStore::class,
            ApplyShopDebug::class,
            SetLocale::class,
            EnsureInstalled::class,
            BootPlugins::class,
            ShopMaintenance::class,
            CaptureProfiler::class,
            MarkAdminListToast::class,
            HandleInertiaRequests::class,
            SecurityHeaders::class,
        ]);

        $middleware->api(prepend: [
            EnsureInstalled::class,
            ResolveStore::class,
            ApplyShopDebug::class,
            SetLocale::class,
            BootPlugins::class,
            ShopMaintenance::class,
            CaptureProfiler::class,
            // Before auth:customer (priority-sorted) so Bearer tokens bind.
            BindCustomerBearer::class,
        ]);
        $middleware->api(append: [
            AssignApiRequestId::class,
            CachePublicStorefrontApi::class,
            ExposeApiRateLimitHeaders::class,
            SecurityHeaders::class,
        ]);

        $middleware->alias([
            'installed' => EnsureInstalled::class,
            'permission' => EnsureAdminPermission::class,
            'super_admin' => EnsureSuperAdmin::class,
            'seller' => EnsureApprovedSeller::class,
            'marketplace' => EnsureMarketplaceEnabled::class,
            'webservice' => AuthenticateWebservice::class,
            'customer.mfa' => EnsureCustomerMfa::class,
            'honeypot' => ProtectAgainstSpamBots::class,
            'admin.integration' => BindAdminIntegrationAuth::class,
            'admin.integration.ratelimit' => EnforceIntegrationTokenRateLimit::class,
            'admin.api.audit' => LogAdminApiAudit::class,
            'storefront.key' => EnforceStorefrontApiKeyRateLimit::class,
            'customer.bearer' => \App\Http\Middleware\BindCustomerBearer::class,
        ]);

        $middleware->redirectGuestsTo(function (Request $request): string {
            if ($request->is('admin') || $request->is('admin/*')) {
                return route('admin.login');
            }

            return '/login';
        });

        $middleware->redirectUsersTo(function (Request $request): string {
            if ($request->is('admin') || $request->is('admin/*')) {
                return route('admin.dashboard');
            }

            return '/account';
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->is('admin/login')) {
                return redirect()->route('admin.login')->withErrors([
                    'email' => 'Too many sign-in tries. Wait a minute and try again.',
                ]);
            }
            if ($request->is('admin/forgot-password')) {
                return redirect()->route('admin.password.request')->withErrors([
                    'email' => 'Too many reset tries. Wait a minute and try again.',
                ]);
            }

            return null;
        });

        $exceptions->render(function (PostTooLargeException $e, Request $request) {
            $message = $request->is('admin/files', 'admin/files/*')
                ? AttachmentUploadValidator::tooLargeMessage()
                : 'The uploaded data is too large. Please try a smaller file.';

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $message,
                    'errors' => ['file' => [$message]],
                ], 413);
            }

            return redirect()
                ->back()
                ->withInput($request->except('file'))
                ->withErrors(['file' => $message]);
        });
    })->create();
