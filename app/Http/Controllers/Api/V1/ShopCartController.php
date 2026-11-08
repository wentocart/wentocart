<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\CatalogPresenter;
use App\Domain\Checkout\Services\CartService;
use App\Domain\Customer\Models\Customer;
use App\Domain\Localization\Money;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Domain\Store\StoreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shop REST cart REST under /api/v1/store/* (does not replace Wento /cart).
 */
class ShopCartController extends Controller
{
    public function getCart(Request $request, CartService $carts): JsonResponse
    {
        $customer = $request->user('customer');
        $cart = $carts->current($customer instanceof Customer ? $customer : null);
        if ($cart->items()->count() < 1) {
            return response()->json(['message' => 'Cart not found.', 'success' => false], 404);
        }

        return response()->json($this->cartPayload($carts, $cart, $customer instanceof Customer ? $customer : null));
    }

    public function createCart(Request $request, CartService $carts): JsonResponse
    {
        $customer = $request->user('customer');
        if ($customer instanceof Customer) {
            $cart = $carts->current($customer);
        } else {
            $carts->issueFreshGuestCartToken();
            $cart = $carts->current(null);
        }

        return response()->json($this->cartPayload($carts, $cart, $customer instanceof Customer ? $customer : null), 201);
    }

    /**
     * Wento also accepts cartToken for headless clients that only kept the token.
     */
    public function mergeCarts(Request $request, CartService $carts): JsonResponse
    {
        $customer = $request->user('customer');
        if (! $customer instanceof Customer) {
            return response()->json([
                'detail' => 'Unauthenticated. Please login to perform this action',
                'message' => 'Unauthenticated. Please login to perform this action',
            ], 401);
        }

        $cartId = (int) ($request->input('cartId') ?? $request->input('cart_id') ?? 0);
        $cartToken = trim((string) ($request->input('cartToken') ?? $request->input('cart_token') ?? ''));
        if ($cartId < 1 && $cartToken === '') {
            return response()->json([
                'message' => 'The cart id field is required.',
                'success' => false,
            ], 422);
        }

        try {
            $cart = $carts->mergeGuestCart(
                $customer,
                $cartId > 0 ? $cartId : null,
                $cartToken !== '' ? $cartToken : null,
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Guest cart not found.',
                'detail' => 'Guest cart not found.',
                'success' => false,
            ], 404);
        }

        $payload = $this->cartPayload($carts, $cart, $customer);
        $payload['success'] = true;
        $payload['message'] = 'Cart merged successfully.';

