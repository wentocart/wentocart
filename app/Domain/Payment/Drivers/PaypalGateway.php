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

class PaypalGateway implements PaymentGateway, RefundsPayments
{
    public function driver(): string
    {
        return 'paypal';
    }

    public function checkoutLifecycle(): CheckoutLifecycle
    {
        return CheckoutLifecycle::OrderFirst;
    }

    public function capture(Order $order, array $payload = []): PaymentCaptureResult
    {
        $config = is_array($order->paymentMethod?->config) ? $order->paymentMethod->config : [];
        $client = PaymentSettings::paypalClientId($config);
        $secret = PaymentSettings::paypalSecret($config);
        if ($client === '' || $secret === '') {
            return PaymentSettings::sandboxCapture(
                'paypal',
                $order,
                'PayPal keys are missing. Paste Client ID and Secret on the payment method.',
            );
        }

        $host = PaymentSettings::paypalSandbox($config)
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';
        $token = $this->token($host, $client, $secret);
        if ($token === '') {
            return PaymentCaptureResult::failed('Could not authenticate with PayPal.');
        }

        $response = Http::timeout(30)
            ->withToken($token)
            ->acceptJson()
            ->post($host.'/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => $order->number,
                    'amount' => [
                        'currency_code' => strtoupper((string) ($order->currency_code ?: 'USD')),
                        'value' => number_format((float) $order->grand_total, 2, '.', ''),
                    ],
                ]],
                'application_context' => [
                    'return_url' => url('/order/'.$order->number),
                    'cancel_url' => url('/checkout'),
                ],
            ]);
        $json = $response->json() ?: [];
        $approve = collect($json['links'] ?? [])->firstWhere('rel', 'approve');
        if ($response->failed() || ! is_array($approve) || empty($approve['href'])) {
            return PaymentCaptureResult::failed((string) ($json['message'] ?? 'Could not start PayPal Checkout.'), ['paypal' => $json]);
        }

        return PaymentCaptureResult::redirect((string) $approve['href'], ['paypal_order_id' => $json['id'] ?? null]);
    }

    public function refund(Order $order, float $amount, array $payload = []): PaymentCaptureResult
    {
        $captureId = (string) data_get($order->payment_details, 'gateway.capture_id', '');
        if ($captureId === '') {
            return PaymentCaptureResult::paid(['paypal' => 'local', 'amount' => $amount]);
        }
        $config = is_array($order->paymentMethod?->config) ? $order->paymentMethod->config : [];
        $host = PaymentSettings::paypalSandbox($config)
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';
        $token = $this->token($host, PaymentSettings::paypalClientId($config), PaymentSettings::paypalSecret($config));
        if ($token === '') {
            return PaymentCaptureResult::failed('Could not authenticate with PayPal.');
        }
        $response = Http::timeout(30)->withToken($token)->acceptJson()->post($host.'/v2/payments/captures/'.$captureId.'/refund', [
            'amount' => [
                'currency_code' => strtoupper((string) ($order->currency_code ?: 'USD')),
                'value' => number_format($amount, 2, '.', ''),
            ],
        ]);

        return $response->successful()
            ? PaymentCaptureResult::paid(['paypal' => $response->json()])
            : PaymentCaptureResult::failed('PayPal refund failed.', ['paypal' => $response->json()]);
    }

    public function supportsTransactionalCapture(): bool
    {
        return false;
    }

    protected function token(string $host, string $client, string $secret): string
    {
        if ($client === '' || $secret === '') {
            return '';
        }
        try {
            SafeUrl::assertPublicHttp($host.'/v1/oauth2/token');
            $response = Http::timeout(20)->withBasicAuth($client, $secret)->asForm()
                ->post($host.'/v1/oauth2/token', ['grant_type' => 'client_credentials']);
        } catch (\Throwable) {
            return '';
        }

        return (string) ($response->json('access_token') ?? '');
    }
};
