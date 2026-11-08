<?php

namespace App\Domain\Plugin\Support;

use App\Domain\Plugin\PluginManifest;

class PluginContext
{
    public function __construct(protected PluginManifest $manifest) {}

    public function id(): string
    {
        return $this->manifest->id();
    }

    public function manifest(): PluginManifest
    {
        return $this->manifest;
    }

    public function settings(): PluginSettings
    {
        return new PluginSettings($this->manifest);
    }

    public function hooks(): PluginHooks
    {
        return new PluginHooks($this->manifest);
    }

    public function lists(): PluginLists
    {
        return new PluginLists($this->manifest);
    }

    public function slots(): PluginSlots
    {
        return new PluginSlots($this->manifest);
    }

    public function menu(): PluginMenu
    {
        return new PluginMenu($this->manifest);
    }

    public function pages(): PluginPages
    {
        return new PluginPages($this->manifest);
    }

    public function actions(): PluginActions
    {
        return new PluginActions($this->manifest);
    }

    public function query(): ModuleQuery
    {
        return new ModuleQuery($this->manifest);
    }

    public function catalog(): ModuleCatalogWrite
    {
        return new ModuleCatalogWrite($this->manifest);
    }

    public function customers(): ModuleCustomersWrite
    {
        return new ModuleCustomersWrite($this->manifest);
    }

    public function mail(): ModuleMail
    {
        return new ModuleMail($this->manifest);
    }

    public function checkout(): ModuleCheckout
    {
        return new ModuleCheckout($this->manifest);
    }

    public function orders(): ModuleOrdersWrite
    {
        return new ModuleOrdersWrite($this->manifest);
    }

    public function pdf(): ModulePdf
    {
        return new ModulePdf($this->manifest);
    }

    public function auth(): ModuleAuth
    {
        return new ModuleAuth($this->manifest);
    }

    public function carts(): ModuleCarts
    {
        return new ModuleCarts($this->manifest);
    }

    public function wishlist(): ModuleWishlist
    {
        return new ModuleWishlist($this->manifest);
    }

    public function schedule(): ModuleSchedule
    {
        return new ModuleSchedule($this->manifest);
    }

    public function payments(): PluginPayments
    {
        return new PluginPayments($this->manifest);
    }

    public function shipping(): PluginShipping
    {
        return new PluginShipping($this->manifest);
    }

    public function http(): PluginHttp
    {
        return new PluginHttp($this->manifest);
    }
}

class PluginLists
{
    public function __construct(protected PluginManifest $manifest) {}

    public function extend(string $list, callable $callback): void
    {
        ModuleBuses::extendList($this->manifest, $list, $callback);
    }
}

class PluginSlots
{
    public function __construct(protected PluginManifest $manifest) {}

    public function on(string $area, callable $callback): void
    {
        ModuleBuses::slot($this->manifest, $area, $callback);
    }
}

class PluginMenu
{
    public function __construct(protected PluginManifest $manifest) {}

    /**
     * @param  array<string, mixed>  $group
     */
    public function group(array $group): void
    {
        ModuleBuses::menu($this->manifest, $group);
    }
}

class PluginPages
{
    public function __construct(protected PluginManifest $manifest) {}

    public function admin(string $page, string $class): void
    {
        ModuleBuses::adminPage($this->manifest, $page, $class);
    }

    public function front(string $page, string $class, bool $auth = true): void
    {
        ModuleBuses::frontPage($this->manifest, $page, $class, $auth);
    }
}

class PluginActions
{
    public function __construct(protected PluginManifest $manifest) {}

    public function before(string $action, callable $callback): void
    {
        ModuleBuses::before($this->manifest, $action, $callback);
    }

    public function after(string $action, callable $callback): void
    {
        ModuleBuses::after($this->manifest, $action, $callback);
    }
}
