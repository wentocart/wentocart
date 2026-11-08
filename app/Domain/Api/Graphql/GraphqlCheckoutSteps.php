<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Checkout\Models\Cart;
use App\Domain\Checkout\Services\CartService;
use App\Domain\Checkout\Services\CheckoutService;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Settings\CartCheckoutSettings;
use App\Domain\Settings\CustomerSettings;
use App\Domain\Settings\ProductSettings;
use App\Domain\Shipping\Models\ShippingMethod;
use GraphQL\Error\UserError;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Shop REST checkout sequence: address → shipping → payment → order.
 * Draft state lives on cart.meta.checkout (ahead of one-shot placeOrder).
 */
final class GraphqlCheckoutSteps
{
    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function saveAddress(array $args): array
    {
        $carts = app(CartService::class);
        $customer = auth('customer')->user();
        $cart = $carts->current($customer);
        if ($cart->items()->count() < 1) {
            throw new UserError('Cart is empty.');
        }

        $useForShipping = (bool) ($args['useForShipping'] ?? true);
        $billing = self::addressFromArgs($args, 'billing');
        if ($billing === []) {
            throw new UserError('Billing address is required.');
        }
        $shipping = $useForShipping ? $billing : self::addressFromArgs($args, 'shipping');
        if ($shipping === []) {
            throw new UserError('Shipping address is required when useForShipping is false.');
        }
        $email = strtolower(trim((string) ($args['billingEmail'] ?? $customer?->email ?? '')));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new UserError('A valid billing email is required.');
        }

        $draft = self::draft($cart);
        $draft['email'] = $email;
        $draft['billing_address'] = $billing;
        $draft['shipping_address'] = $shipping;
        $draft['shipping_method_id'] = null;
        $draft['payment_method_id'] = null;
        self::putDraft($cart, $draft);

