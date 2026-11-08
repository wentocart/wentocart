<?php

namespace App\Domain\Plugin\Support;

use App\Domain\Payment\Contracts\PaymentGateway;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Payment\PaymentGatewayRegistry;
use App\Domain\Plugin\Capabilities;
use App\Domain\Plugin\PluginManifest;
use Illuminate\Validation\ValidationException;

class PluginPayments
{
    public function __construct(protected PluginManifest $manifest) {}

    public function register(PaymentGateway $gateway): void
    {
        if (! $this->manifest->can(Capabilities::PAYMENT_DRIVER)) {
            throw ValidationException::withMessages(['payment' => 'Declare payment.driver (certified) to register a gateway.']);
        }
        app(PaymentGatewayRegistry::class)->register($gateway);
    }

    /**
     * Ensure a payment_methods row exists for this driver so checkout can select it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function syncMethod(string $code, array $attributes = []): PaymentMethod
    {
        if (! $this->manifest->can(Capabilities::PAYMENT_DRIVER)) {
            throw ValidationException::withMessages(['payment' => 'Declare payment.driver to sync payment methods.']);
        }

        $driver = strtolower((string) ($attributes['driver'] ?? $code));

        return PaymentMethod::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => $attributes['name'] ?? ucfirst($code),
                'description' => $attributes['description'] ?? null,
                'driver' => $driver,
                'is_enabled' => (bool) ($attributes['is_enabled'] ?? true),
                'position' => (int) ($attributes['position'] ?? 50),
                'config' => array_merge(
                    is_array($attributes['config'] ?? null) ? $attributes['config'] : [],
                    ['extension' => $this->manifest->id()]
                ),
            ]
        );
    }
}
