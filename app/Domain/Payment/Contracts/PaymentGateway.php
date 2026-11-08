<?php

namespace App\Domain\Payment\Contracts;

use App\Domain\Order\Models\Order;
use App\Domain\Payment\CheckoutLifecycle;
use App\Domain\Payment\PaymentCaptureResult;

interface PaymentGateway
{
    public function driver(): string;

    public function checkoutLifecycle(): CheckoutLifecycle;

    public function capture(Order $order, array $payload = []): PaymentCaptureResult;

    /**
     * True when capture() only touches local DB state (safe inside a checkout transaction).
     * External/network gateways must return false so CheckoutService captures after commit.
     */
    public function supportsTransactionalCapture(): bool;
}
