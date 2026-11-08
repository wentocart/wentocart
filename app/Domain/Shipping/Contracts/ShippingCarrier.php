<?php

namespace App\Domain\Shipping\Contracts;

use App\Domain\Shipping\Models\ShippingMethod;

interface ShippingCarrier
{
    public function driver(): string;

    /**
     * @param  array<string, mixed>  $context
     */
    public function quote(ShippingMethod $method, float $subtotal, float $weight, array $context = []): ?float;
}
