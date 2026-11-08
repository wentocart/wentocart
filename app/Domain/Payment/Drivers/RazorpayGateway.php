<?php

namespace App\Domain\Payment\Drivers;

use App\Domain\Order\Models\Order;
use App\Domain\Payment\CheckoutLifecycle;
use App\Domain\Payment\Contracts\PaymentGateway;
use App\Domain\Payment\Contracts\RefundsPayments;
use App\Domain\Payment\PaymentCaptureResult;
use App\Domain\Payment\PaymentSettings;
use App\Domain\Security\SafeUrl;
use Illuminate\Support\Facades\Http;

class RazorpayGateway implements PaymentGateway, RefundsPayments
{
    public function driver(): string
    {
        return 'razorpay';
    }

    public function checkoutLifecycle(): CheckoutLifecycle
    {
        return CheckoutLifecycle::OrderFirst;
    }

    public function capture(Order $order, array $payload = []): PaymentCaptureResult
    {
        $config = is_array($order->paymentMethod?->config) ? $order->paymentMethod->config : [];
        $key = PaymentSettings::razorpayKey($config);
        $secret = PaymentSettings::razorpaySecret($config);
        if ($key === '' || $secret === '') {
            return PaymentSettings::sandboxCapture(
                'razorpay',
                $order,
                'Razorpay keys are missing. Paste Key ID and Secret on the payment method.',
            );
        }

        $paise = (int) round(((float) $order->grand_total) * 100);
        try {
            SafeUrl::assertPublicHttp('https://api.razorpay.com/v1/payment_links');
            $response = Http::timeout(30)->withBasicAuth($key, $secret)->acceptJson()
                ->post('https://api.razorpay.com/v1/payment_links', [
                    'amount' => $paise,
                    'currency' => strtoupper((string) ($order->currency_code ?: 'INR')),
                    'description' => 'Order '.$order->number,
                    'customer' => ['email' => $order->email],
                    'callback_url' => url('/order/'.$order->number),
                    'callback_method' => 'get',
                    'notes' => ['order_number' => $order->number],
                ]);
        } catch (\Throwable $e) {
            return PaymentCaptureResult::failed('Could not reach Razorpay.', ['error' => $e->getMessage()]);
        }
        $json = $response->json() ?: [];
        if ($response->failed() || empty($json['short_url'])) {
            return PaymentCaptureResult::failed((string) ($json['error']['description'] ?? 'Could not start Razorpay.'), ['razorpay' => $json]);
        }

        return PaymentCaptureResult::redirect((string) $json['short_url'], ['payment_link_id' => $json['id'] ?? null]);
    }

    public function refund(Order $order, float $amount, array $payload = []): PaymentCaptureResult
    {
        $paymentId = (string) data_get($order->payment_details, 'gateway.payment_id', '');
        if ($paymentId === '') {
            return PaymentCaptureResult::paid(['razorpay' => 'local', 'amount' => $amount]);
        }
        $config = is_array($order->paymentMethod?->config) ? $order->paymentMethod->config : [];
        $response = Http::timeout(30)
            ->withBasicAuth(PaymentSettings::razorpayKey($config), PaymentSettings::razorpaySecret($config))
            ->acceptJson()
            ->post('https://api.razorpay.com/v1/payments/'.$paymentId.'/refund', [
                'amount' => (int) round($amount * 100),
            ]);

        return $response->successful()
            ? PaymentCaptureResult::paid(['razorpay' => $response->json()])
            : PaymentCaptureResult::failed('Razorpay refund failed.', ['razorpay' => $response->json()]);
    }

    public function supportsTransactionalCapture(): bool
    {
        return false;
    }
};
