<?php

namespace App\Domain\Payment\Drivers;

use App\Domain\Order\Models\Order;
use App\Domain\Payment\CheckoutLifecycle;
use App\Domain\Payment\Contracts\ChargesSavedMethods;
use App\Domain\Payment\Contracts\PaymentGateway;
use App\Domain\Payment\Contracts\RefundsPayments;
use App\Domain\Payment\PaymentCaptureResult;
use App\Domain\Payment\SavedChargeResult;
use App\Domain\Settings\ShopSetting;

/**
 * Stripe gateway.
 *
 * Implements ChargesSavedMethods, so subscriptions on Stripe renew for real: the
 * PaymentIntent is created with `off_session = true` and `confirm = true`, which tells
 * Stripe to charge the stored payment method without the customer present.
 *
 * Card data never touches this application — only Stripe's own ids are stored.
 */
class StripeGateway implements ChargesSavedMethods, PaymentGateway, RefundsPayments
{
    public function driver(): string
    {
        return 'stripe';
    }

    public function checkoutLifecycle(): CheckoutLifecycle
    {
        // Order is written first, then Stripe confirms — the redirect returns to us.
        return CheckoutLifecycle::OrderFirst;
    }

    public function supportsTransactionalCapture(): bool
    {
        // Network call: never inside the checkout transaction.
        return false;
    }

    public function capture(Order $order, array $payload = []): PaymentCaptureResult
    {
        $token = $this->secretKey();
        if ($token === '') {
            return PaymentCaptureResult::failed('Stripe is not configured.');
        }

        $amount = $this->minorUnits((float) $order->grand_total, (string) $order->currency_code);
        if ($amount <= 0) {
            return PaymentCaptureResult::failed('Order total must be greater than zero.');
        }

        $response = $this->post('https://api.stripe.com/v1/payment_intents', [
            'amount' => $amount,
            'currency' => strtolower((string) $order->currency_code),
            'confirm' => 'true',
            // A saved method (subscription renewal) charges off-session; a fresh checkout
            // redirects the customer to Stripe's hosted page.
            'automatic_payment_methods' => ['enabled' => 'true'],
            'metadata' => ['order' => (string) $order->number],
            'return_url' => rtrim((string) config('app.url'), '/') . '/account/orders/' . $order->number,
        ], $token);

        if ($response === null) {
            return PaymentCaptureResult::failed('Stripe did not respond.');
        }

        return $this->interpretIntent($response, $order);
    }

    public function chargeStored(Order $order, string $reference, array $payload = []): SavedChargeResult
    {
        $secret = $this->secretKey();
        if ($secret === '') {
            return SavedChargeResult::failed('Stripe is not configured.');
        }

        $amount = $this->minorUnits((float) $order->grand_total, (string) $order->currency_code);
        if ($amount <= 0) {
            return SavedChargeResult::failed('Order total must be greater than zero.');
        }

        $body = [
            'amount' => $amount,
            'currency' => strtolower((string) $order->currency_code),
            'payment_method' => $reference,
            // The two flags that make this a merchant-initiated, customer-absent charge.
            'off_session' => 'true',
            'confirm' => 'true',
            'metadata' => [
                'order' => (string) $order->number,
                'subscription_renewal' => 'true',
            ],
        ];

        $customerRef = (string) ($payload['customer_ref'] ?? '');
        if ($customerRef !== '') {
            $body['customer'] = $customerRef;
        }

        $headers = [];
        if (! empty($payload['idempotency_key'])) {
            // Stripe de-duplicates on this key, so a retried cron run cannot double-charge.
            $headers['Idempotency-Key'] = (string) $payload['idempotency_key'];
        }

        $response = $this->post('https://api.stripe.com/v1/payment_intents', $body, $secret, $headers);

        if ($response === null) {
            return SavedChargeResult::failed('Stripe did not respond.');
        }

        $status = (string) ($response['status'] ?? '');

        if ($status === 'succeeded') {
            return SavedChargeResult::paid(['intent' => $response['id'] ?? null]);
        }

        // The bank wants the customer to authenticate. Not a failure — hand them a link.
        if ($status === 'requires_action' || $status === 'requires_confirmation') {
            $url = $response['next_action']['redirect_to_url']['url']
                ?? $response['next_action']['use_stripe_sdk']['url']
                ?? null;
            if (is_string($url) && $url !== '') {
                return SavedChargeResult::requiresAction($url, ['intent' => $response['id'] ?? null]);
            }

            return SavedChargeResult::failed('The card needs authentication that Stripe did not provide a link for.');
        }

        $message = $response['last_payment_error']['message']
            ?? $response['error']['message']
            ?? 'The saved card was declined.';

        return SavedChargeResult::failed((string) $message, ['intent' => $response['id'] ?? null]);
    }

