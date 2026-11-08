<?php

namespace Tests\Feature;

use App\Http\Middleware\MarkAdminListToast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class MarkAdminListToastTest extends TestCase
{
    public function test_list_toggle_redirect_sets_toast_flag(): void
    {
        $middleware = new MarkAdminListToast;
        $request = Request::create('/admin/products/1/toggle', 'POST');
        $request->setRouteResolver(function () use ($request) {
            $route = new Route(['POST'], 'admin/products/{product}/toggle', fn () => null);
            $route->name('admin.products.toggle');
            $route->bind($request);

            return $route;
        });
        $request->setLaravelSession($this->app['session']->driver());

        $response = $middleware->handle($request, function () {
            return redirect('/admin/products')->with('success', 'Product enabled.');
        });

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertTrue((bool) session('toast'));
        $this->assertSame('Product enabled.', session('success'));
    }

    public function test_form_update_redirect_does_not_set_toast_flag(): void
    {
        Session::flush();
        $middleware = new MarkAdminListToast;
        $request = Request::create('/admin/products/1', 'PUT');
        $request->setRouteResolver(function () use ($request) {
            $route = new Route(['PUT'], 'admin/products/{product}', fn () => null);
            $route->name('admin.products.update');
            $route->bind($request);

            return $route;
        });
        $request->setLaravelSession($this->app['session']->driver());

        $response = $middleware->handle($request, function () {
            return redirect('/admin/products')->with('success', 'Product updated.');
        });

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('Product updated.', session('success'));
        $this->assertFalse((bool) session('toast'));
    }

    public function test_form_store_redirect_does_not_set_toast_flag(): void
    {
        Session::flush();
        $middleware = new MarkAdminListToast;
        $request = Request::create('/admin/products', 'POST');
        $request->setRouteResolver(function () use ($request) {
            $route = new Route(['POST'], 'admin/products', fn () => null);
            $route->name('admin.products.store');
            $route->bind($request);

            return $route;
        });
        $request->setLaravelSession($this->app['session']->driver());

        $middleware->handle($request, function () {
            return redirect('/admin/products')->with('success', 'Product created.');
        });

        $this->assertSame('Product created.', session('success'));
        $this->assertFalse((bool) session('toast'));
    }

    public function test_bulk_status_redirect_sets_toast_flag(): void
    {
        Session::flush();
        $middleware = new MarkAdminListToast;
        $request = Request::create('/admin/products/bulk-status', 'POST');
        $request->setRouteResolver(function () use ($request) {
            $route = new Route(['POST'], 'admin/products/bulk-status', fn () => null);
            $route->name('admin.products.bulk-status');
            $route->bind($request);

            return $route;
        });
        $request->setLaravelSession($this->app['session']->driver());

        $middleware->handle($request, function () {
            return redirect('/admin/products')->with('success', 'Products enabled.');
        });

        $this->assertTrue((bool) session('toast'));
    }

    public function test_stock_alert_resend_redirect_sets_toast_flag(): void
    {
        Session::flush();
        $middleware = new MarkAdminListToast;
        $request = Request::create('/admin/stock-alerts/1/resend', 'POST');
        $request->setRouteResolver(function () use ($request) {
            $route = new Route(['POST'], 'admin/stock-alerts/{alert}/resend', fn () => null);
            $route->name('admin.stock-alerts.resend');
            $route->bind($request);

            return $route;
        });
        $request->setLaravelSession($this->app['session']->driver());

        $middleware->handle($request, function () {
            return redirect('/admin/stock-alerts')->with('success', 'Stock alert email resent.');
        });

        $this->assertTrue((bool) session('toast'));
        $this->assertSame('Stock alert email resent.', session('success'));
    }
}
