<?php

namespace App\Domain\Payment\Services;

use App\Domain\Checkout\CheckoutSnapshot;
use App\Domain\Checkout\Models\Cart;
use App\Domain\Checkout\Services\CheckoutService;
use App\Domain\Checkout\Services\CheckoutSnapshotBuilder;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Payment\CheckoutLifecycle;
use App\Domain\Payment\Contracts\PreparesCheckoutPayment;
use App\Domain\Payment\Contracts\VerifiesCheckoutPayment;
use App\Domain\Payment\Models\PaymentCheckout;
use App\Domain\Payment\PaymentCaptureResult;
use App\Domain\Payment\PaymentCheckoutResult;
use App\Domain\Payment\PaymentCheckoutStatus;
use App\Domain\Payment\PaymentGatewayRegistry;
use App\Domain\Payment\PaymentVerificationResult;
use App\Domain\Store\StoreContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentCheckoutService
{
    public function __construct(
        protected CheckoutSnapshotBuilder $snapshots,
        protected CheckoutService $checkout,
        protected PaymentGatewayRegistry $gateways,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     checkout_lifecycle: string,
     *     order?: Order,
     *     payment: array<string, mixed>,
     *     seller_orders?: list<array<string, mixed>>,
     *     session_token?: string,
     * }
     */
    public function settle(Cart $cart, array $payload, ?Customer $customer = null): array
    {
        return DB::transaction(function () use ($cart, $payload, $customer) {
            $snapshot = $this->snapshots->build($cart, $payload, $customer);
            $gateway = $this->gateways->get($snapshot->paymentDriver());

            if (! $gateway instanceof PreparesCheckoutPayment || ! $gateway instanceof VerifiesCheckoutPayment) {
                throw ValidationException::withMessages([
                    'payment' => 'This payment method does not support payment-first checkout.',
                ]);
            }

            $paymentProof = is_array($payload['payment_proof'] ?? null) ? $payload['payment_proof'] : [];
            $sessionToken = trim((string) ($payload['session_token'] ?? ''));
            $session = $this->resolveSession($snapshot, $sessionToken);

            if ($session?->isFulfilled()) {
                return $this->fulfilledResponse($session);
            }

            if ($paymentProof !== []) {
                return $this->verifyAndFulfill($snapshot, $session, $paymentProof, $gateway);
            }

            return $this->preparePayment($snapshot, $session, $gateway);
        });
    }

    /**
     * @param  array<string, mixed>  $paymentProof
     * @return array<string, mixed>
     */
    public function finalize(string $sessionToken, array $paymentProof): array
    {
        $session = PaymentCheckout::query()
            ->where('token', $sessionToken)
            ->lockForUpdate()
            ->firstOrFail();

        if ($session->isFulfilled()) {
            return $this->fulfilledResponse($session);
        }

        $snapshot = $this->snapshotFromSession($session);
        $gateway = $this->gateways->get($snapshot->paymentDriver());

        if (! $gateway instanceof VerifiesCheckoutPayment) {
            throw ValidationException::withMessages([
                'payment' => 'This payment session cannot be finalized.',
            ]);
        }

        return DB::transaction(function () use ($snapshot, $session, $paymentProof, $gateway) {
            return $this->verifyAndFulfill($snapshot, $session, $paymentProof, $gateway);
        });
    }

    /**
     * @param  PreparesCheckoutPayment&VerifiesCheckoutPayment  $gateway
     * @return array<string, mixed>
     */
    protected function preparePayment(
        CheckoutSnapshot $snapshot,
        ?PaymentCheckout $session,
        PreparesCheckoutPayment $gateway,
    ): array {
        $session = $this->ensureSession($snapshot, $session);
        $result = $gateway->prepareCheckoutPayment($snapshot, $session);

        $session->update([
            'status' => $result->status->value,
            'provider_reference' => $result->meta['provider_reference'] ?? $session->provider_reference,
            'meta' => array_merge(is_array($session->meta) ? $session->meta : [], $result->meta),
        ]);

        if ($result->status === PaymentCheckoutStatus::Failed) {
            throw ValidationException::withMessages([
                'payment' => $result->message ?: 'Payment could not be started.',
            ]);
        }

        return [
            'checkout_lifecycle' => CheckoutLifecycle::PaymentFirst->value,
            'session_token' => $session->token,
            'payment' => $result->toPaymentResponse(),
            'order' => null,
        ];
    }

    /**
     * @param  PreparesCheckoutPayment&VerifiesCheckoutPayment  $gateway
     * @param  array<string, mixed>  $paymentProof
     * @return array<string, mixed>
     */
    protected function verifyAndFulfill(
        CheckoutSnapshot $snapshot,
        ?PaymentCheckout $session,
        array $paymentProof,
        PreparesCheckoutPayment $gateway,
    ): array {
        $session = $this->ensureSession($snapshot, $session);

        if ($session->checkoutStatus() === PaymentCheckoutStatus::PaidUnfulfilled) {
            return $this->recoverFulfillment($session, $snapshot);
        }

        $verification = $gateway->verifyCheckoutPayment($snapshot, $session, $paymentProof);

        if (! $verification->verified) {
            $session->update([
                'status' => $verification->status->value,
                'payment_proof' => $verification->paymentProof ?: $paymentProof,
                'meta' => array_merge(is_array($session->meta) ? $session->meta : [], $verification->meta),
            ]);

            if ($verification->status === PaymentCheckoutStatus::RequiresAction) {
                return [
                    'checkout_lifecycle' => CheckoutLifecycle::PaymentFirst->value,
                    'session_token' => $session->token,
                    'payment' => PaymentCheckoutResult::requiresAction(
                        $session->token,
                        $verification->meta,
                        $snapshot->paymentContext,
                        $verification->meta,
                    )->toPaymentResponse(),
                    'order' => null,
                ];
            }

            throw ValidationException::withMessages([
                'payment' => $verification->message ?: 'Payment verification failed.',
            ]);
        }

        $consumed = PaymentCheckout::query()
            ->where('provider_reference', $verification->providerReference)
            ->whereNotNull('order_id')
            ->where('id', '!=', $session->id)
            ->exists();

        if ($consumed) {
            throw ValidationException::withMessages([
                'payment' => 'This payment has already been used for an order.',
            ]);
        }

        $session->update([
            'status' => PaymentCheckoutStatus::Paid->value,
            'provider_reference' => $verification->providerReference,
            'payment_proof' => $verification->paymentProof,
            'meta' => array_merge(is_array($session->meta) ? $session->meta : [], $verification->meta),
        ]);

        try {
            $fulfilled = $this->checkout->fulfillFromSnapshot($snapshot, $verification);
        } catch (\Throwable $e) {
            $session->update(['status' => PaymentCheckoutStatus::PaidUnfulfilled->value]);
            throw $e;
        }

        $session->update([
            'status' => PaymentCheckoutStatus::Fulfilled->value,
            'order_id' => $fulfilled['order']->id,
        ]);

        session([
            'wento.last_order' => $fulfilled['order']->number,
            'wento.last_order_email' => $fulfilled['order']->email,
            'wento.last_checkout_reference' => $fulfilled['order']->checkout_reference,
        ]);

        return [
            'checkout_lifecycle' => CheckoutLifecycle::PaymentFirst->value,
            'session_token' => $session->token,
            'order' => $fulfilled['order'],
            'payment' => PaymentCaptureResult::paid($verification->meta)->toArray(),
            'seller_orders' => $fulfilled['seller_orders'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function recoverFulfillment(PaymentCheckout $session, CheckoutSnapshot $snapshot): array
    {
        $verification = PaymentVerificationResult::paid(
            (string) $session->provider_reference,
            is_array($session->payment_proof) ? $session->payment_proof : [],
            is_array($session->meta) ? $session->meta : [],
            (float) $session->amount,
            (string) $session->currency_code,
        );

        $fulfilled = $this->checkout->fulfillFromSnapshot($snapshot, $verification);

        $session->update([
            'status' => PaymentCheckoutStatus::Fulfilled->value,
            'order_id' => $fulfilled['order']->id,
        ]);

        session([
            'wento.last_order' => $fulfilled['order']->number,
            'wento.last_order_email' => $fulfilled['order']->email,
            'wento.last_checkout_reference' => $fulfilled['order']->checkout_reference,
        ]);

        return [
            'checkout_lifecycle' => CheckoutLifecycle::PaymentFirst->value,
            'session_token' => $session->token,
            'order' => $fulfilled['order'],
            'payment' => PaymentCaptureResult::paid($verification->meta)->toArray(),
            'seller_orders' => $fulfilled['seller_orders'],
        ];
    }

    protected function ensureSession(CheckoutSnapshot $snapshot, ?PaymentCheckout $session): PaymentCheckout
    {
        if ($session) {
            $this->assertSessionMatches($session, $snapshot);

            return $session;
        }

        if ($snapshot->idempotencyKey) {
            $existing = PaymentCheckout::query()
                ->where('idempotency_key', $snapshot->idempotencyKey)
                ->where('cart_id', $snapshot->cart->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->assertSessionMatches($existing, $snapshot);

                return $existing;
            }
        }

        return PaymentCheckout::query()->create([
            'token' => PaymentCheckout::issueToken(),
            'store_id' => $snapshot->cart->store_id ?: app(StoreContext::class)->requireId(),
            'cart_id' => $snapshot->cart->id,
            'customer_id' => $snapshot->customer?->id,
            'email' => $snapshot->email(),
            'payment_method_id' => $snapshot->paymentMethod->id,
            'driver' => $snapshot->paymentDriver(),
            'status' => PaymentCheckoutStatus::RequiresPayment->value,
            'amount' => $snapshot->captureAmount,
            'currency_code' => $snapshot->currencyCode(),
            'idempotency_key' => $snapshot->idempotencyKey,
            'checkout_snapshot' => $snapshot->toSessionPayload(),
            'expires_at' => now()->addHours(24),
        ]);
    }

    protected function resolveSession(CheckoutSnapshot $snapshot, string $sessionToken): ?PaymentCheckout
    {
        if ($sessionToken !== '') {
            $session = PaymentCheckout::query()->where('token', $sessionToken)->lockForUpdate()->first();
            if ($session) {
                $this->assertSessionMatches($session, $snapshot);

                return $session;
            }
        }

        if ($snapshot->idempotencyKey) {
            return PaymentCheckout::query()
                ->where('idempotency_key', $snapshot->idempotencyKey)
                ->where('cart_id', $snapshot->cart->id)
                ->lockForUpdate()
                ->first();
        }

        return null;
    }

    protected function assertSessionMatches(PaymentCheckout $session, CheckoutSnapshot $snapshot): void
    {
        if ((int) $session->cart_id !== (int) $snapshot->cart->id) {
            throw ValidationException::withMessages([
                'payment' => 'This payment session belongs to another cart.',
            ]);
        }

        if ((int) $session->payment_method_id !== (int) $snapshot->paymentMethod->id) {
            throw ValidationException::withMessages([
                'payment' => 'This payment session uses a different payment method.',
            ]);
        }

        $fingerprint = $snapshot->totalsFingerprint();
        $stored = hash('sha256', json_encode([
            'grand' => data_get($session->checkout_snapshot, 'totals.grand_total'),
            'capture' => data_get($session->checkout_snapshot, 'totals.capture_amount'),
            'currency' => data_get($session->checkout_snapshot, 'totals.currency'),
            'cart' => (int) $session->cart_id,
        ]));

        if ($stored !== '' && $fingerprint !== $stored && ! $session->isFulfilled()) {
            $session->update(['checkout_snapshot' => $snapshot->toSessionPayload()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function fulfilledResponse(PaymentCheckout $session): array
    {
        $order = Order::query()->findOrFail($session->order_id);

        return [
            'checkout_lifecycle' => CheckoutLifecycle::PaymentFirst->value,
            'session_token' => $session->token,
            'order' => $order->load(['items.product', 'paymentMethod', 'shippingMethod', 'sellerOrders.seller', 'sellerOrders.items']),
            'payment' => PaymentCaptureResult::paid(is_array($session->meta) ? $session->meta : [])->toArray(),
            'seller_orders' => app(CheckoutService::class)->presentSellerOrdersPublic($order),
        ];
    }

    protected function snapshotFromSession(PaymentCheckout $session): CheckoutSnapshot
    {
        $cart = Cart::query()->whereKey($session->cart_id)->lockForUpdate()->firstOrFail();
        $payload = is_array($session->checkout_snapshot) ? $session->checkout_snapshot : [];

        return $this->snapshots->build($cart, array_merge($payload, [
            'payment_method_id' => $session->payment_method_id,
            'payment_context' => data_get($session->meta, 'payment_context', []),
        ]), $session->customer_id ? Customer::query()->find($session->customer_id) : null);
    }
}
