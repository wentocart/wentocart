<?php

namespace App\Domain\Payment\Drivers;

use App\Domain\Order\Models\Order;
use App\Domain\Payment\CheckoutLifecycle;
use App\Domain\Payment\Contracts\PaymentGateway;
use App\Domain\Payment\Contracts\RefundsPayments;
use App\Domain\Payment\PaymentCaptureResult;
use App\Domain\Payment\PaymentSettings;

class UpiGateway implements PaymentGateway, RefundsPayments
{
    public function driver(): string
    {
        return 'upi';
    }

    public function checkoutLifecycle(): CheckoutLifecycle
    {
        return CheckoutLifecycle::OrderFirst;
    }

    public function capture(Order $order, array $payload = []): PaymentCaptureResult
    {
        $config = is_array($order->paymentMethod?->config) ? $order->paymentMethod->config : [];
        $vpa = trim((string) ($config['vpa'] ?? ''));
        if ($vpa === '') {
            return PaymentSettings::sandboxCapture(
                'upi',
                $order,
                'Set a UPI VPA on the payment method, or enable sandbox pay.',
            );
        }
        return PaymentCaptureResult::redirect(PaymentSettings::sandboxPayUrl('upi', $order), [
            'sandbox' => true,
            'vpa' => $vpa,
        ]);
    }

    public function refund(Order $order, float $amount, array $payload = []): PaymentCaptureResult
    {
        return PaymentCaptureResult::paid(['upi' => 'local', 'amount' => $amount]);
    }

    public function supportsTransactionalCapture(): bool
    {
        return false;
    }
}
