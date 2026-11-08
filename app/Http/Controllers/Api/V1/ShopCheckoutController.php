<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Checkout\CheckoutContext;
use App\Domain\Checkout\Services\CartService;
use App\Domain\Checkout\Services\CheckoutService;
use App\Domain\Customer\Models\Customer;
use App\Domain\Localization\Money;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Plugin\Support\ModuleBuses;
use App\Domain\Settings\ProductSettings;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shop REST checkout sequence REST under /api/v1/store/*.
 */
class ShopCheckoutController extends Controller
{
    public function getAddresses(Request $request, CartService $carts): JsonResponse
    {
        $cart = $this->cartOrFail($request, $carts, requireItems: false);
        $state = $this->state($cart);
        if (empty($state['billing'])) {
            return response()->json([]);
        }

        return response()->json($this->addressResponse($state));
    }

    public function setAddresses(Request $request, CartService $carts): JsonResponse
    {
        $cart = $this->cartOrFail($request, $carts);
        $billing = $this->readAddress($request, 'billing');
        if ($billing === null) {
            return $this->prereqFail('Billing address is required');
        }
        $useForShipping = $request->boolean('useForShipping', true);
        $shipping = $useForShipping ? $billing : $this->readAddress($request, 'shipping');
        if ($shipping === null) {
            return $this->prereqFail('Shipping address is required');
        }

        $state = $this->state($cart);
        $state['billing'] = $billing;
        $state['shipping'] = $shipping;
        $state['useForShipping'] = $useForShipping;

        // Optional one-shot later steps
        if ($request->filled('shippingMethod')) {
            $state['shippingMethod'] = (string) $request->input('shippingMethod');
        }
        if ($request->filled('paymentMethod')) {
            $state['paymentMethod'] = (string) $request->input('paymentMethod');
        }

        $this->saveState($cart, $state);

        return response()->json($this->addressResponse($state));
    }

    public function getShippingMethods(Request $request, CartService $carts): JsonResponse
    {
        $cart = $this->cartOrFail($request, $carts);
        $state = $this->state($cart);
        if (empty($state['billing'])) {
            return response()->json([]);
        }

        $customer = $request->user('customer');
        $cart->loadMissing('items.product');
        $country = strtoupper((string) ($state['shipping']['country_code'] ?? $state['billing']['country_code'] ?? ''));
        $context = CheckoutContext::fromCart($cart, $customer instanceof Customer ? $customer : null, $request);

        $sellerIds = $cart->items
            ->map(fn ($item) => (int) ($item->product?->seller_id ?? 0))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $methods = ShippingMethod::query()
            ->where('is_enabled', true)
            ->where(function ($query) use ($sellerIds) {
                $query->whereNull('seller_id');
                if ($sellerIds !== []) {
                    $query->orWhereIn('seller_id', $sellerIds);
                }
            })
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->filter(fn (ShippingMethod $m) => $m->matchesContext($context))
            ->values();
        $methods = ModuleBuses::applyShippingFilters($methods, $context);

        $rates = [];
        foreach ($methods as $method) {
            try {
                $price = $carts->quoteShipping($cart, $method, $country !== '' ? $country : null);
            } catch (\Throwable) {
                $price = (float) ($method->price ?? 0);
            }
            $code = (string) ($method->code ?: $method->type ?: 'method_'.$method->id);
            $methodKey = $code.'_'.$code;
            $rates[] = [
                'id' => $methodKey.'_'.$methodKey,
                'method' => $methodKey,
                'carrier' => $code,
                'carrierTitle' => (string) ($method->name ?: $code),
                'methodTitle' => (string) ($method->name ?: $code),
                'methodDescription' => (string) ($method->description ?? ''),
                'price' => $price,
                'basePrice' => $price,
                'formattedPrice' => Money::format((float) $price),
                'shippingMethodId' => (int) $method->id,
            ];
        }

        return response()->json($rates);
    }

    public function setShippingMethod(Request $request, CartService $carts): JsonResponse
    {
        $cart = $this->cartOrFail($request, $carts);
        $state = $this->state($cart);
        if (empty($state['billing'])) {
            return $this->prereqFail('Billing address is required');
        }
        $method = trim((string) $request->input('shippingMethod', ''));
        if ($method === '') {
            return $this->prereqFail('Shipping method is required');
        }
        $state['shippingMethod'] = $method;
        $this->saveState($cart, $state);

        return response()->json([
            'shippingMethod' => $method,
            'success' => true,
            'message' => 'Shipping method saved.',
        ]);
    }

