<?php

namespace App\Domain\Payment\Drivers;

use App\Domain\Order\Models\Order;
use App\Domain\Payment\CheckoutLifecycle;
use App\Domain\Payment\Contracts\PaymentGateway;
use App\Domain\Payment\Contracts\RefundsPayments;
use App\Domain\Payment\PaymentCaptureResult;

class OfflinePaymentGateway implements PaymentGateway, RefundsPayments
{
    public function driver(): string
    {
        return 'offline';
    }

    public function checkoutLifecycle(): CheckoutLifecycle
    {
        return CheckoutLifecycle::OrderFirst;
    }

    public function capture(Order $order, array $payload = []): PaymentCaptureResult
    {
        // CheckoutService owns payment_status (including partial deposits).
        if ($order->payment_status !== 'partial') {
            $order->updateTrusted(['payment_status' => 'pending']);
        }

        return PaymentCaptureResult::pending();
    }

    public function refund(Order $order, float $amount, array $payload = []): PaymentCaptureResult
    {
        return PaymentCaptureResult::paid(['offline' => true, 'amount' => $amount]);
    }

    public function supportsTransactionalCapture(): bool
    {
        return true;
    }
}
