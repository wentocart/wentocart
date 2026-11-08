<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\GraphqlAdminOrderComposerDraft;
use App\Domain\Catalog\Models\Product;
use App\Domain\Checkout\Models\Cart;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerAddress;
use App\Domain\Order\Models\OrderItem;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use GraphQL\Error\UserError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 */
class AdminOrderComposerDraftRestController extends Controller
{
    public function createDraft(Request $request, int $customerId): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            $cart = GraphqlAdminOrderComposerDraft::create($customerId);
        } catch (UserError $e) {
            return $this->userError($e);
        }

        return response()->json([
            'cartId' => $cart['cart_id'] ?? null,
            'customerId' => $cart['customer_id'] ?? $customerId,
            'success' => true,
            'message' => 'Order composer draft created.',
            'cart' => $cart,
        ], 201);
    }

    public function show(Request $request, string $cartId): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            $cart = GraphqlAdminOrderComposerDraft::get($cartId);
        } catch (UserError $e) {
            return $this->userError($e);
        }
        if ($cart === null) {
            return response()->json(['message' => 'Cart not found.', 'error' => 'not_found'], 404);
        }

        return response()->json($this->cartShape($cart));
    }

    public function storeItems(Request $request, string $cartId): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        $productId = (int) ($request->input('productId') ?? $request->input('product_id') ?? 0);
        $quantity = (float) ($request->input('quantity') ?? $request->input('qty') ?? 1);
        $selected = $request->input('selectedVariantOption') ?? $request->input('selected_configurable_option');
        $grouped = $this->normalizeOptionRows($request->input('groupedQuantities') ?? $request->input('grouped_quantities') ?? []);
        $bundle = $this->normalizeOptionRows($request->input('bundleOptions') ?? $request->input('bundle_options') ?? []);

        try {
            $cart = GraphqlAdminOrderComposerDraft::addItem(
                $cartId,
                $productId,
                $quantity,
                $selected !== null && $selected !== '' ? (int) $selected : null,
                $grouped,
                $bundle,
            );
        } catch (UserError $e) {
            return $this->userError($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Item added to order composer draft.',
            ...$this->cartShape($cart),
        ]);
    }

    public function updateItems(Request $request, string $cartId): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        $qtyMap = $request->input('qty');
        if (! is_array($qtyMap) || $qtyMap === []) {
            return response()->json([
                'message' => 'qty map of cartItemId → quantity is required.',
                'error' => 'validation',
            ], 422);
        }

        $cart = null;
        try {
            foreach ($qtyMap as $itemId => $quantity) {
                $cart = GraphqlAdminOrderComposerDraft::updateItem($cartId, (string) $itemId, (float) $quantity);
            }
        } catch (UserError $e) {
            return $this->userError($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Cart items updated.',
            ...$this->cartShape($cart ?? []),
        ]);
    }

    public function destroyItems(Request $request, string $cartId): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        $itemId = (string) ($request->input('cartItemId') ?? $request->input('itemId') ?? $request->input('item_id') ?? '');
        if ($itemId === '') {
            return response()->json([
                'message' => 'cartItemId is required.',
                'error' => 'validation',
            ], 422);
        }

        try {
            $cart = GraphqlAdminOrderComposerDraft::removeItem($cartId, $itemId);
        } catch (UserError $e) {
            return $this->userError($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Cart item removed.',
            ...$this->cartShape($cart),
        ]);
    }

    public function saveAddresses(Request $request, string $cartId): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        $billing = $request->input('billing');
        if (! is_array($billing)) {
            return response()->json([
                'message' => 'billing address is required.',
                'error' => 'validation',
            ], 422);
        }
        $shipping = $request->input('shipping');
        $useForShipping = (bool) ($billing['useForShipping'] ?? $request->boolean('useForShipping', true));

        try {
            $cart = GraphqlAdminOrderComposerDraft::saveAddresses(
                $cartId,
                $billing,
                is_array($shipping) ? $shipping : null,
                $useForShipping,
            );
        } catch (UserError $e) {
            return $this->userError($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Addresses saved.',
            ...$this->cartShape($cart),
        ]);
    }

    public function listShippingMethods(Request $request, string $cartId): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            $methods = GraphqlAdminOrderComposerDraft::shippingMethods($cartId);
        } catch (UserError $e) {
            return $this->userError($e);
        }

        return response()->json(['data' => $methods]);
    }

    public function setShippingMethod(Request $request, string $cartId): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        $code = (string) ($request->input('shippingMethod')
            ?? $request->input('code')
            ?? $request->input('method')
            ?? '');

        try {
            $cart = GraphqlAdminOrderComposerDraft::setShippingMethod($cartId, $code);
        } catch (UserError $e) {
            return $this->userError($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Shipping method saved.',
            ...$this->cartShape($cart),
        ]);
    }

    public function listPaymentMethods(Request $request, string $cartId): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            $methods = GraphqlAdminOrderComposerDraft::paymentMethods($cartId);
        } catch (UserError $e) {
            return $this->userError($e);
        }

        return response()->json(['data' => $methods]);
    }

    public function setPaymentMethod(Request $request, string $cartId): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        $code = (string) ($request->input('payment')
            ?? $request->input('paymentMethod')
            ?? $request->input('code')
            ?? $request->input('method')
            ?? '');

        try {
            $cart = GraphqlAdminOrderComposerDraft::setPaymentMethod($cartId, $code);
        } catch (UserError $e) {
            return $this->userError($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment method saved.',
            ...$this->cartShape($cart),
        ]);
    }

    public function applyCoupon(Request $request, string $cartId): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        $code = (string) ($request->input('coupon_code') ?? $request->input('couponCode') ?? $request->input('code') ?? '');

        try {
            $cart = GraphqlAdminOrderComposerDraft::applyCoupon($cartId, $code);
        } catch (UserError $e) {
            return $this->userError($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Coupon applied.',
            ...$this->cartShape($cart),
        ]);
    }

    public function removeCoupon(Request $request, string $cartId): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            $cart = GraphqlAdminOrderComposerDraft::removeCoupon($cartId);
        } catch (UserError $e) {
            return $this->userError($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Coupon removed.',
            ...$this->cartShape($cart),
        ]);
    }

    public function place(Request $request, string $cartId): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            $placed = GraphqlAdminOrderComposerDraft::place(
                $cartId,
                $request->filled('customerNote') ? (string) $request->input('customerNote') : null,
                true,
            );
        } catch (UserError $e) {
            return $this->userError($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Order placed successfully.',
            'orderId' => $placed['id'] ?? null,
            'incrementId' => $placed['number'] ?? null,
            'customerId' => $placed['customer_id'] ?? $placed['customerId'] ?? null,
            'grandTotal' => $placed['grand_total'] ?? null,
            'order' => $placed,
        ]);
    }

    /**
     */
    public function reorder(Request $request, string $orderId): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        try {
            $cart = GraphqlAdminOrderComposerDraft::createFromOrder($orderId);
        } catch (UserError $e) {
            $message = $e->getMessage();
            $status = str_contains(strtolower($message), 'not found') ? 404 : 422;

            return response()->json([
                'type' => '/errors/'.$status,
                'title' => $status === 404 ? 'Not Found' : 'Bad Request',
                'status' => $status,
                'detail' => $message,
                'message' => $message,
                'error' => 'reorder_error',
            ], $status);
        }

        return response()->json([
            'success' => true,
            'message' => 'Order composer draft created from order.',
            'cartId' => $cart['cart_id'] ?? null,
            'customerId' => $cart['customer_id'] ?? null,
            'itemsCount' => $cart['item_count'] ?? 0,
            'cart' => $this->cartShape($cart),
        ], 201);
    }

    /**
     */
    public function customerAddresses(Request $request, int $customerId): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        $customer = Customer::query()->find($customerId);
        if (! $customer) {
            return response()->json(['message' => 'Customer not found.', 'error' => 'not_found'], 404);
        }

        $rows = CustomerAddress::query()
            ->where('customer_id', $customerId)
            ->orderByDesc('is_default_billing')
            ->orderByDesc('is_default_shipping')
            ->orderBy('id')
            ->get()
            ->map(fn (CustomerAddress $a) => [
                'id' => (int) $a->id,
                'firstName' => $a->first_name,
                'lastName' => $a->last_name,
                'companyName' => $a->company,
                'phone' => $a->phone,
                'line1' => $a->line1,
                'line2' => $a->line2,
                'city' => $a->city,
                'state' => $a->state,
                'postalCode' => $a->postal_code,
                'countryCode' => $a->country_code,
                'defaultBilling' => (bool) $a->is_default_billing,
                'defaultShipping' => (bool) $a->is_default_shipping,
                'default' => (bool) $a->is_default_billing || (bool) $a->is_default_shipping,
            ])
            ->values()
            ->all();

        return response()->json(['data' => $rows]);
    }

    public function customerCartItems(Request $request, int $customerId): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        return response()->json(['data' => $this->interestCartItems($customerId, (int) $request->input('limit', 50))]);
    }

    public function customerWishlistItems(Request $request, int $customerId): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        return response()->json(['data' => $this->interestWishlistItems($customerId, (int) $request->input('limit', 20))]);
    }

    public function customerRecentOrderItems(Request $request, int $customerId): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        return response()->json(['data' => $this->interestRecentOrderItems($customerId, (int) $request->input('limit', 10))]);
    }

    public function customerCompareItems(Request $request, int $customerId): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        return response()->json(['data' => $this->interestCompareItems($customerId, (int) $request->input('limit', 20))]);
    }

    protected function guard(): ?JsonResponse
    {
        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin) {
            return response()->json(['message' => 'Unauthenticated.', 'error' => 'unauthenticated'], 401);
        }
        if (! $admin->hasPermission('orders.manage')) {
            return response()->json(['message' => 'Forbidden.', 'error' => 'forbidden'], 403);
        }

        return null;
    }

    protected function userError(UserError $e): JsonResponse
    {
        $message = $e->getMessage();
        $status = 422;
        $lower = strtolower($message);
        if (str_contains($lower, 'not found')) {
            $status = 404;
        } elseif (str_contains($lower, 'unsupported') || str_contains($lower, 'minimum order')) {
            $status = 422;
        } elseif (
            str_contains($message, 'missing')
            || str_contains($message, 'empty')
            || str_contains($message, 'Cart empty')
            || str_contains($message, 'before')
            || str_contains($message, 'must be saved')
        ) {
            $status = 409;
        } elseif (str_contains($lower, 'booking')) {
            $status = 400;
        }

        return response()->json(['message' => $message, 'error' => 'draft_cart_error'], $status);
    }

    /**
     * @param  array<string, mixed>  $cart
     * @return array<string, mixed>
     */
    protected function cartShape(array $cart): array
    {
        $items = [];
        foreach ($cart['items'] ?? [] as $line) {
            if (! is_array($line)) {
                continue;
            }
            $items[] = [
                'id' => $line['item_id'] ?? null,
                'cartItemId' => $line['item_id'] ?? null,
                'productId' => $line['product_id'] ?? null,
                'quantity' => $line['quantity'] ?? null,
                'combinationId' => $line['combination_id'] ?? null,
            ];
        }

        return [
            'id' => '/api/v1/console/carts/'.($cart['cart_id'] ?? ''),
            '_id' => $cart['cart_id'] ?? null,
            'cartId' => $cart['cart_id'] ?? null,
            'customerId' => $cart['customer_id'] ?? null,
            'isActive' => false,
            'itemsCount' => $cart['item_count'] ?? count($items),
            'subTotal' => $cart['subtotal'] ?? null,
            'grandTotal' => $cart['grand_total'] ?? null,
            'shippingMethod' => $cart['shipping_method_code'] ?? null,
            'paymentMethodTitle' => $cart['payment_method_code'] ?? null,
            'items' => $items !== [] ? $items : ($cart['items'] ?? []),
            'billingAddress' => $cart['billing_address'] ?? null,
            'shippingAddress' => $cart['shipping_address'] ?? null,
            'couponCode' => $cart['coupon_code'] ?? null,
        ];
    }

    /**
     * @param  mixed  $rows
     * @return list<array{product_id: int, qty: float}>
     */
    protected function normalizeOptionRows(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $out[] = [
                'product_id' => (int) ($row['productId'] ?? $row['product_id'] ?? 0),
                'qty' => (float) ($row['quantity'] ?? $row['qty'] ?? 1),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function interestCartItems(int $customerId, int $limit): array
    {
        if (! Customer::query()->whereKey($customerId)->exists()) {
            return [];
        }
        $limit = max(1, min(50, $limit));
        $locale = app()->getLocale();
        $cart = Cart::query()
            ->where('customer_id', $customerId)
            ->orderByDesc('id')
            ->with(['items.product.translations'])
            ->first();
        if (! $cart) {
            return [];
        }

        $rows = [];
        foreach ($cart->items as $item) {
            $product = $item->product;
            if (! $product || ! $product->is_enabled) {
                continue;
            }
            $rows[] = [
                'product_id' => (string) $product->id,
                'sku' => $product->sku,
                'name' => $product->translation($locale)?->name,
                'price' => $product->price !== null ? (float) $product->price : null,
                'source' => 'cart',
            ];
            if (count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function interestWishlistItems(int $customerId, int $limit): array
    {
        $customer = Customer::query()->find($customerId);
        if (! $customer) {
            return [];
        }
        $limit = max(1, min(50, $limit));
        $locale = app()->getLocale();
        $ids = $customer->wishlist()->pluck('product_id')->take($limit)->all();

        return Product::query()
            ->with('translations')
            ->whereIn('id', $ids)
            ->where('is_enabled', true)
            ->get()
            ->map(fn (Product $p) => [
                'product_id' => (string) $p->id,
                'sku' => $p->sku,
                'name' => $p->translation($locale)?->name,
                'price' => $p->price !== null ? (float) $p->price : null,
                'source' => 'wishlist',
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function interestRecentOrderItems(int $customerId, int $limit): array
    {
        if (! Customer::query()->whereKey($customerId)->exists()) {
            return [];
        }
        $limit = max(1, min(50, $limit));
        $locale = app()->getLocale();
        $productIds = OrderItem::query()
            ->whereHas('order', fn ($q) => $q->where('customer_id', $customerId))
            ->whereNotNull('product_id')
            ->orderByDesc('id')
            ->limit($limit * 3)
            ->pluck('product_id')
            ->unique()
            ->take($limit)
            ->values();

        return Product::query()
            ->with('translations')
            ->whereIn('id', $productIds->all())
            ->where('is_enabled', true)
            ->get()
            ->map(fn (Product $p) => [
                'product_id' => (string) $p->id,
                'sku' => $p->sku,
                'name' => $p->translation($locale)?->name,
                'price' => $p->price !== null ? (float) $p->price : null,
                'source' => 'recent_order',
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function interestCompareItems(int $customerId, int $limit): array
    {
        $customer = Customer::query()->find($customerId);
        if (! $customer) {
            return [];
        }
        $limit = max(1, min(50, $limit));
        $locale = app()->getLocale();
        $profile = is_array($customer->profile) ? $customer->profile : [];
        $raw = $profile['compare_ids'] ?? [];
        $ids = collect(is_array($raw) ? $raw : [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->take($limit)
            ->values()
            ->all();

        return Product::query()
            ->with('translations')
            ->whereIn('id', $ids)
            ->where('is_enabled', true)
            ->get()
            ->map(fn (Product $p) => [
                'product_id' => (string) $p->id,
                'sku' => $p->sku,
                'name' => $p->translation($locale)?->name,
                'price' => $p->price !== null ? (float) $p->price : null,
                'source' => 'compare',
            ])
            ->all();
    }
}
