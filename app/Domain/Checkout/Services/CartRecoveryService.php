<?php

namespace App\Domain\Checkout\Services;

use App\Domain\Checkout\Models\Cart;
use App\Domain\Customer\Models\Customer;
use App\Domain\Promo\Models\Coupon;
use App\Domain\Settings\ShopSetting;
use Illuminate\Support\Str;

class CartRecoveryService
{
    /**
     * Generate or retrieve a secure cross-device recovery token for a cart.
     */
    public function tokenFor(Cart $cart): string
    {
        $meta = is_array($cart->meta) ? $cart->meta : [];
        if (! empty($meta['recovery_token']) && is_string($meta['recovery_token'])) {
            return $meta['recovery_token'];
        }

        $token = Str::random(40);
        $meta['recovery_token'] = $token;
        $meta['recovery_generated_at'] = now()->toIso8601String();
        $cart->update(['meta' => $meta]);

        return $token;
    }

    /**
     * Build the full recovery URL for emails/SMS.
     */
    public function recoveryUrl(Cart $cart): string
    {
        $token = $this->tokenFor($cart);
        $base = rtrim((string) config('app.url'), '/');

        return "{$base}/cart/recover?token={$token}";
    }

    /**
     * Restore an abandoned cart using its recovery token.
     *
     * @return array{ok: bool, cart: ?Cart, message: string, coupon_applied: ?string}
     */
    public function recover(string $token, ?Customer $currentCustomer, CartService $cartService): array
    {
        if (trim($token) === '') {
            return ['ok' => false, 'cart' => null, 'message' => 'Invalid recovery token.', 'coupon_applied' => null];
        }

        /** @var Cart|null $targetCart */
        $targetCart = Cart::query()
            ->where('meta->recovery_token', $token)
            ->with(['items.product', 'coupon'])
            ->first();

        if (! $targetCart) {
            return ['ok' => false, 'cart' => null, 'message' => 'Cart not found or recovery link expired.', 'coupon_applied' => null];
        }

        $meta = is_array($targetCart->meta) ? $targetCart->meta : [];
        $meta['recovered_at'] = now()->toIso8601String();

        // If the customer is logged in, associate the cart with them
        if ($currentCustomer && ! $targetCart->customer_id) {
            $targetCart->customer_id = $currentCustomer->id;
        }

        // Apply recovery incentive discount if configured
        $couponApplied = null;
        $incentiveCouponCode = trim((string) ShopSetting::get('marketing.cart_recovery_coupon', ''));
        if ($incentiveCouponCode !== '') {
            $coupon = Coupon::query()
                ->where('code', $incentiveCouponCode)
                ->where('is_enabled', true)
                ->first();

            if ($coupon) {
                $targetCart->coupon_id = $coupon->id;
                $couponApplied = $coupon->code;
            }
        }

        $targetCart->meta = $meta;
        $targetCart->save();

        return [
            'ok' => true,
            'cart' => $targetCart,
            'message' => 'Cart recovered successfully.',
            'coupon_applied' => $couponApplied,
        ];
    }
}
