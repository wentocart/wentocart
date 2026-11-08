<?php

namespace App\Domain\Shipping\Contracts;

use App\Domain\Order\Models\Order;
use App\Domain\Shipping\Models\ShippingMethod;

interface RefundsShippingLabels
{
    /**
     * Void or refund a previously bought shipping label.
     *
     * @param  array<string, mixed>  $labelData
     * @return array<string, mixed>
     */
    public function refundLabel(Order $order, ShippingMethod $method, array $labelData): array;
}
