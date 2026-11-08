<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Catalog\Models\Product;
use App\Domain\Settings\OrderSettings;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Services\CompositionService;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Promo\DiscountContext;
use App\Domain\Promo\DiscountEngine;
use App\Domain\Promo\Models\DiscountRule;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Models\Admin;
use GraphQL\Error\UserError;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 */
final class GraphqlAdminOrderComposerDraft
{
    private const TTL_SECONDS = 7200;

    /**
     * @return array<string, mixed>
     */
    public static function create(int $customerId): array
    {
        $admin = self::assertAdmin();
        $customer = Customer::query()->find($customerId);
        if (! $customer) {
            throw new UserError('Customer not found.');
        }
        $cartId = (string) Str::ulid();
        $cart = [
            'cart_id' => $cartId,
            'customer_id' => (int) $customer->id,
            'admin_id' => (int) $admin->id,
            'items' => [],
            'billing_address' => null,
            'shipping_address' => null,
            'shipping_method_code' => null,
            'payment_method_code' => null,
            'coupon_code' => null,
            'discount_rule_id' => null,
            'customer_note' => null,
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ];
        self::store($admin->id, $cart);

        return self::map($cart);
    }

    /**
     * Accepts order number or numeric order id (REST /orders/{id}/reorder).
     *
     * @return array<string, mixed>
     */
    public static function createFromOrder(string $orderNumberOrId): array
    {
        $admin = self::assertAdmin();
        if (! OrderSettings::adminReorderEnabled()) {
            throw new UserError('Reorder by admin is disabled in store settings.');
        }
        $key = trim($orderNumberOrId);
        $source = Order::query()->with('items')->where('number', $key)->first();
        if (! $source && ctype_digit($key)) {
            $source = Order::query()->with('items')->whereKey((int) $key)->first();
        }
        if (! $source) {
            throw new UserError('Order not found.');
        }
        if (! $source->customer_id) {
            throw new UserError('Reorder is not supported for guest orders.');
        }
        $customer = Customer::query()->find((int) $source->customer_id);
        if (! $customer) {
            throw new UserError('Customer not found.');
        }

        $cartId = (string) Str::ulid();
        $items = [];
        foreach ($source->items as $item) {
            $productId = (int) ($item->product_id ?? 0);
            $qty = (float) ($item->qty ?? 0);
            if ($productId < 1 || $qty <= 0) {
                continue;
            }
            if (! Product::query()->whereKey($productId)->where('is_enabled', true)->exists()) {
                continue;
            }
            $merged = false;
            foreach ($items as &$line) {
                if ((int) $line['product_id'] === $productId) {
                    $line['quantity'] = (float) $line['quantity'] + $qty;
                    $merged = true;
                    break;
                }
            }
            unset($line);
            if (! $merged) {
                $items[] = [
                    'item_id' => (string) Str::ulid(),
                    'product_id' => $productId,
                    'quantity' => $qty,
                ];
            }
        }
        if ($items === []) {
            throw new UserError('One or more items in this order are no longer available for purchase.');
        }

        $cart = [
            'cart_id' => $cartId,
            'customer_id' => (int) $customer->id,
            'admin_id' => (int) $admin->id,
            'items' => $items,
            'billing_address' => is_array($source->billing_address) ? $source->billing_address : null,
            'shipping_address' => is_array($source->shipping_address) ? $source->shipping_address : null,
            'shipping_method_code' => null,
            'payment_method_code' => null,
            'coupon_code' => null,
            'discount_rule_id' => null,
            'customer_note' => 'Reorder of '.$source->number,
            'source_order_number' => $source->number,
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ];
        self::store($admin->id, $cart);

        return self::map($cart);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $cartId): ?array
    {
        $admin = self::assertAdmin();
        $cart = self::load($admin->id, $cartId);
        if (! $cart) {
            return null;
        }

        return self::map($cart);
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param  list<array{product_id: int, qty: float|int}>  $groupedQuantities
     * @param  list<array{product_id: int, qty: float|int}>  $bundleOptions
     * @return array<string, mixed>
     */
    public static function addItem(
        string $cartId,
        int $productId,
        float $quantity,
        ?int $selectedVariantOption = null,
        array $groupedQuantities = [],
        array $bundleOptions = [],
    ): array {
        $admin = self::assertAdmin();
        $cart = self::require($admin->id, $cartId);
        if ($quantity <= 0) {
            throw new UserError('quantity must be greater than zero.');
        }
        $product = Product::query()->where('is_enabled', true)->find($productId);
        if (! $product) {
            throw new UserError('Product was not found or is disabled.');
        }
        if ($product->isService() || $product->isRental()) {
            throw new UserError(
                'Booking products cannot be added to an admin draft order. Booking purchases must be made through the customer storefront.'
            );
        }
        if ($product->type === 'group') {
            return self::addGroupedProduct($admin->id, $cart, $product, $groupedQuantities, $quantity);
        }
        if ($product->type === 'bundle') {
            return self::addBundleProduct($admin->id, $cart, $product, $bundleOptions, $quantity);
        }
        $combinationId = null;
        if ($product->isCombination()) {
            $variantId = (int) ($selectedVariantOption ?? 0);
            if ($variantId < 1) {
                throw new UserError('selectedVariantOption is required for combination products.');
            }
            $variant = ProductVariant::query()
                ->where('is_enabled', true)
                ->where('product_id', $product->id)
                ->whereKey($variantId)
                ->first();
            if (! $variant) {
                throw new UserError('Selected configurable option was not found for this product.');
            }
            $combinationId = (int) $variant->id;
            if ($variant->manage_stock && (float) ($variant->stock_qty ?? 0) < $quantity) {
                throw new UserError('Product is out of stock for the requested quantity.');
            }
        } elseif ($selectedVariantOption !== null) {
            throw new UserError('selectedVariantOption is only valid for combination products.');
        } elseif ($product->manage_stock && (float) ($product->stock_qty ?? 0) < $quantity) {
            throw new UserError('Product is out of stock for the requested quantity.');
        }
        $items = is_array($cart['items'] ?? null) ? $cart['items'] : [];
        $merged = false;
        foreach ($items as &$line) {
            if (
                (int) ($line['product_id'] ?? 0) === $productId
                && (int) ($line['combination_id'] ?? 0) === (int) ($combinationId ?? 0)
            ) {
                $line['quantity'] = (float) ($line['quantity'] ?? 0) + $quantity;
                $merged = true;
                break;
            }
        }
        unset($line);
        if (! $merged) {
            $items[] = [
                'item_id' => (string) Str::ulid(),
                'product_id' => $productId,
                'combination_id' => $combinationId,
                'quantity' => $quantity,
            ];
        }
        $cart['items'] = array_values($items);
        $cart['updated_at'] = now()->toIso8601String();
        self::store($admin->id, $cart);

        return self::map($cart);
    }

    /**
     *
     * @param  array<string, mixed>  $cart
     * @param  list<array{product_id: int, qty: float|int}>  $groupedQuantities
     * @return array<string, mixed>
     */
    protected static function addGroupedProduct(
        int $adminId,
        array $cart,
        Product $group,
        array $groupedQuantities,
        float $packQuantity,
    ): array {
        $posted = $groupedQuantities;
        try {
            $resolved = app(CompositionService::class)->resolveGroupCartLines($group, $posted);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $messages = collect($e->errors())->flatten()->filter()->values()->all();
            throw new UserError($messages[0] ?? 'Grouped product could not be added.');
        }
        if ($resolved === []) {
            throw new UserError('No group components were selected.');
        }
        $packQuantity = max(1, $packQuantity);
        foreach ($resolved as $row) {
            $childId = (int) ($row['product_id'] ?? 0);
            $qty = ((float) ($row['qty'] ?? 1)) * $packQuantity;
            if ($childId < 1 || $qty <= 0) {
                continue;
            }
            $mapped = self::addItem(
                (string) $cart['cart_id'],
                $childId,
                $qty,
            );
            $cart = self::require($adminId, (string) $cart['cart_id']);
            // Keep last mapped return for response.
            unset($mapped);
        }

        return self::map(self::require($adminId, (string) $cart['cart_id']));
    }

    /**
     *
     * @param  array<string, mixed>  $cart
     * @param  list<array{product_id: int, qty: float|int}>  $bundleOptions
     * @return array<string, mixed>
     */
    protected static function addBundleProduct(
        int $adminId,
        array $cart,
        Product $bundle,
        array $bundleOptions,
        float $packQuantity,
    ): array {
        try {
            $resolved = app(CompositionService::class)->resolveBundleSelection($bundle, $bundleOptions);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $messages = collect($e->errors())->flatten()->filter()->values()->all();
            throw new UserError($messages[0] ?? 'Bundle product could not be added.');
        }
        $components = is_array($resolved['components'] ?? null) ? $resolved['components'] : [];
        if ($components === []) {
            throw new UserError('No bundle components were selected.');
        }
        $packQuantity = max(1, $packQuantity);
        foreach ($components as $row) {
            $childId = (int) ($row['product_id'] ?? 0);
            $qty = ((float) ($row['qty'] ?? 1)) * $packQuantity;
            if ($childId < 1 || $qty <= 0) {
                continue;
            }
            self::addItem((string) $cart['cart_id'], $childId, $qty);
        }

        return self::map(self::require($adminId, (string) $cart['cart_id']));
    }

    /**
     * @return array<string, mixed>
     */
    public static function updateItem(string $cartId, string $itemId, float $quantity): array
    {
        $admin = self::assertAdmin();
        $cart = self::require($admin->id, $cartId);
        if ($quantity <= 0) {
            throw new UserError('quantity must be greater than zero.');
        }
        $items = is_array($cart['items'] ?? null) ? $cart['items'] : [];
        $found = false;
        foreach ($items as &$line) {
            if ((string) ($line['item_id'] ?? '') === $itemId) {
                $line['quantity'] = $quantity;
                $found = true;
                break;
            }
        }
        unset($line);
        if (! $found) {
            throw new UserError('Composer draft item not found.');
        }
        $cart['items'] = array_values($items);
        $cart['updated_at'] = now()->toIso8601String();
        self::store($admin->id, $cart);

        return self::map($cart);
    }

    /**
     * @return array<string, mixed>
     */
    public static function removeItem(string $cartId, string $itemId): array
    {
        $admin = self::assertAdmin();
        $cart = self::require($admin->id, $cartId);
        $items = is_array($cart['items'] ?? null) ? $cart['items'] : [];
        $before = count($items);
        $items = array_values(array_filter(
            $items,
            fn ($line) => (string) ($line['item_id'] ?? '') !== $itemId
        ));
        if (count($items) === $before) {
            throw new UserError('Composer draft item not found.');
        }
        $cart['items'] = $items;
        $cart['updated_at'] = now()->toIso8601String();
        self::store($admin->id, $cart);

        return self::map($cart);
    }

    /**
     * @param  array<string, mixed>  $billing
     * @param  array<string, mixed>|null  $shipping
     * @return array<string, mixed>
     */
    public static function saveAddresses(string $cartId, array $billing, ?array $shipping = null, bool $useForShipping = true): array
    {
        $admin = self::assertAdmin();
        $cart = self::require($admin->id, $cartId);
        $billingNorm = self::normalizeAddress($billing, 'Billing');
        $shippingNorm = $useForShipping || $shipping === null
            ? $billingNorm
            : self::normalizeAddress($shipping, 'Shipping');
        $cart['billing_address'] = $billingNorm;
        $cart['shipping_address'] = $shippingNorm;
        $cart['shipping_method_code'] = null;
        $cart['payment_method_code'] = null;
        $cart['updated_at'] = now()->toIso8601String();
        self::store($admin->id, $cart);

        return self::map($cart);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function shippingMethods(string $cartId): array
    {
        $admin = self::assertAdmin();
        $cart = self::require($admin->id, $cartId);
        self::assertAddresses($cart);

        return ShippingMethod::query()
            ->where('is_enabled', true)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (ShippingMethod $m) => [
                'code' => (string) $m->code,
                'name' => (string) $m->name,
                'price' => $m->price !== null ? (float) $m->price : 0.0,
                'type' => (string) ($m->type ?? ''),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function setShippingMethod(string $cartId, string $code): array
    {
        $admin = self::assertAdmin();
        $cart = self::require($admin->id, $cartId);
        self::assertAddresses($cart);
        $code = trim($code);
        $method = ShippingMethod::query()->where('is_enabled', true)->where('code', $code)->first();
        if (! $method) {
            throw new UserError('Shipping method not found or disabled.');
        }
        $cart['shipping_method_code'] = $method->code;
        $cart['payment_method_code'] = null;
        $cart['updated_at'] = now()->toIso8601String();
        self::store($admin->id, $cart);

        return self::map($cart);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function paymentMethods(string $cartId): array
    {
        $admin = self::assertAdmin();
        $cart = self::require($admin->id, $cartId);
        if (empty($cart['shipping_method_code'])) {
            throw new UserError('Select a shipping method before listing payment methods.');
        }

        return PaymentMethod::query()
            ->where('is_enabled', true)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (PaymentMethod $m) => [
                'code' => (string) $m->code,
                'name' => (string) $m->name,
                'driver' => (string) ($m->driver ?? ''),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function setPaymentMethod(string $cartId, string $code): array
    {
        $admin = self::assertAdmin();
        $cart = self::require($admin->id, $cartId);
        if (empty($cart['shipping_method_code'])) {
            throw new UserError('Select a shipping method before setting payment.');
        }
        $method = self::resolvePaymentMethod(trim($code));
        if (! $method) {
            throw new UserError('Payment method not found or disabled.');
        }
        $cart['payment_method_code'] = $method->code;
        $cart['updated_at'] = now()->toIso8601String();
        self::store($admin->id, $cart);

        return self::map($cart);
    }

    /**
     *
     * @return array<string, mixed>
     */
    public static function applyCoupon(string $cartId, string $code): array
    {
        $admin = self::assertAdmin();
        $cart = self::require($admin->id, $cartId);
        $items = is_array($cart['items'] ?? null) ? $cart['items'] : [];
        if ($items === []) {
            throw new UserError('Add at least one item before applying a coupon.');
        }
        $code = trim($code);
        if ($code === '') {
            throw new UserError('Coupon code is required.');
        }
        $engine = app(DiscountEngine::class);
        $rule = $engine->findLiveCoupon($code);
        if (! $rule) {
            throw new UserError('Coupon not found.');
        }
        $existing = strtoupper((string) ($cart['coupon_code'] ?? ''));
        if ($existing !== '' && $existing === strtoupper((string) $rule->code)) {
            throw new UserError('Coupon already applied.');
        }
        $customer = Customer::query()->find((int) $cart['customer_id']);
        [$lines, $subtotal] = self::pricedLines($items);
        $context = DiscountContext::current($customer, cartSubtotal: $subtotal);
        if (! $engine->applies($rule, $context, $lines)) {
            throw new UserError('This coupon cannot be applied.');
        }
        $cart['coupon_code'] = (string) $rule->code;
        $cart['discount_rule_id'] = (int) $rule->id;
        $cart['updated_at'] = now()->toIso8601String();
        self::store($admin->id, $cart);

        return self::map($cart);
    }

    /**
     *
     * @return array<string, mixed>
     */
    public static function removeCoupon(string $cartId): array
    {
        $admin = self::assertAdmin();
        $cart = self::require($admin->id, $cartId);
        $cart['coupon_code'] = null;
        $cart['discount_rule_id'] = null;
        $cart['updated_at'] = now()->toIso8601String();
        self::store($admin->id, $cart);

        return self::map($cart);
    }

    /**
     * @return array<string, mixed>
     */
    public static function place(string $cartId, ?string $customerNote = null, bool $requireComplete = false): array
    {
        $admin = self::assertAdmin();
        $cart = self::require($admin->id, $cartId);
        $items = is_array($cart['items'] ?? null) ? $cart['items'] : [];
        if ($items === []) {
            throw new UserError($requireComplete ? 'Cart empty.' : 'Add at least one item before placing the composer draft.');
        }

        $customer = Customer::query()->find((int) $cart['customer_id']);
        if (! $customer) {
            throw new UserError('Customer not found.');
        }

        if (empty($cart['billing_address'])) {
            if ($requireComplete) {
                throw new UserError('Addresses missing.');
            }
            $cart['billing_address'] = self::addressFromCustomer($customer, 'billing');
            $cart['shipping_address'] = self::addressFromCustomer($customer, 'shipping');
        }
        if (empty($cart['shipping_method_code'])) {
            if ($requireComplete) {
                throw new UserError('Shipping method missing.');
            }
            $ship = ShippingMethod::query()->where('is_enabled', true)->orderBy('position')->orderBy('id')->first();
            if (! $ship) {
                throw new UserError('No enabled shipping methods are available.');
            }
            $cart['shipping_method_code'] = $ship->code;
        }
        if (empty($cart['payment_method_code'])) {
            if ($requireComplete) {
                throw new UserError('Payment method missing.');
            }
            $pay = PaymentMethod::query()
                ->where('is_enabled', true)
                ->whereIn('code', self::adminPlacePaymentCodes())
                ->orderBy('position')
                ->orderBy('id')
                ->first()
                ?? PaymentMethod::query()->where('is_enabled', true)->orderBy('position')->orderBy('id')->first();
            if (! $pay) {
                throw new UserError('No enabled payment methods are available.');
            }
            $cart['payment_method_code'] = $pay->code;
        }

        if ($requireComplete && ! self::isAdminPlacePaymentAllowed((string) $cart['payment_method_code'])) {
            throw new UserError(
                'Payment method unsupported for order composer. Use cod or bank_transfer.'
            );
        }

        $shipMethod = ShippingMethod::query()->where('code', $cart['shipping_method_code'])->first();
        $payMethod = PaymentMethod::query()->where('code', $cart['payment_method_code'])->first();

        $mapped = self::map($cart);
        if ($requireComplete) {
            $minError = OrderSettings::assertAdminPlaceMinimum(
                (float) ($mapped['subtotal'] ?? 0),
                (float) ($mapped['discount_total'] ?? 0),
            );
            if ($minError !== null) {
                throw new UserError($minError);
            }
        }
        $payload = [
            'customerId' => (string) $cart['customer_id'],
            'items' => array_map(fn ($line) => [
                'productId' => (string) $line['product_id'],
                'quantity' => (float) $line['quantity'],
                'combinationId' => isset($line['combination_id']) && $line['combination_id']
                    ? (string) $line['combination_id']
                    : null,
            ], $items),
            'customerNote' => $customerNote !== null && trim($customerNote) !== ''
                ? trim($customerNote)
                : (string) ($cart['customer_note'] ?? 'Placed from admin order composer'),
            'billingAddress' => $cart['billing_address'],
            'shippingAddress' => $cart['shipping_address'] ?? $cart['billing_address'],
            'shippingMethodId' => $shipMethod?->id,
            'paymentMethodId' => $payMethod?->id,
            'shippingTotal' => $shipMethod && $shipMethod->price !== null ? (float) $shipMethod->price : 0.0,
            'discountTotal' => (float) ($mapped['discount_total'] ?? 0),
            'couponCode' => $mapped['coupon_code'] ?? null,
        ];
        $order = GraphqlAdminOrderComposer::run($payload);
        Cache::forget(self::key($admin->id, $cartId));

        return $order;
    }

    protected static function assertAdmin(): Admin
    {
        $admin = auth('admin')->user();
        if (! $admin instanceof Admin || ! $admin->hasPermission('orders.manage')) {
            throw new UserError('Forbidden.');
        }

        return $admin;
    }

    /**
     * Offline payment codes allowed when placing from the order composer.
     *
     * @return list<string>
     */
    public static function adminPlacePaymentCodes(): array
    {
        return ['cod', 'bank_transfer'];
    }

    public static function isAdminPlacePaymentAllowed(string $code): bool
    {
        return in_array(strtolower(trim($code)), self::adminPlacePaymentCodes(), true);
    }

    protected static function resolvePaymentMethod(string $code): ?PaymentMethod
    {
        $raw = strtolower(trim($code));
        if ($raw === '') {
            return null;
        }

        return PaymentMethod::query()
            ->where('is_enabled', true)
            ->where('code', $raw)
            ->orderBy('position')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $cart
     */
    protected static function assertAddresses(array $cart): void
    {
        if (empty($cart['billing_address']) || empty($cart['shipping_address'])) {
            throw new UserError('Addresses must be saved before selecting a shipping method.');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected static function normalizeAddress(array $input, string $label): array
    {
        $first = trim((string) ($input['firstName'] ?? $input['first_name'] ?? ''));
        $last = trim((string) ($input['lastName'] ?? $input['last_name'] ?? ''));
        $line1 = trim((string) ($input['line1'] ?? $input['address1'] ?? ''));
        if (isset($input['address']) && is_array($input['address'])) {
            $line1 = trim((string) ($input['address'][0] ?? $line1));
        }
        $city = trim((string) ($input['city'] ?? ''));
        $country = strtoupper(trim((string) ($input['countryCode'] ?? $input['country'] ?? '')));
        $phone = trim((string) ($input['phone'] ?? ''));
        if ($first === '' || $line1 === '' || $city === '' || $country === '') {
            throw new UserError($label.' address requires firstName, line1, city, and countryCode.');
        }

        return [
            'first_name' => $first,
            'last_name' => $last,
            'phone' => $phone,
            'line1' => $line1,
            'line2' => trim((string) ($input['line2'] ?? $input['address2'] ?? '')) ?: null,
            'city' => $city,
            'state' => trim((string) ($input['state'] ?? '')) ?: null,
            'postal_code' => trim((string) ($input['postalCode'] ?? $input['postcode'] ?? '')) ?: null,
            'country_code' => $country,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function addressFromCustomer(Customer $customer, string $type): array
    {
        $address = $type === 'shipping'
            ? $customer->addresses()->where('is_default_shipping', true)->first()
            : $customer->addresses()->where('is_default_billing', true)->first();
        $address ??= $customer->addresses()->first();
        $first = trim((string) ($address?->first_name ?: $customer->first_name ?: $customer->name ?: 'Customer'));
        $last = trim((string) ($address?->last_name ?: $customer->last_name ?: ''));

        return [
            'first_name' => $first !== '' ? $first : 'Customer',
            'last_name' => $last,
            'phone' => (string) ($address?->phone ?: $customer->phone ?: ''),
            'line1' => (string) ($address?->line1 ?: 'Address pending'),
            'line2' => $address?->line2,
            'city' => (string) ($address?->city ?: 'City'),
            'state' => $address?->state,
            'postal_code' => $address?->postal_code,
            'country_code' => strtoupper((string) ($address?->country_code ?: 'US')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function require(int $adminId, string $cartId): array
    {
        $cart = self::load($adminId, $cartId);
        if (! $cart) {
            throw new UserError('Composer draft not found.');
        }

        return $cart;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected static function load(int $adminId, string $cartId): ?array
    {
        $raw = Cache::get(self::key($adminId, $cartId));
        if (! is_array($raw) || empty($raw['cart_id'])) {
            return null;
        }

        return $raw;
    }

    /**
     * @param  array<string, mixed>  $cart
     */
    protected static function store(int $adminId, array $cart): void
    {
        Cache::put(self::key($adminId, (string) $cart['cart_id']), $cart, self::TTL_SECONDS);
    }

    protected static function key(int $adminId, string $cartId): string
    {
        return 'admin_draft_cart:'.$adminId.':'.$cartId;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{0: list<array{product: ?Product, qty: int, line_total: float}>, 1: float}
     */
    protected static function pricedLines(array $items): array
    {
        $lines = [];
        $subtotal = 0.0;
        foreach ($items as $line) {
            $product = Product::query()->find((int) ($line['product_id'] ?? 0));
            $qty = (float) ($line['quantity'] ?? 0);
            $price = $product ? (float) ($product->price ?? 0) : 0.0;
            $combinationId = (int) ($line['combination_id'] ?? 0);
            if ($combinationId > 0) {
                $variant = ProductVariant::query()->find($combinationId);
                if ($variant) {
                    $price = (float) ($variant->price ?? $price);
                }
            }
            $total = round($price * $qty, 2);
            $subtotal += $total;
            $lines[] = [
                'product' => $product,
                'qty' => (int) max(1, (int) ceil($qty)),
                'line_total' => $total,
            ];
        }

        return [$lines, round($subtotal, 2)];
    }

    protected static function discountTotalFor(array $cart, float $subtotal): float
    {
        $ruleId = (int) ($cart['discount_rule_id'] ?? 0);
        if ($ruleId < 1 || $subtotal <= 0) {
            return 0.0;
        }
        $rule = DiscountRule::query()->find($ruleId);
        if (! $rule) {
            return 0.0;
        }

        return round(min($subtotal, $rule->reductionFor($subtotal)), 2);
    }

    /**
     * @param  array<string, mixed>  $cart
     * @return array<string, mixed>
     */
    protected static function map(array $cart): array
    {
        $locale = app()->getLocale();
        $lines = [];
        $subtotal = 0.0;
        foreach (is_array($cart['items'] ?? null) ? $cart['items'] : [] as $line) {
            $product = Product::query()->with('translations')->find((int) ($line['product_id'] ?? 0));
            $qty = (float) ($line['quantity'] ?? 0);
            $price = $product ? (float) ($product->price ?? 0) : 0.0;
            $sku = $product?->sku;
            $combinationId = (int) ($line['combination_id'] ?? 0);
            if ($combinationId > 0) {
                $variant = ProductVariant::query()->find($combinationId);
                if ($variant) {
                    $price = (float) ($variant->price ?? $price);
                    $sku = $variant->sku ?: $sku;
                }
            }
            $total = round($price * $qty, 2);
            $subtotal += $total;
            $lines[] = [
                'item_id' => (string) ($line['item_id'] ?? ''),
                'product_id' => (string) ($line['product_id'] ?? ''),
                'sku' => $sku,
                'name' => $product?->translation($locale)?->name,
                'quantity' => $qty,
                'unit_price' => $price,
                'total' => $total,
            ];
        }
        $subtotal = round($subtotal, 2);
        $shipCode = $cart['shipping_method_code'] ?? null;
        $shipPrice = 0.0;
        if ($shipCode) {
            $shipPrice = (float) (ShippingMethod::query()->where('code', $shipCode)->value('price') ?? 0);
        }
        $discount = self::discountTotalFor($cart, $subtotal);

        return [
            'cart_id' => (string) $cart['cart_id'],
            'customer_id' => (string) $cart['customer_id'],
            'item_count' => count($lines),
            'subtotal' => $subtotal,
            'discount_total' => $discount,
            'coupon_code' => $cart['coupon_code'] ?? null,
            'shipping_total' => round($shipPrice, 2),
            'grand_total' => round(max(0, $subtotal - $discount + $shipPrice), 2),
            'items' => $lines,
            'has_addresses' => ! empty($cart['billing_address']),
            'billing_address' => $cart['billing_address'] ?? null,
            'shipping_address' => $cart['shipping_address'] ?? null,
            'shipping_method_code' => $shipCode,
            'payment_method_code' => $cart['payment_method_code'] ?? null,
            'created_at' => $cart['created_at'] ?? null,
            'updated_at' => $cart['updated_at'] ?? null,
        ];
    }
}