    public function getPaymentMethods(Request $request, CartService $carts): JsonResponse
    {
        $cart = $this->cartOrFail($request, $carts);
        $state = $this->state($cart);
        if (empty($state['billing'])) {
            return response()->json([]);
        }
        if ($this->requiresShipping($cart) && empty($state['shippingMethod'])) {
            return response()->json([]);
        }

        $customer = $request->user('customer');
        $cart->loadMissing('items.product');
        $context = CheckoutContext::fromCart($cart, $customer instanceof Customer ? $customer : null, $request);
        $shipId = $this->resolveShippingId((string) ($state['shippingMethod'] ?? ''));
        if ($shipId > 0) {
            $context['shipping_method_id'] = $shipId;
        }

        $payments = PaymentMethod::query()
            ->where('is_enabled', true)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->filter(fn (PaymentMethod $m) => $m->matchesContext($context))
            ->values();
        $payments = ModuleBuses::applyPaymentFilters($payments, $context);

        $rows = $payments->map(function (PaymentMethod $m) {
            $code = (string) ($m->code ?: $m->type ?: 'payment_'.$m->id);

            return [
                'method' => $code,
                'methodTitle' => $m->textForLocale('name') ?: $code,
                'description' => $m->textForLocale('description'),
                'sort' => (int) ($m->position ?? 0),
                'paymentMethodId' => (int) $m->id,
            ];
        })->values()->all();

        return response()->json($rows);
    }

    public function setPaymentMethod(Request $request, CartService $carts): JsonResponse
    {
        $cart = $this->cartOrFail($request, $carts);
        $state = $this->state($cart);
        if (empty($state['billing'])) {
            return $this->prereqFail('Billing address is required');
        }
        $method = trim((string) $request->input('paymentMethod', ''));
        if ($method === '') {
            return $this->prereqFail('Payment method is required');
        }
        $state['paymentMethod'] = $method;
        $this->saveState($cart, $state);

        return response()->json([
            'paymentMethod' => $method,
            'success' => true,
            'message' => 'Payment method saved.',
        ]);
    }

    /**
     */
    public function checkMinimumOrder(Request $request, CartService $carts): JsonResponse
    {
        $customer = $request->user('customer');
        $cart = $carts->current($customer instanceof Customer ? $customer : null);
        $totals = $carts->summary(
            $cart,
            0.0,
            null,
            null,
            $customer instanceof Customer ? $customer : null,
            Money::currentCode(),
            true,
            null,
        );
        $grand = (float) ($totals['grand_total'] ?? $totals['subtotal'] ?? 0);
        $minimum = (float) (\App\Domain\Settings\ShopSetting::get('checkout.minimum_order_amount', 0) ?: 0);
        $ok = $grand >= $minimum;

        return response()->json([
            'status' => $ok,
            'success' => $ok,
            'cartAmount' => $grand,
            'minimumOrderAmount' => $minimum,
            'formattedCartAmount' => Money::format($grand),
            'formattedMinimumOrderAmount' => Money::format($minimum),
            'message' => $ok
                ? 'Minimum order amount satisfied.'
                : 'Cart total is below the minimum order amount.',
        ], $ok ? 200 : 422);
    }

    /**
     */
    public function summary(Request $request, CartService $carts): JsonResponse
    {
        $customer = $request->user('customer');
        $cart = $carts->current($customer instanceof Customer ? $customer : null);
        $state = $this->state($cart);
        $totals = $carts->summary(
            $cart,
            0.0,
            null,
            null,
            $customer instanceof Customer ? $customer : null,
            Money::currentCode(),
            true,
            null,
        );
        $subtotal = (float) ($totals['subtotal'] ?? 0);
        $discount = (float) ($totals['discount'] ?? $totals['discount_amount'] ?? 0);
        $tax = (float) ($totals['tax'] ?? $totals['tax_amount'] ?? 0);
        $shipping = (float) ($totals['shipping'] ?? $totals['shipping_amount'] ?? 0);
        $grand = (float) ($totals['grand_total'] ?? $subtotal);
        $itemsCount = (int) $cart->items()->count();

        return response()->json([
            'cartId' => (int) $cart->id,
            'cartToken' => (string) ($cart->session_id ?: $cart->id),
            'itemsCount' => $itemsCount,
            'subtotal' => $subtotal,
            'discountAmount' => $discount,
            'taxAmount' => $tax,
            'shippingAmount' => $shipping,
            'grandTotal' => $grand,
            'formattedSubtotal' => Money::format($subtotal),
            'formattedDiscountAmount' => Money::format($discount),
            'formattedTaxAmount' => Money::format($tax),
            'formattedShippingAmount' => Money::format($shipping),
            'formattedGrandTotal' => Money::format($grand),
            'shippingMethod' => $state['shippingMethod'] ?? null,
            'paymentMethod' => $state['paymentMethod'] ?? null,
            'hasBillingAddress' => ! empty($state['billing'] ?? null),
            'hasShippingAddress' => ! empty($state['shipping'] ?? $state['billing'] ?? null),
            'success' => true,
        ]);
    }

