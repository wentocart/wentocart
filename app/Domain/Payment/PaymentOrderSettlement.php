<?php

namespace App\Domain\Payment;

use App\Domain\Checkout\Services\CheckoutService;
use App\Domain\Order\Models\Order;

/**
 * Payment-driver entry point for marking orders paid with inventory/financial side effects.
 */
class PaymentOrderSettlement
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public static function markPaid(Order $order, array $meta = []): Order
    {
        return app(CheckoutService::class)->markLocalPaid($order, $meta);
    }
}