        return response()->json($payload, 201);
    }

    /**
     */
    public function addProductById(Request $request, int $productId, CartService $carts): JsonResponse
    {
        $request->merge([
            'productId' => $productId,
            'product_id' => $productId,
        ]);

        return $this->addProduct($request, $carts);
    }

    /**
     */
    public function moveToWishlistByItemId(Request $request, int $cartItemId, CartService $carts): JsonResponse
    {
        $request->merge([
            'cartItemId' => $cartItemId,
            'cart_item_id' => $cartItemId,
        ]);

        return $this->moveToWishlist($request, $carts);
    }

    public function addProduct(Request $request, CartService $carts): JsonResponse
    {
        $productId = (int) ($request->input('productId') ?? $request->input('product_id') ?? 0);
        $qty = max(1, (float) ($request->input('quantity') ?? $request->input('qty') ?? 1));
        if ($productId < 1) {
            return response()->json(['message' => 'The product id field is required.', 'success' => false], 400);
        }
        $variantId = (int) ($request->input('selectedVariantOption') ?? $request->input('combination_id') ?? 0);
        $customer = $request->user('customer');
        $cart = $carts->current($customer instanceof Customer ? $customer : null);
        $product = Product::query()->enabled()->find($variantId > 0 ? $variantId : $productId);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'success' => false], 404);
        }
        $customization = [];
        $rawOptions = $request->input('customizableOptions') ?? $request->input('customization') ?? [];
        if (is_array($rawOptions)) {
            foreach ($rawOptions as $optionId => $value) {
                $customization[(string) $optionId] = is_array($value) ? ($value[0] ?? $value) : $value;
            }
        }
        try {
            $carts->add(
                $cart,
                $product,
                $qty,
                $customization,
                [],
                null,
                [],
                $variantId > 0 ? $variantId : null,
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage(), 'success' => false], 422);
        }
        $payload = $this->cartPayload($carts, $cart->fresh(['items.product.translations']), $customer instanceof Customer ? $customer : null);
        $payload['success'] = true;
        $payload['message'] = 'Item added to cart.';

        return response()->json($payload);
    }

    public function updateItem(Request $request, int $cartItemId, CartService $carts): JsonResponse
    {
        $qty = (float) ($request->input('quantity') ?? $request->input('qty') ?? 0);
        if ($qty <= 0) {
            return response()->json(['message' => 'Quantity must be at least 1.', 'success' => false], 400);
        }
        $customer = $request->user('customer');
        $cart = $carts->current($customer instanceof Customer ? $customer : null);
        $item = $cart->items()->whereKey($cartItemId)->first();
        if (! $item) {
            return response()->json(['message' => 'Cart item not found.', 'success' => false], 404);
        }
        try {
            $carts->updateQty($cart, $cartItemId, $qty);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage(), 'success' => false], 422);
        }

        return response()->json($this->cartPayload($carts, $cart->fresh(['items.product.translations']), $customer instanceof Customer ? $customer : null));
    }

    /**
     * Unknown cartItemId → 201 success no-op (parity). Quantity &lt; 1 → 400.
     */
    public function updateCartItem(Request $request, CartService $carts): JsonResponse
    {
        $cartItemId = (int) ($request->input('cartItemId') ?? $request->input('cart_item_id') ?? 0);
        $qtyRaw = $request->input('quantity') ?? $request->input('qty');
        $qty = is_numeric($qtyRaw) ? (float) $qtyRaw : 0.0;
        if ($cartItemId < 1 || $qty < 1) {
            return response()->json([
                'message' => 'Cart item ID and quantity are required',
                'success' => false,
            ], 400);
        }

        $customer = $request->user('customer');
        $cart = $carts->current($customer instanceof Customer ? $customer : null);
        $item = $cart->items()->whereKey($cartItemId)->first();
        if ($item) {
            try {
                $carts->updateQty($cart, $cartItemId, $qty);
            } catch (\Throwable $e) {
                return response()->json(['message' => $e->getMessage(), 'success' => false], 422);
            }
            $cart = $cart->fresh(['items.product.translations']);
        }

        $payload = $this->cartPayload(
            $carts,
            $cart,
            $customer instanceof Customer ? $customer : null,
        );
        $payload['success'] = true;
        $payload['message'] = 'Cart item updated successfully';

        return response()->json($payload, 201);
    }

    public function removeItem(Request $request, int $cartItemId, CartService $carts): JsonResponse
    {
        $customer = $request->user('customer');
        $cart = $carts->current($customer instanceof Customer ? $customer : null);
        if (! $cart->items()->whereKey($cartItemId)->exists()) {
            return response()->json(['message' => 'Cart item not found.', 'success' => false], 404);
        }
        $cart->items()->whereKey($cartItemId)->delete();

        // Legacy DELETE path: empty cart → 404 (Wave 225). Prefer POST remove-cart-item for empty-cart parity.
        if ($cart->items()->count() < 1) {
            return response()->json(['message' => 'Cart not found.', 'success' => false], 404);
        }

        return response()->json($this->cartPayload($carts, $cart->fresh(['items.product.translations']), $customer instanceof Customer ? $customer : null));
    }

    /**
     * Empty cart remains readable (201 + items: []); cartToken stays valid.
     */
    public function removeCartItem(Request $request, CartService $carts): JsonResponse
    {
        $cartItemId = (int) ($request->input('cartItemId') ?? $request->input('cart_item_id') ?? 0);
        if ($cartItemId < 1) {
            return response()->json([
                'message' => 'The cart item id field is required.',
                'success' => false,
            ], 400);
        }

        $customer = $request->user('customer');
        $cart = $carts->current($customer instanceof Customer ? $customer : null);
        if (! $cart->items()->whereKey($cartItemId)->exists()) {
            return response()->json(['message' => 'Cart item not found.', 'success' => false], 404);
        }
        $cart->items()->whereKey($cartItemId)->delete();

        $payload = $this->cartPayload(
            $carts,
            $cart->fresh(['items.product.translations']),
            $customer instanceof Customer ? $customer : null,
        );
        $payload['success'] = true;
        $payload['message'] = 'Item removed from cart successfully';

        return response()->json($payload, 201);
    }

    /**
     */
    public function removeAllCartItems(Request $request, CartService $carts): JsonResponse
    {
        $customer = $request->user('customer');
        $cart = $carts->current($customer instanceof Customer ? $customer : null);
        $cart->items()->delete();
        $payload = $this->cartPayload(
            $carts,
            $cart->fresh(['items.product.translations']),
            $customer instanceof Customer ? $customer : null,
        );
        $payload['success'] = true;
        $payload['message'] = 'Cart cleared successfully.';

        return response()->json($payload);
    }

    /**
     * Also DELETE checkout/cart/selected with body { ids: int[] }.
     * DELETE customer/cart/remove with empty body → removeAllCartItems.
     */
    public function removeCartItems(Request $request, CartService $carts): JsonResponse
    {
        $raw = $request->input('itemIds')
            ?? $request->input('item_ids')
            ?? $request->input('ids');
        if (! is_array($raw) || $raw === []) {
            if (str_contains($request->path(), 'customer/cart/remove')) {
                return $this->removeAllCartItems($request, $carts);
            }

            return response()->json([
                'message' => 'The item ids field is required.',
                'success' => false,
            ], 400);
        }

        $ids = array_values(array_unique(array_filter(array_map(
            static fn ($v) => (int) $v,
            $raw,
        ), static fn (int $id) => $id > 0)));
        if ($ids === []) {
            return response()->json([
                'message' => 'The item ids field is required.',
                'success' => false,
            ], 400);
        }

        $customer = $request->user('customer');
        $cart = $carts->current($customer instanceof Customer ? $customer : null);
        $found = $cart->items()->whereIn('id', $ids)->pluck('id')->all();
        if ($found === []) {
            return response()->json(['message' => 'Cart item not found.', 'success' => false], 404);
        }
        $cart->items()->whereIn('id', $found)->delete();

        $payload = $this->cartPayload(
            $carts,
            $cart->fresh(['items.product.translations']),
            $customer instanceof Customer ? $customer : null,
        );
        $payload['success'] = true;
        $payload['message'] = 'Items removed from cart successfully';

        return response()->json($payload, 201);
    }

    public function applyCoupon(Request $request, CartService $carts): JsonResponse
    {
        $code = trim((string) ($request->input('code') ?? $request->input('couponCode') ?? ''));
        if ($code === '') {
            return response()->json(['message' => 'Coupon code is required.', 'success' => false], 400);
        }
        $customer = $request->user('customer');
        $cart = $carts->current($customer instanceof Customer ? $customer : null);
        try {
            $carts->applyCoupon($cart, $code);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage(), 'success' => false], 422);
        }

        $payload = $this->cartPayload(
            $carts,
            $cart->fresh(['items.product.translations', 'discountRule']),
            $customer instanceof Customer ? $customer : null,
        );
        $payload['success'] = true;
        $payload['message'] = 'Coupon applied successfully.';
        $payload['discount'] = $payload['discountAmount'] ?? 0;
        $payload['tax'] = $payload['taxAmount'] ?? 0;
        $payload['total'] = $payload['grandTotal'] ?? 0;

        return response()->json($payload);
    }

    public function removeCoupon(Request $request, CartService $carts): JsonResponse
    {
        $customer = $request->user('customer');
        $cart = $carts->current($customer instanceof Customer ? $customer : null);
        $cart->forceFill(['coupon_id' => null, 'discount_rule_id' => null])->save();

        $payload = $this->cartPayload(
            $carts,
            $cart->fresh(['items.product.translations', 'discountRule']),
            $customer instanceof Customer ? $customer : null,
        );
        $payload['success'] = true;
        $payload['message'] = 'Coupon removed successfully';
        $payload['cartTotal'] = $payload['subtotal'] ?? 0;
        $payload['tax'] = $payload['taxAmount'] ?? 0;
        $payload['total'] = $payload['grandTotal'] ?? 0;

        return response()->json($payload);
    }

    /**
     * Body: { cartItemId } or { productId }.
     */
    public function moveToWishlist(Request $request, CartService $carts): JsonResponse
    {
        $customer = $request->user('customer');
        if (! $customer instanceof Customer) {
            return response()->json([
                'detail' => 'Unauthenticated. Please login to perform this action',
                'message' => 'Unauthenticated. Please login to perform this action',
            ], 401);
        }

        $cart = $carts->current($customer);
        $ids = $request->input('ids');
        if (is_array($ids) && $ids !== []) {
            $moved = 0;
            foreach ($ids as $rawId) {
                $item = $cart->items()->where('id', (int) $rawId)->first();
                if (! $item) {
                    continue;
                }
                $pid = (int) $item->product_id;
                if ($pid > 0 && ! $customer->wishlist()->where('product_id', $pid)->exists()) {
                    $customer->wishlist()->attach($pid);
                }
                $item->delete();
                $moved++;
            }
            if ($moved < 1) {
                return response()->json(['message' => 'Cart item not found.', 'success' => false], 404);
            }
            $payload = $this->cartPayload(
                $carts,
                $cart->fresh(['items.product.translations']),
                $customer,
            );
            $payload['success'] = true;
            $payload['message'] = 'Items moved to wishlist successfully.';

            return response()->json($payload);
        }

        $itemId = (int) ($request->input('cartItemId') ?? $request->input('cart_item_id') ?? $request->input('id') ?? 0);
        $productId = (int) ($request->input('productId') ?? $request->input('product_id') ?? 0);
        $item = null;
        if ($itemId > 0) {
            $item = $cart->items()->where('id', $itemId)->first();
        } elseif ($productId > 0) {
            $item = $cart->items()->where('product_id', $productId)->first();
        }
        if (! $item) {
            return response()->json(['message' => 'Cart item not found.', 'success' => false], 404);
        }
        $productId = (int) $item->product_id;
        if (! Product::query()->whereKey($productId)->exists()) {
            return response()->json(['message' => 'Product not found.', 'success' => false], 404);
        }
        if (! $customer->wishlist()->where('product_id', $productId)->exists()) {
            $customer->wishlist()->attach($productId);
        }
        $item->delete();

        $payload = $this->cartPayload(
            $carts,
            $cart->fresh(['items.product.translations']),
            $customer,
        );
        $payload['success'] = true;
        $payload['message'] = 'Item moved to wishlist successfully.';

        return response()->json($payload);
    }

    /**
     * (also /shop/estimate-shipping-methods). Body: { country }.
     */
    public function estimateShippingMethods(Request $request, CartService $carts): JsonResponse
    {
        $country = strtoupper(trim((string) ($request->input('country')
            ?? $request->input('country_code')
            ?? $request->input('countryCode')
            ?? '')));
        if (strlen($country) !== 2) {
            return response()->json([
                'message' => 'Country must be a 2-letter ISO code.',
                'success' => false,
            ], 422);
        }

        $customer = $request->user('customer');
        $cart = $carts->current($customer instanceof Customer ? $customer : null);
        $methods = ShippingMethod::query()
            ->where('is_enabled', true)
            ->whereNull('seller_id')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(function (ShippingMethod $method) use ($carts, $cart, $country) {
                try {
                    $price = $carts->quoteShipping($cart, $method, $country);
                } catch (\Throwable) {
                    $price = (float) ($method->price ?? 0);
                }
                $code = (string) ($method->code ?: $method->type ?: 'method_'.$method->id);

                return [
                    'id' => (string) $method->id,
                    'code' => $code,
                    'carrier' => $code,
                    'title' => (string) ($method->name ?: ('Method #'.$method->id)),
                    'methodTitle' => (string) ($method->name ?: $code),
                    'description' => (string) ($method->description ?? ''),
                    'price' => $price,
                    'basePrice' => $price,
                    'formattedPrice' => Money::format((float) $price),
                    'is_pickup' => $method->type === 'local_pickup',
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'data' => $methods,
            'shippingMethods' => $methods,
            'success' => true,
        ]);
    }

    /**
     */
    public function crossSell(Request $request, CartService $carts, CatalogPresenter $present): JsonResponse
    {
        $customer = $request->user('customer');
        $cart = $carts->current($customer instanceof Customer ? $customer : null);
        $cart->loadMissing('items');
        $productIds = $cart->items->pluck('product_id')->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        if ($productIds === []) {
            return response()->json([]);
        }

        $locale = app()->getLocale();
        $relatedIds = \Illuminate\Support\Facades\DB::table('product_relations')
            ->whereIn('product_id', $productIds)
            ->where('type', 'crosssell')
            ->pluck('related_product_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->reject(fn (int $id) => in_array($id, $productIds, true))
            ->values()
            ->all();

        if ($relatedIds === []) {
            return response()->json([]);
        }

        $rows = Product::query()
            ->enabled()
            ->whereIn('id', $relatedIds)
            ->with(['translations', 'images'])
            ->limit(20)
            ->get()
            ->map(function (Product $p) use ($present, $locale) {
                $presented = $present->product($p, $locale, false);
                $price = (float) ($presented['price'] ?? $p->price ?? 0);

                return [
                    'id' => (int) $p->id,
                    'sku' => (string) $p->sku,
                    'name' => (string) ($presented['name'] ?? ''),
                    'slug' => (string) ($presented['slug'] ?? ''),
                    'price' => $price,
                    'formattedPrice' => Money::format($price),
                    'baseImageUrl' => is_string($presented['image'] ?? null)
                        ? $presented['image']
                        : (is_string($presented['thumbnail'] ?? null) ? $presented['thumbnail'] : null),
                ];
            })
            ->values()
            ->all();

        return response()->json($rows);
    }

    /**
     * @return array<string, mixed>
     */
    private function cartPayload(CartService $carts, $cart, ?Customer $customer): array
    {
        $totals = $carts->summary(
            $cart,
            0.0,
            null,
            null,
            $customer,
            Money::currentCode(),
            true,
            null,
        );
        $presented = $carts->present($cart, $totals);
        $items = [];
        $qtySum = 0.0;
        foreach ($presented['items'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $qty = (float) ($row['qty'] ?? 1);
            $qtySum += $qty;
            $unit = (float) ($row['unit_price'] ?? 0);
            $line = round($unit * $qty, 2);
            $locale = app()->getLocale();
            $product = null;
            if (isset($row['product_id'])) {
                $product = Product::query()->with('translations')->find((int) $row['product_id']);
            }
            $slug = $product?->translation($locale)?->slug ?? '';
            $items[] = [
                'id' => (int) ($row['id'] ?? 0),
                'cartId' => (int) $cart->id,
                'productId' => (int) ($row['product_id'] ?? 0),
                'name' => (string) ($row['name'] ?? $product?->translation($locale)?->name ?? ''),
                'sku' => (string) ($product?->sku ?? ''),
                'quantity' => fmod($qty, 1.0) === 0.0 ? (int) $qty : $qty,
                'price' => $unit,
                'total' => $line,
                'formattedPrice' => Money::format($unit),
                'formattedTotal' => Money::format($line),
                'priceInclTax' => $unit,
                'totalInclTax' => $line,
                'formattedPriceInclTax' => Money::format($unit),
                'formattedTotalInclTax' => Money::format($line),
                'discountAmount' => 0,
                'taxAmount' => 0,
                'options' => null,
                'type' => (string) ($product?->type ?? 'simple'),
                'baseImage' => null,
                'productUrlKey' => (string) $slug,
                'canChangeQty' => true,
            ];
        }
        $subtotal = (float) ($totals['subtotal'] ?? 0);
        $discount = (float) ($totals['discount'] ?? $totals['discount_amount'] ?? 0);
        $tax = (float) ($totals['tax'] ?? $totals['tax_amount'] ?? 0);
        $shipping = (float) ($totals['shipping'] ?? $totals['shipping_amount'] ?? 0);
        $grand = (float) ($totals['grand_total'] ?? $subtotal);
        $storeId = (int) (app(StoreContext::class)->id() ?: 0);
        $coupon = null;
        if (! $cart->relationLoaded('discountRule')) {
            $cart->loadMissing('discountRule');
        }
        if ($cart->discountRule) {
            $coupon = $cart->discountRule->code ?? null;
        }

        return [
            'id' => (int) $cart->id,
            'cartToken' => (string) ($cart->session_id ?: $cart->id),
            'customerId' => $cart->customer_id ? (int) $cart->customer_id : null,
            'storeId' => $storeId,
            'itemsCount' => count($items),
            'itemsQty' => fmod($qtySum, 1.0) === 0.0 ? (int) $qtySum : $qtySum,
            'items' => $items,
            'subtotal' => $subtotal,
            'baseSubtotal' => $subtotal,
            'discountAmount' => $discount,
            'baseDiscountAmount' => $discount,
            'taxAmount' => $tax,
            'baseTaxAmount' => $tax,
            'shippingAmount' => $shipping,
            'baseShippingAmount' => $shipping,
            'grandTotal' => $grand,
            'baseGrandTotal' => $grand,
            'formattedSubtotal' => Money::format($subtotal),
            'formattedDiscountAmount' => Money::format($discount),
            'formattedTaxAmount' => Money::format($tax),
            'formattedShippingAmount' => Money::format($shipping),
            'formattedGrandTotal' => Money::format($grand),
            'couponCode' => $coupon,
            'isGuest' => $cart->customer_id === null,
        ];
    }
}
