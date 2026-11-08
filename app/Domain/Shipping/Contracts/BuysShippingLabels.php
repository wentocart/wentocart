<?php

namespace App\Domain\Shipping\Contracts;

use App\Domain\Order\Models\Order;
use App\Domain\Shipping\Models\ShippingMethod;

interface BuysShippingLabels
{
    /**
     * @return array{carrier: string, tracking_number: string, tracking_url: ?string}
     */
    public function buyLabel(Order $order, ShippingMethod $method): array;
}
