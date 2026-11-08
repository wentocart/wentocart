<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Models\Product;
use App\Domain\Checkout\CheckoutContext;
use App\Domain\Checkout\Services\CartRecoveryService;
use App\Domain\Checkout\Services\CartService;
use App\Domain\Checkout\Services\CheckoutService;
use App\Domain\Localization\Money;
use App\Domain\Order\Models\Order;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Payment\Support\PaymentContext;
use App\Domain\Plugin\Support\ExtensionRuntime;
use App\Domain\Plugin\Support\ModuleBuses;
use App\Domain\Security\CustomerPassword;
use App\Domain\Settings\CartCheckoutSettings;
use App\Domain\Settings\CustomerSettings;
use App\Domain\Settings\OrderSettings;
use App\Domain\Settings\ProductSettings;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Domain\Shipping\ShippingCarrierRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckoutController extends Controller
{
    public function cart(Request $request, CartService $carts): JsonResponse
    {
        $cart = $carts->current($request->user('customer'));
        $customer = $request->user('customer');
        $shippingAmount = 0.0;
        $method = null;
        $methodId = (int) $request->input('shipping_method_id', 0);
        if ($methodId > 0) {
            $method = ShippingMethod::query()->where('is_enabled', true)->find($methodId);
            $shippingAmount = $method ? $carts->quoteShipping($cart, $method, $request->input('country_code')) : 0.0;
        }
        $totals = $carts->summary(
            $cart,
            $shippingAmount,
            $request->input('country_code'),
            $request->input('region_code') ?? $request->input('state'),
            $customer,
            $request->input('currency_code') ?: Money::currentCode(),
            true,
            $method,
        );
        $presented = $carts->present($cart, $totals);
        $locale = app()->getLocale();
        $cross = collect();
        foreach ($cart->items as $item) {
            if ($item->product) {
                $cross = $cross->concat($item->product->relationsOfType('crosssell')->limit(4)->get());
            }
        }
        $cross = $cross->unique('id')->take(8)->values();

        $payload = [
            'cart' => $presented,
            'sellers' => $presented['sellers'],
            'totals' => $totals,
            'crosssell' => $cross->map(fn (Product $p) => app(\App\Domain\Catalog\Services\CatalogPresenter::class)->product($p, $locale))->values(),
            'modules' => [
                'sidebar' => ModuleBuses::collectSlots('storefront.cart.sidebar', [
                    'subtotal' => $totals['subtotal'] ?? 0,
                    'grand_total' => $totals['grand_total'] ?? 0,
                    'due_now' => $totals['due_now'] ?? null,
                    'balance_due' => $totals['balance_due'] ?? 0,
                ]),
                'lines' => $this->cartLineSlots($cart),
            ],
        ];
        $notices = $carts->pullQuantityNotices();
        if ($notices !== []) {
            $payload['quantity_notices'] = $notices;
        }

        return response()->json($payload);
    }

    /**
     * Blocks drawn under each cart line. Keyed by cart item id.
     *
     * @return array<string, list<array<string, mixed>>>|\stdClass
     */
    protected function cartLineSlots(object $cart): array|\stdClass
    {
        $cart->loadMissing('items.product.translations');
        $locale = app()->getLocale();
        $lines = [];
        foreach ($cart->items as $item) {
            $blocks = ModuleBuses::collectSlots('storefront.cart.line', [
                'id' => (int) $item->id,
                'product_id' => (int) $item->product_id,
                'qty' => (float) $item->qty,
                'name' => (string) ($item->product?->translation($locale)?->name ?? ''),
            ]);
            if ($blocks !== []) {
                $lines[(string) $item->id] = $blocks;
            }
        }

        return $lines === [] ? new \stdClass : $lines;
    }

    public function add(Request $request, CartService $carts): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'qty' => ['nullable', 'numeric', 'min:0.001'],
            'customization' => ['nullable', 'array'],
            'subscription_plan_id' => ['nullable', 'integer'],
            'gift_card' => ['nullable', 'array'],
            'components' => ['nullable', 'array'],
            'components.*.product_id' => ['required_with:components', 'integer'],
            'components.*.qty' => ['nullable', 'numeric', 'min:0.001'],
            'combination_id' => ['nullable', 'integer', 'min:1'],
            'booking_date' => ['nullable', 'date'],
            'booking_end_date' => ['nullable', 'date'],
            'rental_duration' => ['nullable', 'integer', 'min:1'],
        ]);
        $cart = $carts->current($request->user('customer'));
        $product = Product::query()->enabled()->with(['customizationFields', 'productSubscriptionPlans.plan', 'giftCardConfig', 'components.child', 'rentalSetting', 'rentalPricingTiers', 'rentalAssets'])->findOrFail($data['product_id']);
        $files = $request->file('customization_files', []) ?: [];
        $planId = isset($data['subscription_plan_id']) && $data['subscription_plan_id'] !== ''
            ? (int) $data['subscription_plan_id']
            : null;

        if ($product->type === 'group') {
            $lines = app(\App\Domain\Catalog\Services\CompositionService::class)
                ->resolveGroupCartLines($product, $data['components'] ?? []);
            foreach ($lines as $row) {
                $child = Product::query()->enabled()->find($row['product_id']);
                if (! $child) {
                    continue;
                }
                $carts->add(
                    $cart,
                    $child,
                    $row['qty'],
                    [],
                    [],
                    null,
                    [],
                    null,
                    null,
                    (int) $product->id,
                    (float) $row['unit_price'],
                );
            }
        } elseif ($product->type === 'bundle') {
            $resolved = app(\App\Domain\Catalog\Services\CompositionService::class)
                ->resolveBundleSelection($product, $data['components'] ?? []);
            $carts->add(
                $cart,
                $product,
                $data['qty'] ?? 1,
                $data['customization'] ?? [],
                is_array($files) ? $files : [],
                $planId,
                $data['gift_card'] ?? [],
                isset($data['combination_id']) ? (int) $data['combination_id'] : null,
                $data['booking_date'] ?? null,
                null,
                (float) $resolved['unit_price'],
                $resolved['components'],
            );
        } else {
            $carts->add(
                $cart,
                $product,
                $data['qty'] ?? 1,
                $data['customization'] ?? [],
                is_array($files) ? $files : [],
                $planId,
                $data['gift_card'] ?? [],
                isset($data['combination_id']) ? (int) $data['combination_id'] : null,
                $data['booking_date'] ?? null,
                null,
                null,
                null,
                $data['booking_end_date'] ?? null,
                isset($data['rental_duration']) ? (int) $data['rental_duration'] : null,
            );
        }

        return $this->cart($request, $carts);
    }

    public function updateItem(Request $request, CartService $carts, int $item): JsonResponse
    {
        $data = $request->validate(['qty' => ['required', 'numeric', 'min:0.001']]);
        $cart = $carts->current($request->user('customer'));
        $carts->updateQty($cart, $item, (float) $data['qty']);

        return $this->cart($request, $carts);
    }

    public function removeItem(Request $request, CartService $carts, int $item): JsonResponse
    {
        $cart = $carts->current($request->user('customer'));
        $cart->items()->where('id', $item)->delete();

        return $this->cart($request, $carts);
    }

    public function coupon(Request $request, CartService $carts): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string']]);
        $cart = $carts->current($request->user('customer'));
        $carts->applyCoupon($cart, $data['code']);

        return $this->cart($request, $carts);
    }

    public function giftCard(Request $request, CartService $carts): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:64']]);
        $cart = $carts->current($request->user('customer'));
        $carts->applyGiftCard($cart, $data['code'], $request->ip());

        return $this->cart($request, $carts);
    }

    public function removeGiftCard(Request $request, CartService $carts): JsonResponse
    {
        $cart = $carts->current($request->user('customer'));
        $carts->removeGiftCard($cart);

        return $this->cart($request, $carts);
    }

    public function giftWrap(Request $request, CartService $carts): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);
        $cart = $carts->current($request->user('customer'));
        $carts->setGiftWrap($cart, (bool) $data['enabled'], $data['message'] ?? null);

        return $this->cart($request, $carts);
    }

    public function storeCredit(Request $request, CartService $carts): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $cart = $carts->current($request->user('customer'));
        $carts->setStoreCredit($cart, (bool) $data['enabled']);

        return $this->cart($request, $carts);
    }

    public function loyalty(Request $request, CartService $carts): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $cart = $carts->current($request->user('customer'));
        $carts->setLoyalty($cart, (bool) $data['enabled']);

        return $this->cart($request, $carts);
    }

    public function recover(Request $request, CartRecoveryService $recovery, CartService $carts): JsonResponse
    {
        $token = (string) ($request->input('token') ?: $request->query('token', ''));
        $customer = $request->user('customer');
        $result = $recovery->recover($token, $customer, $carts);

        if (! $result['ok']) {
            return response()->json([
                'ok' => false,
                'message' => $result['message'],
            ], 404);
        }

        $cart = $result['cart'];
        $totals = $carts->summary($cart, 0.0, null, null, $customer);
        $presented = $carts->present($cart, $totals);

        return response()->json([
            'ok' => true,
            'cart' => $presented,
            'coupon_applied' => $result['coupon_applied'],
            'message' => $result['message'],
        ]);
    }

    public function options(Request $request, CartService $carts): JsonResponse
    {
        $customer = $request->user('customer');
        $cart = $carts->current($customer);
        $cart->loadMissing('items.product');
        $context = CheckoutContext::fromCart($cart, $customer, $request);

        $sellerIds = $cart->items
            ->map(fn ($item) => (int) ($item->product?->seller_id ?? 0))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $payments = PaymentMethod::query()
            ->where('is_enabled', true)
            ->where(function ($query) use ($sellerIds) {
                $query->whereNull('seller_id');
                if ($sellerIds !== []) {
                    $query->orWhereIn('seller_id', $sellerIds);
                }
            })
            ->orderBy('position')->get()
            ->filter(fn (PaymentMethod $m) => $m->matchesContext($context))
            ->values();
        $payments = $payments->filter(function (PaymentMethod $m) use ($customer) {
            if (\App\Domain\Customer\Services\NetTerms::isInvoice($m)) {
                return \App\Domain\Customer\Services\NetTerms::eligible($customer);
            }

            return true;
        })->values();
        $payments = ModuleBuses::applyPaymentFilters($payments, $context);
        $payments = $payments->filter(fn (PaymentMethod $m) => ExtensionRuntime::isActive(data_get($m->config, 'extension')))->values();
        $payments = $payments->map(function (PaymentMethod $method) {
            $row = $method->toArray();
            $row['name'] = $method->textForLocale('name');
            $row['description'] = $method->textForLocale('description');

            return $row;
        })->values();

        $shipping = ShippingMethod::query()
            ->where('is_enabled', true)
            ->where(function ($query) use ($sellerIds) {
                $query->whereNull('seller_id');
                if ($sellerIds !== []) {
                    $query->orWhereIn('seller_id', $sellerIds);
                }
            })
            ->orderBy('position')->get()
            ->filter(fn (ShippingMethod $m) => $m->matchesContext($context))
            ->values();
        $shipping = ModuleBuses::applyShippingFilters($shipping, $context);
        $shipping = $shipping->filter(fn (ShippingMethod $m) => ExtensionRuntime::isActive(data_get($m->config, 'extension')))->values();
        $registry = app(ShippingCarrierRegistry::class);
        $syncCarriers = $request->boolean('sync_carriers', false);
        $cartSubtotal = (float) ($context['cart_subtotal'] ?? 0);
        $cartWeight = (float) ($context['cart_weight'] ?? 0);

        $quoted = $shipping->map(function (ShippingMethod $method) use ($context, $registry, $syncCarriers, $cartSubtotal, $cartWeight) {
            $row = $method->toArray();
            $driver = strtolower(trim((string) ($method->driver ?: 'offline')));
            $isCarrier = ($method->type === 'carrier') || ($driver !== '' && $driver !== 'offline');
            $row['is_carrier'] = $isCarrier;
            $row['calculating'] = false;

            if ($isCarrier && $registry->isAsyncDriver($driver) && ! $syncCarriers) {
                $cached = $registry->getCachedQuote($driver, $method, $cartSubtotal, $cartWeight, $context);
                if ($cached !== null) {
                    $row['price'] = round($cached, 2);
                    $row['calculating'] = false;
                } else {
                    $row['price'] = (float) $method->price;
                    $row['calculating'] = true;
                }
            } else {
                try {
                    $row['price'] = $method->quoteFor($cartSubtotal, $cartWeight, $context);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Shipping quote error for method ['.$method->name.']: '.$e->getMessage());
                    $row['price'] = (float) $method->price;
                }
            }

            $minDays = isset($method->rate_config['min_days']) ? (int) $method->rate_config['min_days'] : null;
            $maxDays = isset($method->rate_config['max_days']) ? (int) $method->rate_config['max_days'] : null;
            $row['min_days'] = $minDays;
            $row['max_days'] = $maxDays;
            $row['is_pickup'] = $method->type === 'local_pickup';
            if ($minDays || $maxDays) {
                $row['eta'] = $minDays && $maxDays && $minDays !== $maxDays
                    ? $minDays.'–'.$maxDays.' days'
                    : (($maxDays ?: $minDays).' days');
            }

            return $row;
        })->values();

        return response()->json([
            'payment_methods' => $payments,
            'shipping_methods' => $quoted,
            'express_checkout' => [
                'apple_pay_ready' => true,
                'google_pay_ready' => true,
                'country_code' => (string) ($context['shipping_country'] ?? 'US'),
                'currency_code' => (string) ($context['currency_code'] ?? 'USD'),
                'cart_total' => (float) ($context['cart_subtotal'] ?? 0),
            ],
            'modules' => [
                'payment' => ModuleBuses::collectSlots('storefront.checkout.payment', $context),
                'shipping' => ModuleBuses::collectSlots('storefront.checkout.shipping', $context),
                'delivery' => ModuleBuses::collectSlots('storefront.checkout.delivery', $context),
                'sidebar' => ModuleBuses::collectSlots('storefront.checkout.sidebar', $context),
            ],
        ]);
    }

    /**
     * Asynchronously quote only third-party carrier shipping methods.
     */
    public function carrierRates(Request $request, CartService $carts): JsonResponse
    {
        $customer = $request->user('customer');
        $cart = $carts->current($customer);
        $cart->loadMissing('items.product');
        $context = CheckoutContext::fromCart($cart, $customer, $request);

        $sellerIds = $cart->items
            ->map(fn ($item) => (int) ($item->product?->seller_id ?? 0))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $shipping = ShippingMethod::query()
            ->where('is_enabled', true)
            ->where(function ($query) use ($sellerIds) {
                $query->whereNull('seller_id');
                if ($sellerIds !== []) {
                    $query->orWhereIn('seller_id', $sellerIds);
                }
            })
            ->orderBy('position')->get()
            ->filter(fn (ShippingMethod $m) => $m->matchesContext($context))
            ->values();

        $shipping = ModuleBuses::applyShippingFilters($shipping, $context);
        $shipping = $shipping->filter(fn (ShippingMethod $m) => ExtensionRuntime::isActive(data_get($m->config, 'extension')))->values();
        $subtotal = (float) ($context['cart_subtotal'] ?? 0);
        $weight = (float) ($context['cart_weight'] ?? 0);

        $rates = [];
        foreach ($shipping as $method) {
            $driver = strtolower(trim((string) ($method->driver ?: 'offline')));
            $isCarrier = ($method->type === 'carrier') || ($driver !== '' && $driver !== 'offline');
            if (! $isCarrier) {
                continue;
            }

            try {
                $price = $method->quoteFor($subtotal, $weight, $context);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Async carrier quote error for ['.$method->name.']: '.$e->getMessage());
                $price = (float) $method->price;
            }

            $minDays = isset($method->rate_config['min_days']) ? (int) $method->rate_config['min_days'] : null;
            $maxDays = isset($method->rate_config['max_days']) ? (int) $method->rate_config['max_days'] : null;
            $eta = null;
            if ($minDays || $maxDays) {
                $eta = $minDays && $maxDays && $minDays !== $maxDays
                    ? $minDays.'–'.$maxDays.' days'
                    : (($maxDays ?: $minDays).' days');
            }

            $rates[] = [
                'id' => (int) $method->id,
                'price' => round((float) $price, 2),
                'formatted_price' => Money::format((float) $price),
                'eta' => $eta,
                'calculating' => false,
            ];
        }

        return response()->json([
            'rates' => $rates,
        ]);
    }

    public function place(Request $request, CartService $carts, CheckoutService $checkout): JsonResponse
    {
        if (ProductSettings::catalogMode()) {
            return response()->json(['message' => 'This shop is catalog-only.'], 422);
        }

        $customer = $request->user('customer');
        if (! $customer && ! CustomerSettings::guestCheckout() && ! CustomerSettings::registrationEnabled()) {
            return response()->json([
                'message' => 'An account is required to place an order. Registration is closed, so sign in if you already have an account.',
            ], 422);
        }

        $cart = $carts->current($customer);
        $carts->assertCartWithinLimit($cart);
        $cart->load('items.product');
        $requiresShipping = $cart->items->contains(fn ($item) => $item->product?->requiresShipping());
        $pickup = false;
        $methodId = (int) $request->input('shipping_method_id', 0);
        if ($methodId > 0) {
            $chosen = ShippingMethod::query()->find($methodId);
            $pickup = $chosen && $chosen->type === 'local_pickup';
        }

        $noteRule = OrderSettings::customerNotesEnabled()
            ? ['nullable', 'string', 'max:2000']
            : ['prohibited'];

        $needBilling = CustomerSettings::requireBillingAddress();
        $needShippingAddress = $requiresShipping && ! $pickup && CustomerSettings::requireShippingAddress();

        $rules = [
            'email' => ['required', 'email'],
            'payment_method_id' => ['required', 'integer'],
            'billing_address' => [$needBilling ? 'required' : 'nullable', 'array'],
            'billing_address.first_name' => ['required_with:billing_address', 'string'],
            'billing_address.last_name' => ['nullable', 'string'],
            'billing_address.line1' => ['required_with:billing_address', 'string'],
            'billing_address.city' => ['required_with:billing_address', 'string'],
            'billing_address.country_code' => ['required_with:billing_address', 'string'],
            'customer_note' => $noteRule,
            'accept_terms' => CartCheckoutSettings::termsRequired() ? ['accepted'] : ['nullable'],
            'payment_proof' => ['nullable', 'array'],
            'session_token' => ['nullable', 'string', 'max:64'],
            'shipping_selections' => ['nullable', 'array'],
            'shipping_selections.*.cart_item_id' => ['required_with:shipping_selections', 'integer'],
            'shipping_selections.*.shipping_method_id' => ['required_with:shipping_selections', 'integer'],
            'create_account' => ['nullable', 'boolean'],
            'password' => (! $customer && ! CustomerSettings::guestCheckout())
                ? CustomerPassword::rules()
                : ['nullable', 'string'],
        ] + PaymentContext::validationRules();

        if ($requiresShipping && $pickup) {
            $rules['shipping_method_id'] = ['required', 'integer'];
            $rules['shipping_address'] = ['nullable', 'array'];
        } elseif ($needShippingAddress) {
            $rules['shipping_method_id'] = ['required', 'integer'];
            $rules['shipping_address'] = ['required', 'array'];
            $rules['shipping_address.first_name'] = ['required', 'string'];
            $rules['shipping_address.last_name'] = ['nullable', 'string'];
            $rules['shipping_address.line1'] = ['required', 'string'];
            $rules['shipping_address.city'] = ['required', 'string'];
            $rules['shipping_address.country_code'] = ['required', 'string'];
        } elseif ($requiresShipping) {
            $rules['shipping_method_id'] = ['required', 'integer'];
            $rules['shipping_address'] = ['nullable', 'array'];
        } else {
            $rules['shipping_method_id'] = ['nullable', 'integer'];
            $rules['shipping_address'] = ['nullable', 'array'];
        }

        $billCountry = $request->input('billing_address.country_code');
        $shipCountry = $request->input('shipping_address.country_code');
        $rules = array_merge(
            $rules,
            CustomerSettings::addressFieldValidation('billing_address', is_string($billCountry) ? $billCountry : null, $needBilling),
            CustomerSettings::addressFieldValidation('shipping_address', is_string($shipCountry) ? $shipCountry : null, $needShippingAddress),
        );

        $data = $request->validate($rules, array_merge(
            CustomerSettings::addressValidationMessages('billing_address'),
            CustomerSettings::addressValidationMessages('shipping_address'),
            CustomerPassword::messages(),
        ));
        if (! $requiresShipping) {
            $data['shipping_address'] = $data['shipping_address'] ?? $data['billing_address'] ?? null;
        }
        if (empty($data['billing_address']) && ! empty($data['shipping_address'])) {
            $data['billing_address'] = $data['shipping_address'];
        }
        if (empty($data['shipping_address']) && ! empty($data['billing_address'])) {
            $data['shipping_address'] = $data['billing_address'];
        }
        $data['billing_address'] = is_array($data['billing_address'] ?? null) ? $data['billing_address'] : [];
        $data['shipping_address'] = is_array($data['shipping_address'] ?? null) ? $data['shipping_address'] : [];

        // Strip any untrusted financial / seller ownership fields if clients send them.
        unset(
            $data['seller_id'],
            $data['product_price'],
            $data['unit_price'],
            $data['subtotal'],
            $data['tax_total'],
            $data['tax_amount'],
            $data['discount_total'],
            $data['discount_amount'],
            $data['shipping_total'],
            $data['shipping_amount'],
            $data['grand_total'],
            $data['order_total'],
            $data['seller_total'],
            $data['seller_orders'],
        );

        $data['payment_context'] = PaymentContext::normalize($data['payment_context'] ?? []);

        $customer = $request->user('customer');
        $cart = $carts->current($customer);
        $result = $checkout->place($cart, $data, $customer);

        $status = ($result['order'] ?? null) ? 201 : 200;

        $signedIn = $request->user('customer') ?: Auth::guard('customer')->user();

        return response()->json([
            'checkout_lifecycle' => $result['checkout_lifecycle'] ?? 'order_first',
            'session_token' => $result['session_token'] ?? null,
            'customer' => $signedIn?->toStorefrontArray(),
            'order' => $result['order'],
            'orders' => $result['orders'] ?? (($result['order'] ?? null) ? [$result['order']] : []),
            'checkout_reference' => $result['order']->number ?? ($result['checkout_group']->reference ?? null),
            'checkout_group' => $result['checkout_group'] ?? null,
            'payment' => $result['payment']->toArray(),
            'seller_orders' => $result['seller_orders'] ?? [],
        ], $status);
    }

    public function finalize(Request $request, \App\Domain\Payment\Services\PaymentCheckoutService $payments): JsonResponse
    {
        $data = $request->validate([
            'session_token' => ['required', 'string', 'max:64'],
            'payment_proof' => ['required', 'array'],
        ]);

        $result = $payments->finalize($data['session_token'], $data['payment_proof']);

        return response()->json([
            'checkout_lifecycle' => $result['checkout_lifecycle'] ?? 'payment_first',
            'session_token' => $result['session_token'] ?? $data['session_token'],
            'order' => $result['order'],
            'payment' => is_array($result['payment'] ?? null) ? $result['payment'] : [],
            'seller_orders' => $result['seller_orders'] ?? [],
        ], 201);
    }

    public function thanks(Request $request, string $number): JsonResponse
    {
        $customer = $request->user('customer');
        $query = Order::query()
            ->with(['items.product.seller', 'sellerOrders.seller', 'sellerOrders.items', 'paymentMethod', 'shippingMethod'])
            ->where('number', $number);
        if ($customer) {
            $query->where(function ($q) use ($customer) {
                $q->where('customer_id', $customer->id)->orWhere('email', $customer->email);
            });
        } else {
            $last = $request->session()->get('wento.last_order');
            $email = $request->session()->get('wento.last_order_email');
            $lastRef = $request->session()->get('wento.last_checkout_reference');
            if ($last !== $number) {
                $sibling = Order::query()->where('number', $number)->where('email', $email);
                if (is_string($lastRef) && $lastRef !== '') {
                    $sibling->where('checkout_reference', $lastRef);
                } else {
                    abort(404);
                }
                if (! $sibling->exists()) {
                    abort(404);
                }
            }
            $query->where('email', $email);
        }

        $order = $query->firstOrFail();
        $order->loadMissing(['items.product', 'paymentMethod', 'shippingMethod']);

        return response()->json([
            'order' => $order,
            'seller_orders' => $order->sellerOrders->map(static function ($sellerOrder) {
                return [
                    'id' => (int) $sellerOrder->id,
                    'seller_id' => (int) $sellerOrder->seller_id,
                    'shop_name' => (string) ($sellerOrder->seller?->shop_name ?: 'Seller'),
                    'shop_slug' => $sellerOrder->seller?->shop_slug,
                    'status' => (string) $sellerOrder->status,
                    'subtotal' => (float) $sellerOrder->subtotal,
                    'tax_total' => (float) $sellerOrder->tax_total,
                    'discount_total' => (float) $sellerOrder->discount_total,
                    'shipping_total' => (float) $sellerOrder->shipping_total,
                    'grand_total' => (float) $sellerOrder->grand_total,
                    'item_count' => $sellerOrder->items->count(),
                ];
            })->values()->all(),
        ]);
    }

    public function lookup(Request $request, \App\Domain\Order\InvoiceDocument $invoices): JsonResponse
    {
        if (! \App\Domain\Settings\CommunityFeatures::guestOrderLookup()) {
            abort(404);
        }
        $data = $request->validate([
            'number' => ['required', 'string', 'max:64'],
            'email' => ['required', 'email', 'max:190'],
            'postal_code' => ['required', 'string', 'max:32'],
        ]);
        $order = Order::query()
            ->with(['items', 'paymentMethod', 'shippingMethod'])
            ->where('number', trim($data['number']))
            ->whereRaw('LOWER(email) = ?', [strtolower(trim($data['email']))])
            ->first();
        if (! $order || ! $this->guestPostalMatches($order, $data['postal_code'])) {
            abort(404);
        }

        return response()->json([
            'order' => $this->guestOrderPayload($order, $invoices),
        ]);
    }

    public function guestInvoice(Request $request, \App\Domain\Order\InvoiceDocument $invoices): \Illuminate\Http\Response
    {
        if (! \App\Domain\Settings\CommunityFeatures::guestOrderLookup()) {
            abort(404);
        }
        $data = $request->validate([
            'number' => ['required', 'string', 'max:64'],
            'email' => ['required', 'email', 'max:190'],
            'postal_code' => ['required', 'string', 'max:32'],
        ]);
        $order = Order::query()
            ->with('items')
            ->where('number', trim($data['number']))
            ->whereRaw('LOWER(email) = ?', [strtolower(trim($data['email']))])
            ->first();
        if (! $order || ! $this->guestPostalMatches($order, $data['postal_code'])) {
            abort(404);
        }
        $invoices->ensurePaid($order);

        return response($invoices->html($order), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="invoice-'.$order->number.'.html"',
        ]);
    }

    /**
     * Same detail a signed-in customer sees, without account actions or payment secrets.
     *
     * @return array<string, mixed>
     */
    protected function guestOrderPayload(Order $order, \App\Domain\Order\InvoiceDocument $invoices): array
    {
        $order->loadMissing([
            'items.product',
            'paymentMethod',
            'shippingMethod',
            'shipments.shippingMethod',
            'sellerOrders.seller',
            'sellerOrders.shipments',
            'checkoutGroup.orders.shippingMethod',
        ]);
        $locale = app()->getLocale();
        $presenter = app(\App\Domain\Catalog\Services\CatalogPresenter::class);
        $preorders = app(\App\Domain\Catalog\Services\PreorderService::class);
        $fulfillment = app(\App\Domain\Marketplace\Services\SellerFulfillmentService::class);

        return [
            'number' => $order->number,
            'email' => $order->email,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'currency_code' => $order->currency_code,
            'created_at' => optional($order->created_at)?->toIso8601String(),
            'customer_note' => $order->customer_note,
            'subtotal' => (float) $order->subtotal,
            'shipping_total' => (float) $order->shipping_total,
            'discount_total' => (float) $order->discount_total,
            'tax_total' => (float) $order->tax_total,
            'fee_total' => (float) $order->fee_total,
            'grand_total' => (float) $order->grand_total,
            'refunded_total' => (float) $order->refunded_total,
            'payment_method' => $order->paymentMethod ? ['name' => $order->paymentMethod->textForLocale('name')] : null,
            'shipping_method' => $order->shippingMethod ? ['name' => $order->shippingMethod->name] : null,
            'shipping_address' => $this->guestAddress($order->shipping_address),
            'billing_address' => $this->guestAddress($order->billing_address),
            'can_invoice' => $invoices->isAvailable($order),
            'checkout_reference' => $order->checkout_reference,
            'checkout_orders' => $order->checkout_group_id
                ? $order->checkoutGroup?->orders->map(fn ($row) => [
                    'number' => $row->number,
                    'shipping_method' => $row->shippingMethod?->name,
                    'grand_total' => (float) $row->grand_total,
                    'status' => $row->status,
                    'is_current' => (int) $row->id === (int) $order->id,
                ])->values()->all()
                : [],
            'items' => $order->items->map(function ($item) use ($presenter, $locale, $preorders) {
                $product = null;
                if ($item->product) {
                    $card = $presenter->product($item->product, $locale);
                    $product = [
                        'id' => $card['id'] ?? $item->product_id,
                        'name' => $card['name'] ?? $item->name,
                        'slug' => $card['slug'] ?? null,
                        'url' => $card['url'] ?? null,
                        'cover' => $card['cover'] ?? null,
                        'cover_url' => $card['cover_url'] ?? null,
                    ];
                }
                $preorder = null;
                if (is_array($item->preorder_data) && ! empty($item->preorder_data['enabled'])) {
                    $preorder = $preorders->settleLine($item);
                }

                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'sku' => $item->sku,
                    'qty' => (float) $item->qty,
                    'unit_price' => (float) $item->unit_price,
                    'total' => (float) $item->total,
                    'booking_date' => optional($item->booking_date)?->toDateString(),
                    'booking_end_date' => optional($item->booking_end_date)?->toDateString(),
                    'rental_duration' => $item->rental_duration ? (int) $item->rental_duration : null,
                    'rental_data' => is_array($item->rental_data) ? [
                        'deposit' => (float) ($item->rental_data['deposit'] ?? 0),
                        'deposit_status' => $item->rental_data['deposit_status'] ?? null,
                    ] : null,
                    'preorder_data' => is_array($item->preorder_data) ? $item->preorder_data : null,
                    'preorder' => $preorder,
                    'product' => $product,
                ];
            })->values()->all(),
            'shipments' => $order->shipments
                ->where('status', '!=', \App\Domain\Shipping\Models\Shipment::STATUS_CANCELLED)
                ->map(fn ($shipment) => $fulfillment->presentShipment($shipment))
                ->values()
                ->all(),
            'seller_orders' => $order->sellerOrders->map(function ($sellerOrder) use ($fulfillment) {
                $active = $sellerOrder->shipments
                    ->where('status', '!=', \App\Domain\Shipping\Models\Shipment::STATUS_CANCELLED)
                    ->sortByDesc('id')
                    ->first();

                return [
                    'id' => (int) $sellerOrder->id,
                    'shop_name' => (string) ($sellerOrder->seller?->shop_name ?: 'Seller'),
                    'status' => (string) $sellerOrder->status,
                    'grand_total' => (float) $sellerOrder->grand_total,
                    'shipment' => $active ? $fulfillment->presentShipment($active) : null,
                ];
            })->values()->all(),
            'returns' => \App\Domain\Order\Models\ShopReturn::query()
                ->where('order_id', $order->id)
                ->latest('id')
                ->get()
                ->map(fn ($row) => [
                    'number' => $row->number,
                    'status' => $row->status,
                    'refund_total' => (float) $row->refund_total,
                ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $address
     * @return array<string, mixed>|null
     */
    protected function guestAddress(?array $address): ?array
    {
        if (! is_array($address) || $address === []) {
            return null;
        }
        $keys = ['first_name', 'last_name', 'company', 'phone', 'line1', 'line2', 'city', 'state', 'region_code', 'postal_code', 'country_code'];
        $out = [];
        foreach ($keys as $key) {
            if (! array_key_exists($key, $address) || $address[$key] === null || $address[$key] === '') {
                continue;
            }
            $out[$key] = $address[$key];
        }

        return $out === [] ? null : $out;
    }

    protected function guestPostalMatches(Order $order, string $postalCode): bool
    {
        $given = strtolower(preg_replace('/\s+/', '', trim($postalCode)) ?? '');
        if ($given === '') {
            return false;
        }
        foreach ([$order->shipping_address, $order->billing_address] as $addr) {
            if (! is_array($addr)) {
                continue;
            }
            $expected = strtolower(preg_replace('/\s+/', '', trim((string) ($addr['postal_code'] ?? ''))) ?? '');
            if ($expected !== '' && hash_equals($expected, $given)) {
                return true;
            }
        }

        return false;
    }
}