        return self::addressPayload($cart, $draft, true, 'Checkout address saved.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function shippingRates(): array
    {
        $carts = app(CartService::class);
        $customer = auth('customer')->user();
        $cart = $carts->current($customer);
        $draft = self::draft($cart);
        $country = strtoupper((string) ($draft['shipping_address']['country_code'] ?? $draft['billing_address']['country_code'] ?? ''));
        if (strlen($country) !== 2) {
            throw new UserError('Set checkout address before listing shipping rates.');
        }

        return ShippingMethod::query()
            ->where('is_enabled', true)
            ->whereNull('seller_id')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(function (ShippingMethod $method) use ($carts, $cart, $country) {
                return [
                    'id' => (string) $method->id,
                    'code' => $method->code ?? $method->type,
                    'title' => $method->name ?: ('Method #'.$method->id),
                    'price' => $carts->quoteShipping($cart, $method, $country),
                    'is_pickup' => $method->type === 'local_pickup',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{success: bool, message: string, shippingMethodId: string}
     */
    public static function saveShippingMethod(array $args): array
    {
        $carts = app(CartService::class);
        $customer = auth('customer')->user();
        $cart = $carts->current($customer);
        $draft = self::draft($cart);
        if (empty($draft['shipping_address']) && empty($draft['billing_address'])) {
            throw new UserError('Set checkout address before choosing a shipping method.');
        }
        $methodId = (int) ($args['shippingMethodId'] ?? $args['methodId'] ?? 0);
        $code = trim((string) ($args['method'] ?? $args['code'] ?? ''));
        $method = null;
        if ($methodId > 0) {
            $method = ShippingMethod::query()->where('is_enabled', true)->whereKey($methodId)->first();
        } elseif ($code !== '') {
            $method = ShippingMethod::query()
                ->where('is_enabled', true)
                ->where(function ($q) use ($code) {
                    $q->where('code', $code)->orWhere('type', $code);
                })
                ->first();
        }
        if (! $method) {
            throw new UserError('Shipping method not found.');
        }
        $draft['shipping_method_id'] = (int) $method->id;
        $draft['payment_method_id'] = null;
        self::putDraft($cart, $draft);

        return [
            'success' => true,
            'message' => 'Shipping method saved.',
            'shippingMethodId' => (string) $method->id,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function paymentMethods(): array
    {
        $carts = app(CartService::class);
        $customer = auth('customer')->user();
        $cart = $carts->current($customer);
        $draft = self::draft($cart);
        if (empty($draft['shipping_method_id'])) {
            throw new UserError('Set shipping method before listing payment methods.');
        }

        return PaymentMethod::query()
            ->where('is_enabled', true)
            ->whereNull('seller_id')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (PaymentMethod $m) => [
                'id' => (string) $m->id,
                'code' => $m->code ?? $m->driver ?? (string) $m->id,
                'title' => $m->name ?: ('Payment #'.$m->id),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{success: bool, message: string, paymentMethodId: string}
     */
    public static function savePaymentMethod(array $args): array
    {
        $carts = app(CartService::class);
        $customer = auth('customer')->user();
        $cart = $carts->current($customer);
        $draft = self::draft($cart);
        if (empty($draft['shipping_method_id'])) {
            throw new UserError('Set shipping method before choosing a payment method.');
        }
        $methodId = (int) ($args['paymentMethodId'] ?? $args['methodId'] ?? 0);
        $code = trim((string) ($args['method'] ?? $args['code'] ?? ''));
        $method = null;
        if ($methodId > 0) {
            $method = PaymentMethod::query()->where('is_enabled', true)->whereKey($methodId)->first();
        } elseif ($code !== '') {
            $method = PaymentMethod::query()
                ->where('is_enabled', true)
                ->where(function ($q) use ($code) {
                    $q->where('code', $code)->orWhere('driver', $code);
                })
                ->first();
        }
        if (! $method) {
            throw new UserError('Payment method not found.');
        }
        $draft['payment_method_id'] = (int) $method->id;
        self::putDraft($cart, $draft);

        return [
            'success' => true,
            'message' => 'Payment method saved.',
            'paymentMethodId' => (string) $method->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getAddresses(): array
    {
        $carts = app(CartService::class);
        $customer = auth('customer')->user();
        $cart = $carts->current($customer);
        $draft = self::draft($cart);
        if (empty($draft['billing_address'])) {
            throw new UserError('Checkout address not set.');
        }

        return self::addressPayload($cart, $draft, true, null);
    }

    /**
     * @return array<string, mixed>
     */
    public static function placeOrder(): array
    {
        if (ProductSettings::catalogMode()) {
            throw new UserError('This shop is catalog-only.');
        }
        $customer = auth('customer')->user();
        if (! $customer && ! CustomerSettings::guestCheckout() && ! CustomerSettings::registrationEnabled()) {
            throw new UserError('An account is required to place an order.');
        }

        $carts = app(CartService::class);
        $cart = $carts->current($customer);
        $draft = self::draft($cart);
        $email = (string) ($draft['email'] ?? $customer?->email ?? '');
        $paymentId = (int) ($draft['payment_method_id'] ?? 0);
        $shippingId = isset($draft['shipping_method_id']) ? (int) $draft['shipping_method_id'] : null;
        $billing = is_array($draft['billing_address'] ?? null) ? $draft['billing_address'] : [];
        $shipping = is_array($draft['shipping_address'] ?? null) ? $draft['shipping_address'] : $billing;

        if ($email === '' || $paymentId < 1 || $billing === []) {
            throw new UserError('Complete checkout address, shipping, and payment before placing the order.');
        }

        $payload = [
            'email' => $email,
            'payment_method_id' => $paymentId,
            'shipping_method_id' => $shippingId,
            'billing_address' => $billing,
            'shipping_address' => $shipping,
            'customer_note' => null,
            'accept_terms' => ! CartCheckoutSettings::termsRequired(),
        ];

        try {
            $carts->assertCartWithinLimit($cart);
            $result = app(CheckoutService::class)->place($cart, $payload, $customer);
        } catch (ValidationException $e) {
            $first = collect($e->errors())->flatten()->first();
            throw new UserError(is_string($first) && $first !== '' ? $first : 'Checkout validation failed.');
        } catch (Throwable $e) {
            throw new UserError(config('app.debug') ? $e->getMessage() : 'Could not place the order.');
        }

        $order = $result['order'] ?? null;
        $meta = is_array($cart->meta) ? $cart->meta : [];
        unset($meta['checkout']);
        // Cart may be deleted/emptied by place — ignore failures.
        try {
            $cart->update(['meta' => $meta === [] ? null : $meta]);
        } catch (Throwable) {
        }

        $mappedOrder = null;
        if ($order) {
            $mappedOrder = GraphqlCustomerAccount::mapOrderPublic($order);
        }

        return [
            'success' => true,
            'message' => 'Order placed.',
            'orderId' => $order?->id ? (string) $order->id : null,
            'order_id' => $order?->id ? (string) $order->id : null,
            'order_number' => $order?->number,
            'grand_total' => $order ? (float) $order->grand_total : null,
            'payment_status' => $order?->payment_status,
            'checkout_reference' => $order?->number ?? ($result['checkout_group']->reference ?? null),
            'redirect' => false,
            'redirectUrl' => null,
            'order' => $mappedOrder,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function draft(Cart $cart): array
    {
        $meta = is_array($cart->meta) ? $cart->meta : [];
        $draft = $meta['checkout'] ?? [];

        return is_array($draft) ? $draft : [];
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    protected static function putDraft(Cart $cart, array $draft): void
    {
        $meta = is_array($cart->meta) ? $cart->meta : [];
        $meta['checkout'] = $draft;
        $cart->update(['meta' => $meta]);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    protected static function addressFromArgs(array $args, string $prefix): array
    {
        $first = trim((string) ($args[$prefix.'FirstName'] ?? ''));
        $last = trim((string) ($args[$prefix.'LastName'] ?? ''));
        $line1 = trim((string) ($args[$prefix.'Address'] ?? $args[$prefix.'Address1'] ?? ''));
        $city = trim((string) ($args[$prefix.'City'] ?? ''));
        $country = strtoupper(trim((string) ($args[$prefix.'Country'] ?? '')));
        $state = trim((string) ($args[$prefix.'State'] ?? ''));
        $postcode = trim((string) ($args[$prefix.'Postcode'] ?? ''));
        $phone = trim((string) ($args[$prefix.'PhoneNumber'] ?? $args[$prefix.'Phone'] ?? ''));

        if ($first === '' || $line1 === '' || $city === '' || strlen($country) !== 2) {
            return [];
        }

        return array_filter([
            'first_name' => $first,
            'last_name' => $last !== '' ? $last : null,
            'line1' => $line1,
            'city' => $city,
            'state' => $state !== '' ? $state : null,
            'postal_code' => $postcode !== '' ? $postcode : null,
            'country_code' => $country,
            'phone' => $phone !== '' ? $phone : null,
        ], static fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    protected static function addressPayload(Cart $cart, array $draft, bool $success, ?string $message): array
    {
        $billing = is_array($draft['billing_address'] ?? null) ? $draft['billing_address'] : [];
        $shipping = is_array($draft['shipping_address'] ?? null) ? $draft['shipping_address'] : [];

        return [
            'success' => $success,
            'message' => $message,
            'cartToken' => $cart->session_id,
            'billingFirstName' => $billing['first_name'] ?? null,
            'billingLastName' => $billing['last_name'] ?? null,
            'billingAddress' => $billing['line1'] ?? null,
            'billingCity' => $billing['city'] ?? null,
            'billingState' => $billing['state'] ?? null,
            'billingPostcode' => $billing['postal_code'] ?? null,
            'billingPhoneNumber' => $billing['phone'] ?? null,
            'shippingFirstName' => $shipping['first_name'] ?? null,
            'shippingLastName' => $shipping['last_name'] ?? null,
            'shippingAddress' => $shipping['line1'] ?? null,
            'shippingCity' => $shipping['city'] ?? null,
            'shippingState' => $shipping['state'] ?? null,
            'shippingPostcode' => $shipping['postal_code'] ?? null,
            'shippingPhoneNumber' => $shipping['phone'] ?? null,
        ];
    }
}
