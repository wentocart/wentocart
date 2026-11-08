<?php

namespace App\Domain\Shipping;

use App\Domain\Shipping\Contracts\ShippingCarrier;
use App\Domain\Shipping\Drivers\SandboxCarrier;
use App\Domain\Shipping\Models\ShippingMethod;
use InvalidArgumentException;

class ShippingCarrierRegistry
{
    /** @var array<string, ShippingCarrier> */
    protected array $drivers = [];

    public function __construct()
    {
        $this->bootCore();
    }

    protected function bootCore(): void
    {
        $this->register(new SandboxCarrier);
    }

    public function register(ShippingCarrier $carrier): void
    {
        $id = strtolower(trim($carrier->driver()));
        if ($id === '') {
            throw new InvalidArgumentException('Shipping driver id is required.');
        }
        $this->drivers[$id] = $carrier;
    }

    public function get(string $driver): ?ShippingCarrier
    {
        $driver = strtolower(trim($driver));

        return $this->drivers[$driver] ?? null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function cacheKey(string $driver, ShippingMethod $method, float $subtotal, float $weight, array $context = []): string
    {
        $country = $context['country_code']
            ?? ($context['shipping_country']
            ?? ($context['shipping_address']['country_code']
            ?? ($context['shipping_address']['country'] ?? '')));

        $zip = $context['postal_code']
            ?? ($context['zip']
            ?? ($context['shipping_address']['postal_code']
            ?? ($context['shipping_address']['zip'] ?? '')));

        $state = $context['state']
            ?? ($context['shipping_state']
            ?? ($context['shipping_address']['state'] ?? ''));

        $city = $context['city']
            ?? ($context['shipping_city']
            ?? ($context['shipping_address']['city'] ?? ''));

        return 'carrier_quote_'.md5(json_encode([
            'driver' => strtolower(trim($driver)),
            'method_id' => $method->id,
            'subtotal' => round($subtotal, 2),
            'weight' => round($weight, 3),
            'country' => strtoupper(trim((string) $country)),
            'state' => strtoupper(trim((string) $state)),
            'city' => strtolower(trim((string) $city)),
            'zip' => trim((string) $zip),
            'currency' => strtoupper(trim((string) ($context['currency_code'] ?? (\App\Domain\Localization\Money::currentCode())))),
            'customer_id' => $context['customer_id'] ?? null,
        ]));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function getCachedQuote(string $driver, ShippingMethod $method, float $subtotal, float $weight, array $context = []): ?float
    {
        if (! function_exists('cache')) {
            return null;
        }

        try {
            $key = $this->cacheKey($driver, $method, $subtotal, $weight, $context);
            $cached = cache()->get($key);
            if ($cached !== null && is_numeric($cached)) {
                return (float) $cached;
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /**
     * Check if a carrier driver relies on external 3rd-party remote network APIs.
     */
    public function isAsyncDriver(string $driver): bool
    {
        $d = strtolower(trim($driver));

        return $d !== '' && $d !== 'offline' && $d !== 'sandbox';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function quote(string $driver, ShippingMethod $method, float $subtotal, float $weight, array $context = []): ?float
    {
        $carrier = $this->get($driver);
        if (! $carrier) {
            return null;
        }

        $cached = $this->getCachedQuote($driver, $method, $subtotal, $weight, $context);
        if ($cached !== null) {
            return $cached;
        }

        $cacheKey = $this->cacheKey($driver, $method, $subtotal, $weight, $context);

        try {
            $quote = $carrier->quote($method, $subtotal, $weight, $context);
            if ($quote !== null && function_exists('cache')) {
                try {
                    cache()->put($cacheKey, (float) $quote, 120);
                } catch (\Throwable) {
                }
            }

            return $quote;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Shipping carrier driver [{$driver}] quote failed: ".$e->getMessage());

            return null;
        }
    }

    public function has(string $driver): bool
    {
        return $this->get($driver) !== null;
    }

    public function flush(): void
    {
        $this->drivers = [];
        $this->bootCore();
    }
}
