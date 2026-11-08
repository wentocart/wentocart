<?php

namespace App\Domain\Checkout;

use App\Domain\Checkout\Models\Cart;
use App\Domain\Customer\Models\Customer;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Shipping\Models\ShippingMethod;

class CheckoutSnapshot
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $totals
     * @param  array<string, mixed>  $paymentContext
     */
    public function __construct(
        public readonly Cart $cart,
        public readonly array $payload,
        public readonly array $totals,
        public readonly PaymentMethod $paymentMethod,
        public readonly ?ShippingMethod $shippingMethod,
        public readonly ?Customer $customer,
        public readonly float $grandTotal,
        public readonly float $captureAmount,
        public readonly ?float $amountDue,
        public readonly bool $hasDueNow,
        public readonly array $paymentContext = [],
        public readonly ?string $idempotencyKey = null,
        /** @var list<array{method: ?ShippingMethod, items: mixed, totals: array<string, mixed>}> */
        public readonly array $shippingGroups = [],
    ) {}

    public function paymentDriver(): string
    {
        return strtolower((string) ($this->paymentMethod->driver ?: 'offline'));
    }

    public function email(): string
    {
        return (string) ($this->payload['email'] ?? $this->customer?->email ?? '');
    }

    public function currencyCode(): string
    {
        return (string) ($this->totals['currency'] ?? 'USD');
    }

    /**
     * @return array<string, mixed>
     */
    public function toSessionPayload(): array
    {
        return [
            'email' => $this->email(),
            'payment_method_id' => (int) $this->paymentMethod->id,
                'shipping_method_id' => $this->shippingMethod?->id,
                'checkout_groups' => collect($this->shippingGroups)->map(fn ($group) => [
                    'method_id' => $group['method']?->id,
                    'item_ids' => collect($group['items'] ?? [])->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                    'grand_total' => $group['totals']['grand_total'] ?? null,
                    'shipping' => $group['totals']['shipping'] ?? null,
                ])->values()->all(),
            'billing_address' => $this->payload['billing_address'] ?? null,
            'shipping_address' => $this->payload['shipping_address'] ?? null,
            'customer_note' => $this->payload['customer_note'] ?? null,
            'totals' => [
                'grand_total' => $this->grandTotal,
                'capture_amount' => $this->captureAmount,
                'currency' => $this->currencyCode(),
            ],
        ];
    }

    public function totalsFingerprint(): string
    {
        return hash('sha256', json_encode([
            'grand' => $this->grandTotal,
            'capture' => $this->captureAmount,
            'currency' => $this->currencyCode(),
            'cart' => (int) $this->cart->id,
            'items' => $this->cart->items->map(fn ($item) => [
                'id' => (int) $item->id,
                'qty' => (float) $item->qty,
                'product_id' => (int) $item->product_id,
            ])->values()->all(),
        ]));
    }
}