    public function placeOrder(Request $request, CartService $carts, CheckoutService $checkout): JsonResponse
    {
        if (ProductSettings::catalogMode()) {
            return $this->prereqFail('Cart is empty');
        }

        $customer = $request->user('customer');
        $cart = $this->cartOrFail($request, $carts);
        $state = $this->state($cart);

        if (empty($state['billing'])) {
            return $this->prereqFail('Billing address is required');
        }
        if ($this->requiresShipping($cart) && empty($state['shippingMethod'])) {
            return $this->prereqFail('Shipping method is required');
        }
        if (empty($state['paymentMethod'])) {
            return $this->prereqFail('Payment method is required');
        }

        $paymentId = $this->resolvePaymentId((string) $state['paymentMethod']);
        if ($paymentId < 1) {
            return $this->prereqFail('Payment method is required');
        }
        $shippingId = null;
        if ($this->requiresShipping($cart)) {
            $shippingId = $this->resolveShippingId((string) $state['shippingMethod']);
            if ($shippingId < 1) {
                return $this->prereqFail('Shipping method is required');
            }
        }

        $billing = $this->toWentoAddress($state['billing']);
        $shipping = $this->toWentoAddress($state['shipping'] ?? $state['billing']);
        $email = (string) ($state['billing']['email'] ?? $customer?->email ?? '');

        $payload = [
            'email' => $email,
            'payment_method_id' => $paymentId,
            'shipping_method_id' => $shippingId,
            'billing_address' => $billing,
            'shipping_address' => $shipping,
            'accept_terms' => true,
        ];

        try {
            $result = $checkout->place($cart, $payload, $customer instanceof Customer ? $customer : null);
        } catch (\Throwable $e) {
            return response()->json([
                'detail' => $e->getMessage(),
                'message' => $e->getMessage(),
                'success' => false,
            ], 500);
        }

        $order = $result['order'] ?? null;
        $payment = $result['payment'] ?? null;
        $redirectUrl = is_object($payment) && method_exists($payment, 'toArray')
            ? ($payment->toArray()['redirect_url'] ?? $payment->toArray()['checkout_url'] ?? null)
            : (is_array($payment) ? ($payment['redirect_url'] ?? $payment['checkout_url'] ?? null) : null);
        $needsRedirect = ! empty($redirectUrl) && ! $order;

        $this->saveState($cart, []);

        return response()->json([
            'id' => (int) $cart->id,
            'cartToken' => (string) ($cart->session_id ?: $cart->id),
            'orderId' => $order ? (string) ($order->id ?? $order->number ?? '') : null,
            'orderIncrementId' => $order ? (string) ($order->number ?? '') : null,
            'redirect' => (bool) $needsRedirect,
            'redirectUrl' => $needsRedirect ? (string) $redirectUrl : null,
            'success' => true,
            'message' => $needsRedirect
                ? 'Redirect the shopper to complete payment.'
                : 'order placed',
        ], 201);
    }

    private function cartOrFail(Request $request, CartService $carts, bool $requireItems = true)
    {
        $customer = $request->user('customer');
        $cart = $carts->current($customer instanceof Customer ? $customer : null);
        if ($requireItems && $cart->items()->count() < 1) {
            abort(response()->json([
                'detail' => 'Cart is empty',
                'message' => 'Cart is empty',
                'success' => false,
            ], 500));
        }

        return $cart;
    }

    private function prereqFail(string $detail): JsonResponse
    {
        return response()->json([
            'detail' => $detail,
            'message' => $detail,
            'success' => false,
        ], 500);
    }

    /** @return array<string, mixed> */
    private function state($cart): array
    {
        $meta = is_array($cart->meta) ? $cart->meta : [];

        return is_array($meta['compat_checkout'] ?? null) ? $meta['compat_checkout'] : [];
    }

    /** @param  array<string, mixed>  $state */
    private function saveState($cart, array $state): void
    {
        $meta = is_array($cart->meta) ? $cart->meta : [];
        if ($state === []) {
            unset($meta['compat_checkout']);
        } else {
            $meta['compat_checkout'] = $state;
        }
        $cart->forceFill(['meta' => $meta])->save();
    }

