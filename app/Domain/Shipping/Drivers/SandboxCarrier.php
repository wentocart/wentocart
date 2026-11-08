<?php

namespace App\Domain\Shipping\Drivers;

use App\Domain\Order\Models\Order;
use App\Domain\Shipping\Contracts\BuysShippingLabels;
use App\Domain\Shipping\Contracts\ShippingCarrier;
use App\Domain\Shipping\Models\ShippingMethod;
use Illuminate\Support\Str;

class SandboxCarrier implements ShippingCarrier, BuysShippingLabels
{
    public function driver(): string
    {
        return 'sandbox';
    }

    public function quote(ShippingMethod $method, float $subtotal, float $weight, array $context = []): ?float
    {
        $config = is_array($method->config) ? $method->config : [];
        $base = (float) ($config['base'] ?? $method->price ?? 8);
        $perKg = (float) ($config['per_kg'] ?? 2);
        $intl = (float) ($config['intl_surcharge'] ?? 12);
        $origin = strtoupper(trim((string) ($config['origin_country'] ?? 'US')));
        $country = strtoupper(trim((string) ($context['country_code'] ?? '')));
        $surcharge = ($country !== '' && $origin !== '' && $country !== $origin) ? $intl : 0.0;

        return round(max(0, $base + (max(0, $weight) * $perKg) + $surcharge), 2);
    }

    public function buyLabel(Order $order, ShippingMethod $method): array
    {
        $tracking = 'SBX'.strtoupper(Str::random(10));

        return [
            'carrier' => (string) ($method->name ?: 'Sandbox'),
            'tracking_number' => $tracking,
            'tracking_url' => url('/order/'.$order->number),
        ];
    }
}
