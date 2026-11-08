<?php

namespace App\Domain\Plugin;

use App\Domain\Plugin\Models\InstalledPlugin;
use App\Domain\Plugin\Support\BasePlugin;
use App\Domain\Plugin\Support\ModuleBuses;
use App\Domain\Plugin\Support\PluginContext;
use App\Domain\Store\Models\PluginStore;
use App\Domain\Store\StoreContext;
use App\Support\Hooks\HookManager;
use Illuminate\Support\Facades\Schema;
use Throwable;

class PluginKernel
{
    protected static bool $booted = false;

    protected static bool $autoload = false;

    public function __construct(
        protected PluginRegistry $registry,
    ) {}

    public function boot(): void
    {
        if (self::$booted) {
            return;
        }
        if (! Schema::hasTable('plugins')) {
            return;
        }
        self::$booted = true;
        try {
            app(ExtensionDefaults::class)->rehomeLegacy();
        } catch (Throwable) {
        }
        $this->registerAutoload();

        foreach (InstalledPlugin::query()->where('is_enabled', true)->get() as $row) {
            if (! $this->enabledForCurrentShop($row)) {
                continue;
            }
            $this->bootOne($row);
        }
    }

    public static function reset(): void
    {
        self::$booted = false;
        HookManager::flush();
        ModuleBuses::flush();
        if (app()->bound(\App\Domain\Payment\PaymentGatewayRegistry::class)) {
            app(\App\Domain\Payment\PaymentGatewayRegistry::class)->flush();
        }
        if (app()->bound(\App\Domain\Shipping\ShippingCarrierRegistry::class)) {
            app(\App\Domain\Shipping\ShippingCarrierRegistry::class)->flush();
        }
    }

    protected function bootOne(InstalledPlugin $row): void
    {
        $manifest = $this->registry->find($row->vendor, $row->name);
        if (! $manifest) {
            $this->markHealth($row, 'error', 'Extension files are missing.');

            return;
        }
        $class = $manifest->className();
        if (! class_exists($class)) {
            $this->markHealth($row, 'error', 'Extension class '.$class.' was not found.');

            return;
        }
        try {
            $plugin = new $class(new PluginContext($manifest));
            if (! $plugin instanceof BasePlugin) {
                $this->markHealth($row, 'error', 'Extension must extend BasePlugin.');

                return;
            }
            $plugin->boot();
            $this->markHealth($row, 'ok', null);
        } catch (Throwable $e) {
            // One broken extension must not take the rest of the shop down.
            $this->markHealth($row, 'error', $e->getMessage());
        }
    }

    protected function markHealth(InstalledPlugin $row, string $status, ?string $error): void
    {
        try {
            if (! Schema::hasColumn('plugins', 'health_status')) {
                return;
            }
            $row->forceFill([
                'health_status' => $status,
                'last_error' => $error,
            ])->save();
        } catch (Throwable) {
            // Health columns are best-effort diagnostics.
        }
    }

    public function extensionActive(string $extensionId): bool
    {
        if (! str_contains($extensionId, '/')) {
            return false;
        }
        [$vendor, $name] = array_pad(explode('/', strtolower(trim($extensionId)), 2), 2, '');
        if ($vendor === '' || $name === '') {
            return false;
        }
        try {
            $row = InstalledPlugin::query()->where('vendor', $vendor)->where('name', $name)->first();
            if (! $row || ! $row->is_enabled) {
                return false;
            }

            return $this->enabledForCurrentShop($row);
        } catch (Throwable) {
            return false;
        }
    }

    public function instance(string $vendor, string $name): ?BasePlugin
    {
        $manifest = $this->registry->find($vendor, $name);
        if (! $manifest || ! class_exists($manifest->className())) {
            return null;
        }
        $plugin = new ($manifest->className())(new PluginContext($manifest));

        return $plugin instanceof BasePlugin ? $plugin : null;
    }

    protected function enabledForCurrentShop(InstalledPlugin $row): bool
    {
        try {
            $storeId = app(StoreContext::class)->id();
            if (! $storeId || ! class_exists(PluginStore::class)) {
                return true;
            }
            $pivot = PluginStore::query()->where('store_id', $storeId)->where('plugin', $row->idKey())->first();

            return $pivot ? (bool) $pivot->is_enabled : true;
        } catch (Throwable) {
            return true;
        }
    }

    protected function registerAutoload(): void
    {
        if (self::$autoload) {
            return;
        }
        self::$autoload = true;
        spl_autoload_register(function (string $class): void {
            if (! str_starts_with($class, 'Modules\\')) {
                return;
            }
            $parts = explode('\\', $class);
            if (count($parts) < 4) {
                return;
            }
            [, $vendor, $name] = $parts;
            $vendorSlug = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $vendor) ?? $vendor);
            $nameSlug = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $name) ?? $name);
            $relative = implode('/', array_slice($parts, 3)).'.php';
            $path = base_path('extensions/'.$vendorSlug.'/'.$nameSlug.'/src/'.$relative);
            if (is_file($path)) {
                require $path;

                return;
            }
            // Legacy runtime path (pre unified extensions home).
            $legacy = storage_path('app/plugins/'.$vendorSlug.'/'.$nameSlug.'/src/'.$relative);
            if (is_file($legacy)) {
                require $legacy;
            }
        });
    }
}
