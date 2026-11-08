<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Catalog\Models\Product;
use App\Domain\Checkout\Services\CartService;
use GraphQL\Error\UserError;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Shop REST cart/checkout mutation aliases (input-wrapped).
 */
final class GraphqlCartMutations
{
    /**
     * @param  array<string, mixed>  $args
     * @return array{cart: array<string, mixed>}
     */
    public static function createCart(array $args = []): array
    {
        return ['cart' => ShopSchema::cartPayloadPublic()];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{cart: array<string, mixed>}
     */
    public static function addProductsToCart(array $args): array
    {
        $input = is_array($args['input'] ?? null) ? $args['input'] : $args;
        $items = $input['items'] ?? null;
        if (! is_array($items) || $items === []) {
            // Single-item shorthand
            if (isset($input['productId'])) {
                $items = [[
                    'productId' => $input['productId'],
                    'quantity' => $input['quantity'] ?? $input['qty'] ?? 1,
                    'combinationId' => $input['combinationId'] ?? null,
                ]];
            } else {
                throw new UserError('items is required.');
            }
        }

        $carts = app(CartService::class);
        $customer = auth('customer')->user();
        $cart = $carts->current($customer);

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $productId = (int) ($item['productId'] ?? 0);
            if ($productId < 1) {
                throw new UserError('productId is required for each cart item.');
            }
            $qty = (float) ($item['quantity'] ?? $item['qty'] ?? 1);
            if ($qty <= 0) {
                throw new UserError('Quantity must be greater than zero.');
            }
            $combinationId = null;
            if (isset($item['combinationId'])) {
                $combinationId = (int) $item['combinationId'];
            } elseif (isset($item['variantAxisSelection']) && is_array($item['variantAxisSelection'])) {
                $combinationId = isset($item['variantAxisSelection']['combinationId'])
                    ? (int) $item['variantAxisSelection']['combinationId']
                    : null;
            }
            $product = Product::query()->enabled()->find($productId);
            if (! $product) {
                throw new UserError('Product not found.');
            }
            try {
                $carts->add(
                    $cart,
                    $product,
                    $qty,
                    [],
                    [],
                    null,
                    [],
                    $combinationId,
                );
            } catch (ValidationException $e) {
                throw new UserError(collect($e->errors())->flatten()->first() ?: $e->getMessage());
            } catch (Throwable $e) {
                throw new UserError($e->getMessage());
            }
        }

        return ['cart' => ShopSchema::cartPayloadPublic($carts)];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{cart: array<string, mixed>}
     */
    public static function updateCart(array $args): array
    {
        $input = is_array($args['input'] ?? null) ? $args['input'] : $args;
        $items = $input['items'] ?? null;
        if (! is_array($items) || $items === []) {
            if (isset($input['cartItemId']) || isset($input['itemId'])) {
                $items = [[
                    'cartItemId' => $input['cartItemId'] ?? $input['itemId'],
                    'quantity' => $input['quantity'] ?? $input['qty'] ?? 1,
                ]];
            } else {
                throw new UserError('items is required.');
            }
        }

        $carts = app(CartService::class);
        $cart = $carts->current(auth('customer')->user());
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $itemId = (int) ($item['cartItemId'] ?? $item['itemId'] ?? 0);
            $qty = (float) ($item['quantity'] ?? $item['qty'] ?? 0);
            if ($itemId < 1) {
                throw new UserError('cartItemId is required.');
            }
            if ($qty <= 0) {
                throw new UserError('Quantity must be greater than zero.');
            }
            $carts->updateQty($cart, $itemId, $qty);
        }

        return ['cart' => ShopSchema::cartPayloadPublic($carts)];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{cart: array<string, mixed>}
     */
    public static function removeCartItem(array $args): array
    {
        $input = is_array($args['input'] ?? null) ? $args['input'] : $args;
        $itemId = (int) ($input['cartItemId'] ?? $input['itemId'] ?? 0);
        if ($itemId < 1) {
            throw new UserError('cartItemId is required.');
        }
        $carts = app(CartService::class);
        $cart = $carts->current(auth('customer')->user());
        $cart->items()->where('id', $itemId)->delete();

        return ['cart' => ShopSchema::cartPayloadPublic($carts)];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{status: bool, message: string}
     */
    public static function moveToWishlist(array $args): array
    {
        $input = is_array($args['input'] ?? null) ? $args['input'] : $args;
        $customer = auth('customer')->user();
        if (! $customer) {
            throw new UserError('Authentication required.');
        }
        $itemId = (int) ($input['cartItemId'] ?? $input['itemId'] ?? 0);
        $productId = (int) ($input['productId'] ?? 0);
        $carts = app(CartService::class);
        $cart = $carts->current($customer);
        $item = null;
        if ($itemId > 0) {
            $item = $cart->items()->where('id', $itemId)->first();
        } elseif ($productId > 0) {
            $item = $cart->items()->where('product_id', $productId)->first();
        }
        if (! $item) {
            throw new UserError('Cart item not found.');
        }
        $productId = (int) $item->product_id;
        Product::query()->findOrFail($productId);
        if (! $customer->wishlist()->where('product_id', $productId)->exists()) {
            $customer->wishlist()->attach($productId);
        }
        $item->delete();

        return [
            'status' => true,
            'message' => 'Moved to wishlist.',
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{cart: array<string, mixed>}
     */
    public static function applyCoupon(array $args): array
    {
        $input = is_array($args['input'] ?? null) ? $args['input'] : $args;
        $code = trim((string) ($input['couponCode'] ?? $input['code'] ?? $args['couponCode'] ?? $args['code'] ?? ''));
        if ($code === '') {
            throw new UserError('Coupon code is required.');
        }
        $carts = app(CartService::class);
        $cart = $carts->current(auth('customer')->user());
        try {
            $carts->applyCoupon($cart, $code);
        } catch (Throwable $e) {
            throw new UserError($e->getMessage());
        }

        return ['cart' => ShopSchema::cartPayloadPublic($carts)];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{cart: array<string, mixed>}
     */
    public static function removeCoupon(array $args = []): array
    {
        $carts = app(CartService::class);
        $cart = $carts->current(auth('customer')->user());
        $cart->update(['discount_rule_id' => null, 'coupon_id' => null]);

        return ['cart' => ShopSchema::cartPayloadPublic($carts)];
    }
}