    /**
     * @return array<string, string>|null
     */
    private function readAddress(Request $request, string $prefix): ?array
    {
        $map = [
            'first_name' => $prefix.'FirstName',
            'last_name' => $prefix.'LastName',
            'email' => $prefix.'Email',
            'line1' => $prefix.'Address',
            'city' => $prefix.'City',
            'state' => $prefix.'State',
            'country_code' => $prefix.'Country',
            'postal_code' => $prefix.'Postcode',
            'phone' => $prefix.'PhoneNumber',
            'company' => $prefix.'CompanyName',
        ];
        $out = [];
        foreach ($map as $wento => $compat) {
            $val = trim((string) $request->input($compat, ''));
            if ($val !== '') {
                $out[$wento] = $val;
            }
        }
        $required = ['first_name', 'email', 'line1', 'city', 'state', 'country_code', 'postal_code', 'phone'];
        foreach ($required as $key) {
            if (empty($out[$key])) {
                return null;
            }
        }
        $out['last_name'] = $out['last_name'] ?? '';
        $out['country_code'] = strtoupper($out['country_code']);

        return $out;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function addressResponse(array $state): array
    {
        $b = $state['billing'] ?? [];
        $s = $state['shipping'] ?? $b;

        return [
            'billingFirstName' => $b['first_name'] ?? null,
            'billingLastName' => $b['last_name'] ?? null,
            'billingEmail' => $b['email'] ?? null,
            'billingAddress' => $b['line1'] ?? null,
            'billingCity' => $b['city'] ?? null,
            'billingState' => $b['state'] ?? null,
            'billingCountry' => $b['country_code'] ?? null,
            'billingPostcode' => $b['postal_code'] ?? null,
            'billingPhoneNumber' => $b['phone'] ?? null,
            'billingCompanyName' => $b['company'] ?? null,
            'useForShipping' => (bool) ($state['useForShipping'] ?? true),
            'shippingFirstName' => $s['first_name'] ?? null,
            'shippingLastName' => $s['last_name'] ?? null,
            'shippingEmail' => $s['email'] ?? null,
            'shippingAddress' => $s['line1'] ?? null,
            'shippingCity' => $s['city'] ?? null,
            'shippingState' => $s['state'] ?? null,
            'shippingCountry' => $s['country_code'] ?? null,
            'shippingPostcode' => $s['postal_code'] ?? null,
            'shippingPhoneNumber' => $s['phone'] ?? null,
            'shippingCompanyName' => $s['company'] ?? null,
        ];
    }

    /**
     * @param  array<string, string>  $addr
     * @return array<string, string>
     */
    private function toWentoAddress(array $addr): array
    {
        return [
            'first_name' => (string) ($addr['first_name'] ?? ''),
            'last_name' => (string) ($addr['last_name'] ?? ''),
            'line1' => (string) ($addr['line1'] ?? ''),
            'city' => (string) ($addr['city'] ?? ''),
            'state' => (string) ($addr['state'] ?? ''),
            'postal_code' => (string) ($addr['postal_code'] ?? ''),
            'country_code' => (string) ($addr['country_code'] ?? ''),
            'phone' => (string) ($addr['phone'] ?? ''),
            'company' => (string) ($addr['company'] ?? ''),
        ];
    }

    private function requiresShipping($cart): bool
    {
        $cart->loadMissing('items.product');

        return $cart->items->contains(fn ($item) => $item->product?->requiresShipping());
    }

    private function resolvePaymentId(string $method): int
    {
        if (ctype_digit($method)) {
            return (int) $method;
        }
        $row = PaymentMethod::query()
            ->where('is_enabled', true)
            ->where(function ($q) use ($method) {
                $q->where('code', $method)->orWhere('driver', $method);
            })
            ->first();

        return (int) ($row?->id ?? 0);
    }

    private function resolveShippingId(string $method): int
    {
        // Accept "code_code" or raw code / numeric id
        if (ctype_digit($method)) {
            return (int) $method;
        }
        $code = $method;
        if (str_contains($method, '_')) {
            $parts = explode('_', $method);
            $code = $parts[0] ?? $method;
        }
        $row = ShippingMethod::query()
            ->where('is_enabled', true)
            ->where(function ($q) use ($code, $method) {
                $q->where('code', $code)
                    ->orWhere('code', $method)
                    ->orWhere('type', $code)
                    ->orWhereRaw("CONCAT(COALESCE(code, type), '_', COALESCE(code, type)) = ?", [$method]);
            })
            ->first();

        return (int) ($row?->id ?? 0);
    }
}
