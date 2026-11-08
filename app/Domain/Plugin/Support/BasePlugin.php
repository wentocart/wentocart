<?php

namespace App\Domain\Plugin\Support;

use App\Domain\Plugin\Support\PluginContext;

abstract class BasePlugin
{
    public function __construct(protected PluginContext $ctx) {}

    public function context(): PluginContext
    {
        return $this->ctx;
    }

    public function boot(): void {}

    /**
     * Declared admin UI. Core renders this with the official kit.
     * Hosted module pages use page documents; apps do not ship Inertia React.
     *
     * @return array{title?: string, hint?: string, fields?: list<array<string, mixed>>}
     */
    public function adminSchema(): array
    {
        return [];
    }

    public function saveSettings(array $values): void
    {
        $this->settings()->fill($values);
    }

    protected function settings(): PluginSettings
    {
        return $this->ctx->settings();
    }

    protected function hooks(): PluginHooks
    {
        return $this->ctx->hooks();
    }

    protected function lists(): PluginLists
    {
        return $this->ctx->lists();
    }

    protected function slots(): PluginSlots
    {
        return $this->ctx->slots();
    }

    protected function menu(): PluginMenu
    {
        return $this->ctx->menu();
    }

    protected function pages(): PluginPages
    {
        return $this->ctx->pages();
    }

    protected function actions(): PluginActions
    {
        return $this->ctx->actions();
    }

    protected function query(): ModuleQuery
    {
        return $this->ctx->query();
    }

    protected function catalog(): ModuleCatalogWrite
    {
        return $this->ctx->catalog();
    }

    protected function customers(): ModuleCustomersWrite
    {
        return $this->ctx->customers();
    }

    protected function mail(): ModuleMail
    {
        return $this->ctx->mail();
    }

    protected function checkout(): ModuleCheckout
    {
        return $this->ctx->checkout();
    }

    protected function orders(): ModuleOrdersWrite
    {
        return $this->ctx->orders();
    }

    protected function pdf(): ModulePdf
    {
        return $this->ctx->pdf();
    }

    protected function auth(): ModuleAuth
    {
        return $this->ctx->auth();
    }

    protected function carts(): ModuleCarts
    {
        return $this->ctx->carts();
    }

    protected function wishlist(): ModuleWishlist
    {
        return $this->ctx->wishlist();
    }

    protected function schedule(): ModuleSchedule
    {
        return $this->ctx->schedule();
    }

    protected function payments(): PluginPayments
    {
        return $this->ctx->payments();
    }

    protected function shipping(): PluginShipping
    {
        return $this->ctx->shipping();
    }

    protected function http(): PluginHttp
    {
        return $this->ctx->http();
    }
}