    public function storedReferenceIsUsable(?string $reference): bool
    {
        $reference = trim((string) $reference);
        if ($reference === '') {
            return false;
        }

        $secret = $this->secretKey();
        if ($secret === '') {
            return false;
        }

        // A detached/consumed payment method must not be retried forever.
        $response = $this->get('https://api.stripe.com/v1/payment_methods/' . rawurlencode($reference), $secret);
        if ($response === null) {
            // Network trouble is not proof the method is dead — keep it.
            return true;
        }

        return ! isset($response['error']);
    }

    public function refund(Order $order, float $amount, array $payload = []): PaymentCaptureResult
    {
        $secret = $this->secretKey();
        if ($secret === '') {
            return PaymentCaptureResult::failed('Stripe is not configured.');
        }

        $intent = (string) ($order->payment_details['intent'] ?? $order->payment_details['stripe_intent'] ?? '');
        if ($intent === '') {
            return PaymentCaptureResult::failed('No Stripe payment intent on this order.');
        }

        $response = $this->post('https://api.stripe.com/v1/refunds', [
            'payment_intent' => $intent,
            'amount' => $this->minorUnits($amount, (string) $order->currency_code),
        ], $secret);

        if ($response === null || isset($response['error'])) {
            return PaymentCaptureResult::failed((string) ($response['error']['message'] ?? 'Refund failed.'));
        }

        return PaymentCaptureResult::paid(['refund' => $response['id'] ?? null]);
    }

    protected function interpretIntent(array $intent, Order $order): PaymentCaptureResult
    {
        $status = (string) ($intent['status'] ?? '');
        $meta = ['intent' => $intent['id'] ?? null];

        if ($status === 'succeeded') {
            return PaymentCaptureResult::paid($meta);
        }

        if ($status === 'requires_action') {
            $url = $intent['next_action']['redirect_to_url']['url'] ?? null;
            if (is_string($url) && $url !== '') {
                return PaymentCaptureResult::redirect($url, $meta);
            }
        }

        if ($status === 'requires_payment_method') {
            return PaymentCaptureResult::failed('The card was declined.', $meta);
        }

        return PaymentCaptureResult::pending($meta);
    }

    /**
     * Stripe uses zero-decimal currencies for some currencies (JPY, KRW...).
     */
    protected function minorUnits(float $amount, string $currency): int
    {
        $zeroDecimal = ['bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf'];

        return in_array(strtolower($currency), $zeroDecimal, true)
            ? (int) round($amount)
            : (int) round($amount * 100);
    }

    protected function secretKey(): string
    {
        return trim((string) ShopSetting::get('payments.stripe_secret_key', ''));
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $extraHeaders
     */
    protected function post(string $url, array $body, string $secret, array $extraHeaders = []): ?array
    {
        return $this->request('POST', $url, $secret, $extraHeaders, $body);
    }

    protected function get(string $url, string $secret): ?array
    {
        return $this->request('GET', $url, $secret);
    }

    /**
     * @param  array<string, mixed>|null  $form
     * @param  array<string, string>  $extraHeaders
     */
    protected function request(string $method, string $url, string $secret, array $extraHeaders = [], ?array $form = null): ?array
    {
        $headers = ['Authorization: Bearer ' . $secret];
        foreach ($extraHeaders as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        if ($form !== null) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
        }

        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return null;
        }

        // 402 = declined, which carries a useful message — surface it rather than null.
        if ($code >= 400 && $code !== 402) {
            return $decoded;
        }

        return $decoded;
    }
}
