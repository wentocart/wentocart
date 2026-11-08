<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Checkout\Services\CartService;
use App\Domain\Checkout\Services\CheckoutService;
use App\Domain\Settings\CartCheckoutSettings;
use App\Domain\Settings\CustomerSettings;
use App\Domain\Settings\ProductSettings;
use GraphQL\Error\UserError;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Thin GraphQL adapter over CheckoutService::place — same rules as REST /api/v1/checkout.
 */
final class GraphqlPlaceOrder
{
    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function run(array $args): array
    {
        if (ProductSettings::catalogMode()) {
            throw new UserError('This shop is catalog-only.');
        }

        $customer = auth('customer')->user();
        if (! $customer && ! CustomerSettings::guestCheckout() && ! CustomerSettings::registrationEnabled()) {
            throw new UserError('An account is required to place an order.');
        }

        $billing = self::address($args['billingAddress'] ?? null);
        $shipping = self::address($args['shippingAddress'] ?? null) ?: $billing;
        if ($billing === [] && $shipping !== []) {
            $billing = $shipping;
        }

        $payload = [
            'email' => (string) ($args['email'] ?? ($customer?->email ?? '')),
            'payment_method_id' => (int) ($args['paymentMethodId'] ?? 0),
            'shipping_method_id' => isset($args['shippingMethodId']) ? (int) $args['shippingMethodId'] : null,
            'billing_address' => $billing,
            'shipping_address' => $shipping,
            'customer_note' => isset($args['customerNote']) ? (string) $args['customerNote'] : null,
            'accept_terms' => (bool) ($args['acceptTerms'] ?? ! CartCheckoutSettings::termsRequired()),
        ];

        if ($payload['email'] === '' || $payload['payment_method_id'] < 1) {
            throw new UserError('email and paymentMethodId are required.');
        }

        try {
            $carts = app(CartService::class);
            $cart = $carts->current($customer);
            $carts->assertCartWithinLimit($cart);
            $result = app(CheckoutService::class)->place($cart, $payload, $customer);
        } catch (ValidationException $e) {
            $first = collect($e->errors())->flatten()->first();
            throw new UserError(is_string($first) && $first !== '' ? $first : 'Checkout validation failed.');
        } catch (Throwable $e) {
            throw new UserError(config('app.debug') ? $e->getMessage() : 'Could not place the order.');
        }

        $order = $result['order'] ?? null;

        $orderId = $order?->id ? (string) $order->id : null;

        return [
            'order_number' => $order?->number,
            'order_id' => $orderId,
            'orderId' => $orderId,
            'grand_total' => $order ? (float) $order->grand_total : null,
            'payment_status' => $order?->payment_status,
            'checkout_reference' => $result['order']->number ?? ($result['checkout_group']->reference ?? null),
            'success' => true,
            'message' => 'Order placed.',
            'redirect' => false,
            'redirectUrl' => null,
            'order' => $order ? GraphqlCustomerAccount::mapOrderPublic($order) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function address(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        return array_filter([
            'first_name' => $raw['firstName'] ?? $raw['first_name'] ?? null,
            'last_name' => $raw['lastName'] ?? $raw['last_name'] ?? null,
            'line1' => $raw['line1'] ?? null,
            'line2' => $raw['line2'] ?? null,
            'city' => $raw['city'] ?? null,
            'state' => $raw['state'] ?? null,
            'postal_code' => $raw['postalCode'] ?? $raw['postal_code'] ?? null,
            'country_code' => isset($raw['countryCode']) ? strtoupper((string) $raw['countryCode']) : (isset($raw['country_code']) ? strtoupper((string) $raw['country_code']) : null),
            'phone' => $raw['phone'] ?? null,
        ], static fn ($v) => $v !== null && $v !== '');
    }
}
