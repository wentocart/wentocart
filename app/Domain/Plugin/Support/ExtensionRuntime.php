<?php

namespace App\Domain\Plugin\Support;

use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Plugin\Models\InstalledPlugin;
use App\Domain\Plugin\PluginKernel;
use App\Domain\Shipping\Models\ShippingMethod;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ExtensionRuntime
{
    public static function isActive(?string $extensionId): bool
    {
        if (! is_string($extensionId) || $extensionId === '') {
            return true;
        }

        try {
            return app(PluginKernel::class)->extensionActive($extensionId);
        } catch (Throwable) {
            return false;
        }
    }

    public static function syncMethods(InstalledPlugin $row, bool $enabled): void
    {
        try {
            $key = $row->idKey();
            if (Schema::hasTable('shipping_methods')) {
                ShippingMethod::query()->where('config->extension', $key)->update(['is_enabled' => $enabled]);
            }
            if (Schema::hasTable('payment_methods')) {
                PaymentMethod::query()->where('config->extension', $key)->update(['is_enabled' => $enabled]);
            }
        } catch (Throwable) {
        }
    }

    public static function forgetMethods(InstalledPlugin $row): void
    {
        try {
            $key = $row->idKey();
            if (Schema::hasTable('shipping_methods')) {
                ShippingMethod::query()->where('config->extension', $key)->delete();
            }
            if (Schema::hasTable('payment_methods')) {
                PaymentMethod::query()->where('config->extension', $key)->delete();
            }
        } catch (Throwable) {
        }
    }
}
