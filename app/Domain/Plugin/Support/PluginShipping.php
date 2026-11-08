<?php

namespace App\Domain\Plugin\Support;

use App\Domain\Plugin\Capabilities;
use App\Domain\Plugin\PluginManifest;
use App\Domain\Shipping\Contracts\ShippingCarrier;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Domain\Shipping\ShippingCarrierRegistry;
use Illuminate\Validation\ValidationException;

class PluginShipping
{
    public function __construct(protected PluginManifest $manifest) {}

    public function register(ShippingCarrier $carrier): void
    {
        if (! $this->manifest->can(Capabilities::SHIPPING_DRIVER)) {
            throw ValidationException::withMessages(['shipping' => 'Declare shipping.driver (certified) to register a carrier.']);
        }
        app(ShippingCarrierRegistry::class)->register($carrier);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function syncMethod(string $code, array $attributes = []): ShippingMethod
    {
        if (! $this->manifest->can(Capabilities::SHIPPING_DRIVER)) {
            throw ValidationException::withMessages(['shipping' => 'Declare shipping.driver to sync shipping methods.']);
        }

        $driver = strtolower((string) ($attributes['driver'] ?? $code));
        $rawName = trim((string) ($attributes['name'] ?? ucfirst($code)));
        $name = mb_substr($rawName !== '' ? $rawName : ucfirst($code), 0, 255);
        $description = isset($attributes['description']) && $attributes['description'] !== null
            ? mb_substr((string) $attributes['description'], 0, 65000)
            : null;
        $price = min(99999999.99, max(0.0, (float) ($attributes['price'] ?? 0)));

        return ShippingMethod::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => $name,
                'description' => $description,
                'type' => $attributes['type'] ?? 'carrier',
                'driver' => $driver,
                'price' => $price,
                'is_enabled' => (bool) ($attributes['is_enabled'] ?? true),
                'position' => (int) ($attributes['position'] ?? 50),
                'conditions' => ShippingMethod::emptyConditions(),
                'rate_config' => ['tiers' => []],
                'config' => array_merge(
                    is_array($attributes['config'] ?? null) ? $attributes['config'] : [],
                    ['extension' => $this->manifest->id()]
                ),
            ]
        );
    }
}
