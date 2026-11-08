<?php

namespace App\Domain\Checkout\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\SaleUnit;
use App\Domain\Catalog\Services\CustomizationService;
use App\Domain\Catalog\Services\GiftCardService;
use App\Domain\Checkout\Models\Cart;
use App\Domain\Checkout\Models\CartItem;
use App\Domain\Customer\Models\Customer;
use App\Domain\Localization\Money;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\Models\SellerProduct;
use App\Domain\Plugin\Support\ModuleBuses;
use App\Domain\Promo\DiscountContext;
use App\Domain\Promo\DiscountEngine;
use App\Domain\Promo\Models\DiscountRule;
use App\Domain\Settings\CartCheckoutSettings;
use App\Domain\Settings\InventorySettings;
use App\Domain\Settings\PricingSettings;
use App\Domain\Settings\ProductSettings;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Domain\Store\StoreContext;
use App\Domain\Subscription\Models\SubscriptionPlan;
use App\Domain\Subscription\Services\SubscriptionService;
use App\Domain\Tax\TaxEngine;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CartService
{
    /** @var list<array<string, mixed>> */
    protected array $quantityNotices = [];

    public function __construct(
        protected TaxEngine $tax,
        protected DiscountEngine $discounts,
        protected CustomizationService $customizations,
        protected SubscriptionService $subscriptions,
        protected GiftCardService $giftCards,
    ) {}

    public function current(?Customer $customer = null): Cart
    {
        $storeId = app(StoreContext::class)->cartStoreId();
        if ($customer) {
            $cart = Cart::query()->firstOrCreate(['customer_id' => $customer->id, 'store_id' => $storeId]);
            $guest = Cart::query()->where('session_id', $this->guestToken())->whereNull('customer_id')->where('store_id', $storeId)->first();
            if ($guest) {
                $this->merge($guest, $cart);
            }

            $cart = $cart->load([
                'items.product.translations',
                'items.product.seller',
                'items.subscriptionPlan.translations',
            ]);
            $this->sanitize($cart);

            return $cart->fresh([
                'items.product.translations',
                'items.product.seller',
                'items.subscriptionPlan.translations',
            ]) ?? $cart;
        }

        $token = $this->guestToken();

        $cart = Cart::query()->firstOrCreate(['session_id' => $token, 'store_id' => $storeId])->load([
            'items.product.translations',
            'items.product.seller',
            'items.subscriptionPlan.translations',
        ]);
        $this->sanitize($cart);

        return $cart->fresh([
            'items.product.translations',
            'items.product.seller',
            'items.subscriptionPlan.translations',
        ]) ?? $cart;
    }

    /**
     * Guest cart identity for cookie/session clients and headless Bearer-less GraphQL.
     * Prefer an explicit token header when present (cartToken parity).
     */
    public function guestToken(): string
    {
        $header = request()->header('X-Cart-Token') ?: request()->header('X-Guest-Cart');
        if (is_string($header)) {
            $header = trim($header);
            if ($header !== '' && strlen($header) <= 80 && preg_match('/^[A-Za-z0-9._-]+$/', $header)) {
                Session::put('wento_cart', $header);
                Cookie::queue(cookie('wento_cart', $header, $this->cartCookieMinutes(), '/', null, false, true, false, 'lax'));

                return $header;
            }
        }
        $bearer = request()->bearerToken();
        if (is_string($bearer)) {
            $bearer = trim($bearer);
            if (
                $bearer !== ''
                && ! str_contains($bearer, '|')
                && strlen($bearer) <= 80
                && preg_match('/^[A-Za-z0-9._-]+$/', $bearer)
            ) {
                Session::put('wento_cart', $bearer);
                Cookie::queue(cookie('wento_cart', $bearer, $this->cartCookieMinutes(), '/', null, false, true, false, 'lax'));

                return $bearer;
            }
        }

        $token = request()->cookie('wento_cart') ?: Session::get('wento_cart');

        if (! $token) {
            $token = (string) Str::uuid();
            Session::put('wento_cart', $token);
            Cookie::queue(cookie('wento_cart', $token, $this->cartCookieMinutes(), '/', null, false, true, false, 'lax'));
        }

        return (string) $token;
    }

    /** Ensure a guest cart exists and return its token (createCartToken GraphQL). */
    public function issueGuestCartToken(): string
    {
        $token = $this->guestToken();
        $this->current(null);

        return $token;
    }

    /**
     * (does not reuse the session cookie / prior cartToken).
     */
    public function issueFreshGuestCartToken(): string
    {
        $token = (string) Str::uuid();
        Session::put('wento_cart', $token);
        Cookie::queue(cookie('wento_cart', $token, $this->cartCookieMinutes(), '/', null, false, true, false, 'lax'));
        request()->headers->set('X-Cart-Token', $token);
        $this->current(null);

        return $token;
    }

    /**
     * into the authenticated customer's cart. Guest cart is deleted after merge.
     */
    public function mergeGuestCart(Customer $customer, ?int $guestCartId = null, ?string $guestCartToken = null): Cart
    {
        $storeId = app(StoreContext::class)->cartStoreId();
        $guest = null;
        if ($guestCartId !== null && $guestCartId > 0) {
            $guest = Cart::query()
                ->whereKey($guestCartId)
                ->whereNull('customer_id')
                ->where('store_id', $storeId)
                ->first();
        }
        if (! $guest && is_string($guestCartToken) && $guestCartToken !== '') {
            $token = trim($guestCartToken);
            if (strlen($token) <= 80 && preg_match('/^[A-Za-z0-9._-]+$/', $token)) {
                $guest = Cart::query()
                    ->where('session_id', $token)
                    ->whereNull('customer_id')
                    ->where('store_id', $storeId)
                    ->first();
            }
        }
        if (! $guest) {
            throw ValidationException::withMessages([
                'cartId' => 'Guest cart not found.',
            ]);
        }

        $cart = Cart::query()->firstOrCreate(['customer_id' => $customer->id, 'store_id' => $storeId]);
        $this->merge($guest, $cart);
        $this->sanitize($cart);

        return $cart->fresh([
            'items.product.translations',
            'items.product.seller',
            'items.subscriptionPlan.translations',
        ]) ?? $cart->load([
            'items.product.translations',
            'items.product.seller',
            'items.subscriptionPlan.translations',
        ]);
    }

    protected function cartCookieMinutes(): int
    {
        return max(1, CartCheckoutSettings::cartExpiryMinutes());
    }

    protected function sanitize(Cart $cart): void
    {
        $cart->loadMissing('items.product');
        $minutes = CartCheckoutSettings::cartExpiryMinutes();
        $latest = $cart->items->max('updated_at') ?: $cart->updated_at;
        if ($latest && $latest->lt(now()->subMinutes($minutes)) && $cart->items->isNotEmpty()) {
            $cart->items()->delete();
            $cart->update(['discount_rule_id' => null, 'coupon_id' => null]);
            $cart->unsetRelation('items');

            return;
        }

        if (CartCheckoutSettings::unavailableItems() === 'remove') {
            $preorders = app(\App\Domain\Catalog\Services\PreorderService::class);
            foreach ($cart->items as $item) {
                if ($preorders->isFinalPaymentData(is_array($item->preorder_data) ? $item->preorder_data : null)) {
                    continue;
                }
                $product = $item->product;
                $gone = ! $product || ! $this->isPurchasable($product);
                $oos = CartCheckoutSettings::stockValidation()
                    && InventorySettings::enabled()
                    && ! InventorySettings::allowNegative()
                    && $product
                    && ! $product->inStockFor((float) $item->qty);
                if ($gone || $oos) {
                    $item->delete();
                }
            }
            $cart->unsetRelation('items');
        }

        $cart->loadMissing('items.product');
        $preorders = app(\App\Domain\Catalog\Services\PreorderService::class);
        foreach ($cart->items as $item) {
            if (! $item->exists) {
                continue;
            }
            if ($preorders->isFinalPaymentData(is_array($item->preorder_data) ? $item->preorder_data : null)) {
                continue;
            }
            $product = $item->product;
            if (! $product) {
                continue;
            }
            $snapped = $this->quantityForLine($product, (float) $item->qty);
            if (abs($snapped - (float) $item->qty) > 0.00001) {
                $item->qty = $snapped;
                $item->save();
            }
        }

        if (! CartCheckoutSettings::couponsEnabled() && ($cart->discount_rule_id || $cart->coupon_id)) {
            $cart->update(['discount_rule_id' => null, 'coupon_id' => null]);
        }
    }

    public function add(Cart $cart, Product $product, float $qty = 1, array $customization = [], array $files = [], ?int $subscriptionPlanId = null, array $giftCardData = [], ?int $combinationId = null, ?string $bookingDate = null, ?int $parentProductId = null, ?float $unitPriceOverride = null, ?array $bundleSelection = null, ?string $bookingEndDate = null, ?int $rentalDuration = null): CartItem
    {
        if (ProductSettings::catalogMode()) {
            abort(422, 'This shop is catalog-only.');
        }

        $this->assertPurchasable($product);

        $requested = $qty;
        $qty = $this->quantityForLine($product, $requested);

        $giftCardPayload = null;
        if ($product->isGiftCard()) {
            $giftCardPayload = $this->giftCards->resolvePurchaseData($product, $giftCardData);
            $qty = 1;
        }

        $combinationId = $this->resolveCombinationId($product, $combinationId);

        $preorders = app(\App\Domain\Catalog\Services\PreorderService::class);
        $preorderActive = $preorders->isActive($product);

        $this->assertInStock($product, $combinationId, $qty, $preorderActive);

        $rentalPeriod = null;
        $rentalData = null;
        if ($product->isRental()) {
            $product->loadMissing(['rentalSetting', 'rentalPricingTiers', 'rentalAssets']);
            $rentals = app(\App\Domain\Catalog\Services\RentalProductService::class);
            $rentalPeriod = $rentals->resolvePeriod($product, $bookingDate, $bookingEndDate, $rentalDuration, $qty);
            $bookingDate = $rentalPeriod['start'];
        } else {
            $bookingDate = $this->resolveBookingDate($product, $bookingDate);
        }

        $product->loadMissing(['customizationFields', 'productSubscriptionPlans.plan', 'preorderSetting']);
        $resolved = $this->customizations->resolve($product, $customization, $files);
        $lines = $resolved['lines'];
        $planId = $this->resolvePlanId($product, $subscriptionPlanId);
        $context = DiscountContext::current($cart->customer);
        $baseUnit = $unitPriceOverride !== null
            ? max(0, round($unitPriceOverride, 2))
            : $this->lineUnitPrice($product, $qty, $context, $planId, $giftCardPayload, $combinationId, $rentalPeriod['duration'] ?? null);

        $preorderData = null;
        $preorderCustomer = null;
        if ($preorderActive) {
            $cart->loadMissing(['items', 'customer']);
            $preorderCustomer = $cart->customer;
            $existingPreorderQty = (float) $cart->items
                ->filter(fn (CartItem $row) => (int) $row->product_id === (int) $product->id
                    && is_array($row->preorder_data) && ! empty($row->preorder_data['enabled'])
                    && ! $preorders->isFinalPaymentData(is_array($row->preorder_data) ? $row->preorder_data : null))
                ->sum('qty');
            $preorders->assertCanPurchase($product, $qty, $existingPreorderQty, $preorderCustomer);
            $preorderData = $preorders->buildPreorderData($product, $qty, $baseUnit);
            $baseUnit = (float) $preorderData['charge_unit_price'];
        }

        $unit = round($baseUnit + $resolved['extra'], 2);

        if ($product->isRental() && $rentalPeriod) {
            $rentalData = app(\App\Domain\Catalog\Services\RentalProductService::class)
                ->buildRentalData($product, $unit);
        }

        $bookingEnd = $rentalPeriod['end'] ?? null;
        $durationVal = $rentalPeriod['duration'] ?? null;

        // Always reload — loadMissing keeps a stale collection after a prior add() on this cart.
        $cart->load('items');
        $item = $cart->items->first(function (CartItem $row) use ($product, $lines, $planId, $giftCardPayload, $combinationId, $bookingDate, $bookingEnd, $parentProductId, $bundleSelection) {
            if (app(\App\Domain\Catalog\Services\PreorderService::class)->isFinalPaymentData(is_array($row->preorder_data) ? $row->preorder_data : null)) {
                return false;
            }
            if ((int) $row->product_id !== (int) $product->id) {
                return false;
            }
            if ((int) ($row->parent_product_id ?: 0) !== (int) ($parentProductId ?: 0)) {
                return false;
            }
            if (json_encode($row->bundle_selection ?? null) !== json_encode($bundleSelection)) {
                return false;
            }
            if ((int) ($row->subscription_plan_id ?: 0) !== (int) ($planId ?: 0)) {
                return false;
            }
            if ((int) ($row->combination_id ?: 0) !== (int) ($combinationId ?: 0)) {
                return false;
            }
            // Rentals: one line per product in the cart (unique cart_id+product_id). Replace period on re-add.
            if ($product->isRental()) {
                if ($giftCardPayload) {
                    return json_encode($row->gift_card_data) === json_encode($giftCardPayload);
                }

                return $this->customizations->sameCustomization($row->customization, $lines);
            }
            if ((string) ($row->booking_date?->toDateString() ?? '') !== (string) ($bookingDate ?? '')) {
                return false;
            }
            if ((string) ($row->booking_end_date?->toDateString() ?? '') !== (string) ($bookingEnd ?? '')) {
                return false;
            }
            if ($giftCardPayload) {
                return json_encode($row->gift_card_data) === json_encode($giftCardPayload);
            }

            return $this->customizations->sameCustomization($row->customization, $lines);
        });

        if ($item) {
            $already = (float) $item->qty;
            $nextQty = ($product->isRental() || $product->isGiftCard())
                ? $qty
                : $this->quantityForLine($product, $requested, $already);
            $this->rememberQuantityAdjustment($product, $already, $already + $requested, $nextQty);
            $this->assertQuantityAllowed($cart, $nextQty, (int) $item->id);
            $this->assertInStock($product, $combinationId, $nextQty, $preorderActive);
            if ($product->isRental()) {
                $item->qty = $nextQty;
                $item->booking_date = $bookingDate;
                $item->booking_end_date = $bookingEnd;
                $item->rental_duration = $durationVal;
                $item->rental_data = $rentalData;
            } elseif ($product->isGiftCard()) {
                $item->qty = $nextQty;
                $item->gift_card_data = $giftCardPayload;
            } else {
                $item->qty = $nextQty;
            }
            if ($preorderActive) {
                $otherCartQty = (float) $cart->items
                    ->filter(fn (CartItem $row) => (int) $row->id !== (int) $item->id
                        && (int) $row->product_id === (int) $product->id
                        && is_array($row->preorder_data) && ! empty($row->preorder_data['enabled'])
                        && ! $preorders->isFinalPaymentData(is_array($row->preorder_data) ? $row->preorder_data : null))
                    ->sum('qty');
                $preorders->assertCanPurchase($product, (float) $item->qty, $otherCartQty, $preorderCustomer);
                $preorderData = $preorders->buildPreorderData($product, (float) $item->qty, $baseUnit);
                $unit = round((float) $preorderData['charge_unit_price'] + $resolved['extra'], 2);
                $item->preorder_data = $preorderData;
            }
            $item->unit_price = $unit;
            $item->parent_product_id = $parentProductId;
            $item->bundle_selection = $bundleSelection;
            $item->save();
        } else {
            $this->assertQuantityAllowed($cart, $qty);
            $this->rememberQuantityAdjustment($product, 0, $requested, $qty);
            $item = $cart->items()->create([
                'product_id' => $product->id,
                'parent_product_id' => $parentProductId,
                'combination_id' => $combinationId,
                'subscription_plan_id' => $planId,
                'qty' => $qty,
                'unit_price' => $unit,
                'customization' => $lines,
                'bundle_selection' => $bundleSelection,
                'gift_card_data' => $giftCardPayload,
                'booking_date' => $bookingDate,
                'booking_end_date' => $bookingEnd,
                'rental_duration' => $durationVal,
                'rental_data' => $rentalData,
                'preorder_data' => $preorderData,
            ]);
        }

        wento_hook('checkout.cart.updated', ['cart' => $cart]);

        return $item;
    }

    /**
     * Remaining pre-order balance as a cart line (qty 1 × remaining due).
     *
     * @param  array<string, mixed>  $settled
     */
    public function addPreorderFinalPayment(Cart $cart, \App\Domain\Order\Models\OrderItem $source, array $settled): CartItem
    {
        $product = $source->product;
        if (! $product) {
            abort(422, 'This pre-order product is no longer available.');
        }

        $remaining = round((float) ($settled['remaining'] ?? 0), 2);
        if ($remaining <= 0.009) {
            abort(422, 'This pre-order is already fully paid.');
        }

        $preorders = app(\App\Domain\Catalog\Services\PreorderService::class);
        $payload = $preorders->buildFinalPaymentCartData($source, $settled);
        $cart->load('items');
        $existing = $cart->items->first(function (CartItem $row) use ($source, $preorders) {
            $data = is_array($row->preorder_data) ? $row->preorder_data : [];
            if (! $preorders->isFinalPaymentData($data)) {
                return false;
            }

            return (int) ($data['source_order_item_id'] ?? 0) === (int) $source->id;
        });

        if ($existing) {
            $existing->qty = 1;
            $existing->unit_price = $remaining;
            $existing->preorder_data = $payload;
            $existing->combination_id = $source->combination_id;
            $existing->customization = $source->customization;
            $existing->save();
            wento_hook('checkout.cart.updated', ['cart' => $cart]);

            return $existing;
        }

        $item = $cart->items()->create([
            'product_id' => $product->id,
            'combination_id' => $source->combination_id,
            'qty' => 1,
            'unit_price' => $remaining,
            'customization' => $source->customization,
            'preorder_data' => $payload,
        ]);
        wento_hook('checkout.cart.updated', ['cart' => $cart]);

        return $item;
    }

    public function updateQty(Cart $cart, int $itemId, float $qty): CartItem
    {
        $item = $cart->items()->with(['product.seller'])->findOrFail($itemId);
        $product = $item->product;
        if (! $product) {
            abort(404);
        }
        if (app(\App\Domain\Catalog\Services\PreorderService::class)->isFinalPaymentData(is_array($item->preorder_data) ? $item->preorder_data : null)) {
            abort(422, 'Final payment quantity cannot be changed.');
        }

        $this->assertPurchasable($product);

        $before = (float) $item->qty;
        $wanted = $qty;
        $qty = $this->quantityForLine($product, $qty);
        $this->rememberQuantityAdjustment($product, $before, $wanted, $qty);
        $this->assertQuantityAllowed($cart, $qty, (int) $item->id);

        $this->assertInStock(
            $product,
            $item->combination_id ? (int) $item->combination_id : null,
            $qty,
            app(\App\Domain\Catalog\Services\PreorderService::class)->isActive($product),
        );

        $preorders = app(\App\Domain\Catalog\Services\PreorderService::class);
        if ($preorders->isActive($product)) {
            $cart->loadMissing(['items', 'customer']);
            $otherCartQty = (float) $cart->items
                ->filter(fn (CartItem $row) => (int) $row->id !== (int) $item->id
                    && (int) $row->product_id === (int) $product->id
                    && is_array($row->preorder_data) && ! empty($row->preorder_data['enabled'])
                    && ! $preorders->isFinalPaymentData(is_array($row->preorder_data) ? $row->preorder_data : null))
                ->sum('qty');
            $preorders->assertCanPurchase($product, $qty, $otherCartQty, $cart->customer);
        }

        $context = DiscountContext::current($cart->customer);
        $unit = $this->lineUnitPrice(
            $product,
            $qty,
            $context,
            $item->subscription_plan_id ? (int) $item->subscription_plan_id : null,
            is_array($item->gift_card_data) ? $item->gift_card_data : null,
            $item->combination_id ? (int) $item->combination_id : null,
            $item->rental_duration ? (int) $item->rental_duration : null,
        );
        $preorderData = null;
        if ($preorders->isActive($product)) {
            $preorderData = $preorders->buildPreorderData($product, $qty, $unit);
            $unit = (float) $preorderData['charge_unit_price'];
        }
        if ($item->gift_card_data || $item->parent_product_id || ! empty($item->bundle_selection) || $item->rental_duration) {
            $unit = (float) $item->unit_price;
        }

        $payload = [
            'qty' => $qty,
            'unit_price' => round($unit, 2),
        ];
        if ($preorderData) {
            $payload['preorder_data'] = $preorderData;
        }
        $item->update($payload);

        wento_hook('checkout.cart.updated', ['cart' => $cart]);

        return $item->fresh(['product.translations', 'product.seller']);
    }

    /**
     * Seller + listing gates for marketplace lines; platform products skip seller checks.
     *
     * @param  bool  $checkStock  When false, ignore out-of-stock policy (PDP still shows a disabled Add to cart + notify).
     */
    public function isPurchasable(Product $product, bool $checkStock = true): bool
    {
        $product->loadMissing('seller');

        if (! $product->is_enabled) {
            return false;
        }

        if (ProductSettings::catalogMode()) {
            return false;
        }

        if (! $product->seller_id) {
            if (! (bool) ($product->available_for_order ?? true)) {
                return false;
            }
            if (PricingSettings::blocksZeroPricePurchase((float) $product->sellingPrice())) {
                return false;
            }
            if (app(\App\Domain\Catalog\Services\PreorderService::class)->isActive($product)) {
                return true;
            }
            if ($checkStock && ProductSettings::blocksPurchaseWhenOutOfStock($product)) {
                return false;
            }

            return true;
        }

        if (! MarketplaceSettings::enabled()) {
            return false;
        }

        $seller = $product->seller;
        if (! $seller || ! $seller->isPublic() || $seller->vacation_enabled) {
            return false;
        }

        $copy = SellerProduct::query()
            ->where('seller_id', $seller->id)
            ->where('product_id', $product->id)
            ->first();

        if (! ($copy && $copy->isPubliclyListable())) {
            return false;
        }
        if (PricingSettings::blocksZeroPricePurchase((float) $product->sellingPrice())) {
            return false;
        }
        if (app(\App\Domain\Catalog\Services\PreorderService::class)->isActive($product)) {
            return true;
        }

        return ! ($checkStock && ProductSettings::blocksPurchaseWhenOutOfStock($product));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pullQuantityNotices(): array
    {
        $notices = $this->quantityNotices;
        $this->quantityNotices = [];

        return $notices;
    }

    /**
     * Min, step, and max for one cart line. A later add is capped with the quantity already in the cart.
     */
    protected function quantityForLine(Product $product, float $requested, float $already = 0): float
    {
        if ($product->isGiftCard()) {
            return 1.0;
        }
        if ($product->isRental()) {
            return SaleUnit::snapQty($product, $requested);
        }

        return SaleUnit::snapQty($product, $already + $requested);
    }

    protected function rememberQuantityAdjustment(Product $product, float $before, float $wanted, float $after): void
    {
        if ($product->isGiftCard() || $product->isRental()) {
            return;
        }
        if (abs($after - $wanted) < 0.0001) {
            return;
        }
        $rules = ProductSettings::quantityRules($product);
        $min = (float) $rules['min'];
        $max = $rules['max'];
        $unchanged = abs($after - $before) < 0.0001;
        $hitMax = $max !== null && $wanted > $after + 0.0001 && $after + 0.0001 >= (float) $max;
        if ($hitMax && $unchanged) {
            $code = 'at_max';
        } elseif ($unchanged && $wanted > $before + 0.0001) {
            // Next step would exceed max (e.g. min 1 / step 4 / max 4 → only qty 1 is valid).
            $code = 'no_increase';
        } elseif ($hitMax) {
            $code = 'capped';
        } elseif ($wanted + 0.0001 < $min && $after + 0.0001 >= $min) {
            $code = 'minimum';
        } else {
            $code = 'step';
        }
        $this->quantityNotices[] = [
            'code' => $code,
            'min' => $this->qtyNumber($min),
            'step' => $this->qtyNumber((float) $rules['step']),
            'max' => $max !== null ? $this->qtyNumber((float) $max) : null,
            'before' => $this->qtyNumber($before),
            'wanted' => $this->qtyNumber($wanted),
            'after' => $this->qtyNumber($after),
        ];
    }

    protected function qtyNumber(float $qty): int|float
    {
        return abs($qty - round($qty)) < 0.0001 ? (int) round($qty) : round($qty, 3);
    }

    protected function assertInStock(Product $product, ?int $combinationId, float $qty, bool $preorderActive): void
    {
        if ($product->isGiftCard() || $product->isRental() || $preorderActive) {
            return;
        }
        if (! InventorySettings::enabled() || InventorySettings::allowNegative() || ! CartCheckoutSettings::stockValidation()) {
            return;
        }
        if ($combinationId && $product->isCombination()) {
            $variant = ProductVariant::query()
                ->where('product_id', $product->id)
                ->whereKey($combinationId)
                ->first();
            if ($variant && ! $variant->inStockFor($qty)) {
                abort(422, 'Not enough stock for '.$variant->sku);
            }

            return;
        }
        if (! $product->inStockFor($qty)) {
            abort(422, 'Not enough stock for '.$product->sku);
        }
    }

    public function assertPurchasable(Product $product): void
    {
        if ($this->isPurchasable($product)) {
            return;
        }

        $product->loadMissing('seller');
        if ($product->seller_id && $product->seller && ! $product->seller->isPublic()) {
            abort(422, 'This seller is not available.');
        }

        abort(422, 'This product is not available for order.');
    }

    /**
     * Group cart lines by marketplace seller (null bucket = platform / non-marketplace).
     *
     * @return list<array{
     *   seller_id: int|null,
     *   shop_name: string,
     *   shop_slug: string|null,
     *   is_marketplace: bool,
     *   item_ids: list<int>,
     *   item_count: int,
     *   subtotal: float
     * }>
     */
    public function sellerGroups(Cart $cart): array
    {
        $cart->loadMissing(['items.product.seller']);
        $groups = [];
        $storeName = (string) (config('wento.name') ?: config('app.name') ?: 'Store');

        foreach ($cart->items as $item) {
            $sellerId = (int) ($item->product?->seller_id ?? 0);
            $key = $sellerId > 0 ? 's'.$sellerId : 'store';
            if (! isset($groups[$key])) {
                $seller = $item->product?->seller;
                $groups[$key] = [
                    'seller_id' => $sellerId > 0 ? $sellerId : null,
                    'shop_name' => $sellerId > 0
                        ? (string) ($seller?->shop_name ?: 'Seller')
                        : $storeName,
                    'shop_slug' => $sellerId > 0 ? ($seller?->shop_slug) : null,
                    'is_marketplace' => $sellerId > 0,
                    'item_ids' => [],
                    'item_count' => 0,
                    'subtotal' => 0.0,
                ];
            }
            $line = round((float) $item->unit_price * (float) $item->qty, 2);
            $groups[$key]['item_ids'][] = (int) $item->id;
            $groups[$key]['item_count']++;
            $groups[$key]['subtotal'] = round($groups[$key]['subtotal'] + $line, 2);
        }

        return array_values($groups);
    }

    /**
     * must meet that seller's minimum_order_amount.
     *
     * @return list<array{seller_id: int, shop_name: string, minimum: float, subtotal: float, message: string}>
     */
    public function minimumOrderViolations(Cart $cart): array
    {
        if (! \App\Domain\Marketplace\MarketplaceSettings::enableMinimumOrderAmount()) {
            return [];
        }
        \App\Domain\Marketplace\SellerMinimumOrderSchema::ensure();
        $violations = [];
        foreach ($this->sellerGroups($cart) as $group) {
            $sellerId = (int) ($group['seller_id'] ?? 0);
            if ($sellerId < 1) {
                continue;
            }
            $seller = \App\Domain\Marketplace\Models\Seller::query()->find($sellerId);
            $min = $seller?->minimum_order_amount;
            if ($min === null || (float) $min <= 0) {
                continue;
            }
            $sub = (float) ($group['subtotal'] ?? 0);
            if ($sub + 0.0001 >= (float) $min) {
                continue;
            }
            $shop = (string) ($group['shop_name'] ?? 'Seller');
            $violations[] = [
                'seller_id' => $sellerId,
                'shop_name' => $shop,
                'minimum' => round((float) $min, 2),
                'subtotal' => $sub,
                'message' => 'Minimum order amount for '.$shop.' is: '.number_format((float) $min, 2),
            ];
        }

        return $violations;
    }

    public function assertSellerMinimumOrders(Cart $cart): void
    {
        $violations = $this->minimumOrderViolations($cart);
        if ($violations === []) {
            return;
        }
        throw ValidationException::withMessages([
            'minimum_order' => $violations[0]['message'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $totals
     * @return array{id: int, items: mixed, sellers: list<array<string, mixed>>, discount_rule_id: int|null, coupon_id: int|null}
     */
    public function present(Cart $cart, array $totals = []): array
    {
        $cart->loadMissing([
            'items.product.translations',
            'items.product.seller',
            'items.subscriptionPlan.translations',
            'discountRule',
        ]);

        $groups = $this->sellerGroups($cart);
        // Prefer live catalog line totals from summary when available.
        if (! empty($totals['lines']) && is_array($totals['lines'])) {
            $byProduct = [];
            foreach ($totals['lines'] as $line) {
                $pid = (int) ($line['product_id'] ?? 0);
                if ($pid > 0) {
                    $byProduct[$pid] = (float) ($line['line_total'] ?? 0);
                }
            }
            foreach ($groups as &$group) {
                $sum = 0.0;
                foreach ($cart->items as $item) {
                    if (! in_array((int) $item->id, $group['item_ids'], true)) {
                        continue;
                    }
                    $pid = (int) $item->product_id;
                    $sum += $byProduct[$pid] ?? round((float) $item->unit_price * (float) $item->qty, 2);
                }
                $group['subtotal'] = round($sum, 2);
            }
            unset($group);
        }

        return [
            'id' => $cart->id,
            'minimum_order_violations' => $this->minimumOrderViolations($cart),
            'items' => $cart->items->map(function (CartItem $item) {
                $row = $item->toArray();
                $qty = (float) $item->qty;
                $row['qty'] = fmod($qty, 1.0) === 0.0 ? (int) $qty : $qty;
                $row['booking_date'] = $item->booking_date?->toDateString();
                $row['booking_end_date'] = $item->booking_end_date?->toDateString();
                $row['rental_duration'] = $item->rental_duration;
                $row['rental_data'] = is_array($item->rental_data) ? $item->rental_data : null;
                $row['preorder_data'] = is_array($item->preorder_data) ? $item->preorder_data : null;
                if ($item->product) {
                    $quantity = ProductSettings::quantityRules($item->product);
                    $row['product'] = array_merge(
                        is_array($row['product'] ?? null) ? $row['product'] : [],
                        [
                            'id' => $item->product->id,
                            'type' => $item->product->type,
                            'sku' => $item->product->sku,
                            'requires_shipping' => $item->product->requiresShipping(),
                            'min_qty' => $quantity['min'],
                            'qty_step' => $quantity['step'],
                            'max_qty' => $quantity['max'],
                        ],
                    );
                }

                return $row;
            })->values(),
            'sellers' => $groups,
            'discount_rule_id' => $cart->discount_rule_id,
            'coupon_id' => $cart->coupon_id,
            'gift_card_code' => is_array($cart->meta) ? ($cart->meta['gift_card_code'] ?? null) : null,
            'gift_wrap' => CartCheckoutSettings::giftWrapEnabled()
                && ! empty((is_array($cart->meta) ? $cart->meta : [])['gift_wrap']),
            'gift_message' => is_array($cart->meta) ? ($cart->meta['gift_message'] ?? null) : null,
            'apply_store_credit' => ! array_key_exists('apply_store_credit', is_array($cart->meta) ? $cart->meta : [])
                || ! empty((is_array($cart->meta) ? $cart->meta : [])['apply_store_credit']),
            'apply_loyalty' => ! array_key_exists('apply_loyalty', is_array($cart->meta) ? $cart->meta : [])
                || ! empty((is_array($cart->meta) ? $cart->meta : [])['apply_loyalty']),
        ];
    }

    public function applyCoupon(Cart $cart, string $code): DiscountRule
    {
        if (! CartCheckoutSettings::couponsEnabled()) {
            abort(422, 'Coupons are disabled.');
        }
        $rule = $this->discounts->findLiveCoupon($code);
        if (! $rule) {
            abort(422, 'This coupon cannot be applied.');
        }

        $cart->load(['items.product.categories', 'customer']);
        $context = DiscountContext::current($cart->customer);
        $priced = $this->catalogLines($cart, $context);
        $context->cartSubtotal = (float) array_sum(array_map(fn (array $line) => $line['line_total'], $priced));

        if (! $this->discounts->applies($rule, $context, $priced)) {
            abort(422, 'This coupon cannot be applied.');
        }

        $cart->update([
            'discount_rule_id' => $rule->id,
            'coupon_id' => null,
        ]);

        return $rule;
    }

    public function applyGiftCard(Cart $cart, string $code, ?string $clientKey = null): array
    {
        $this->giftCards->assertApplyAllowed($clientKey);

        try {
            $preview = $this->giftCards->preview($code);
        } catch (ValidationException $e) {
            $this->giftCards->recordFailedApply($clientKey);
            throw $e;
        }

        $meta = is_array($cart->meta) ? $cart->meta : [];
        $meta['gift_card_code'] = $preview['code'];
        $cart->update(['meta' => $meta]);

        $customer = $cart->customer_id
            ? \App\Domain\Customer\Models\Customer::query()->find($cart->customer_id)
            : null;
        $totals = $this->summary($cart, customer: $customer);

        try {
            $this->validateGiftCardForCart($cart, $totals);
        } catch (ValidationException $e) {
            $this->removeGiftCard($cart);
            $this->giftCards->recordFailedApply($clientKey);
            throw $e;
        }

        $this->giftCards->clearFailedApply($clientKey);

        return array_merge($preview, [
            'credit' => (float) ($totals['gift_card_credit'] ?? 0),
            'grand_total' => (float) ($totals['grand_total'] ?? 0),
        ]);
    }

    /**
     * Validate an attached gift card against the live cart total using the card's
     * own configured restrictions, so the customer is told at cart/checkout time
     * rather than after payment.
     */
    public function validateGiftCardForCart(Cart $cart, array $totals): void
    {
        $code = trim((string) (is_array($cart->meta) ? ($cart->meta['gift_card_code'] ?? '') : ''));
        if ($code === '') {
            return;
        }

        $grand = (float) ($totals['grand_total'] ?? 0) + (float) ($totals['gift_card_credit'] ?? 0);
        $credit = (float) ($totals['gift_card_credit'] ?? 0);

        $cart->loadMissing('items.product.categories');
        $lines = $this->giftCards->restrictionLines($cart->items);

        $this->giftCards->assertUsableForCart($code, $grand, $credit, $lines);
    }

    public function removeGiftCard(Cart $cart): void
    {
        $meta = is_array($cart->meta) ? $cart->meta : [];
        unset($meta['gift_card_code']);
        $cart->update(['meta' => $meta === [] ? null : $meta]);
    }

    public function assertCartWithinLimit(Cart $cart): void
    {
        $cart->load('items');
        $total = (float) $cart->items->sum('qty');
        $max = CartCheckoutSettings::maxCartItems();
        if ($total - $max > 0.00001) {
            abort(422, 'This cart can hold at most '.$max.' items.');
        }
    }

    public function assertQuantityAllowed(Cart $cart, float $nextQty, ?int $itemId = null): void
    {
        $cart->load('items');
        $total = 0.0;
        $seen = false;
        foreach ($cart->items as $row) {
            if ($itemId !== null && (int) $row->id === $itemId) {
                $total += $nextQty;
                $seen = true;
            } else {
                $total += (float) $row->qty;
            }
        }
        if (! $seen) {
            $total += $nextQty;
        }
        $max = CartCheckoutSettings::maxCartItems();
        if ($total - $max > 0.00001) {
            abort(422, 'This cart can hold at most '.$max.' items.');
        }
    }

    public function setGiftWrap(Cart $cart, bool $on, ?string $message = null): void
    {
        if ($on && ! CartCheckoutSettings::giftWrapEnabled()) {
            abort(422, 'Gift wrap is turned off.');
        }
        $meta = is_array($cart->meta) ? $cart->meta : [];
        if ($on) {
            $meta['gift_wrap'] = true;
            $meta['gift_message'] = $message !== null ? trim($message) : ($meta['gift_message'] ?? null);
        } else {
            unset($meta['gift_wrap'], $meta['gift_message']);
        }
        $cart->update(['meta' => $meta === [] ? null : $meta]);
    }

    public function setStoreCredit(Cart $cart, bool $on): void
    {
        $meta = is_array($cart->meta) ? $cart->meta : [];
        $meta['apply_store_credit'] = $on;
        $cart->update(['meta' => $meta]);
    }

    public function setLoyalty(Cart $cart, bool $on): void
    {
        $meta = is_array($cart->meta) ? $cart->meta : [];
        $meta['apply_loyalty'] = $on;
        $cart->update(['meta' => $meta]);
    }

    public function cartWeight(Cart $cart, $items = null): float
    {
        $cart->loadMissing('items.product');
        $weight = 0.0;
        $composition = app(\App\Domain\Catalog\Services\CompositionService::class);
        foreach ($items !== null ? $items : $cart->items as $item) {
            $product = $item->product;
            if (! $product || ! $product->requiresShipping()) {
                continue;
            }
            if (app(\App\Domain\Catalog\Services\PreorderService::class)->isFinalPaymentData(is_array($item->preorder_data) ? $item->preorder_data : null)) {
                continue;
            }
            if ($product->isComposite() && ($product->shipping_mode ?: 'derived') === 'derived') {
                $derived = $composition->shippingWeightForSelection(
                    $product,
                    is_array($item->bundle_selection) ? $item->bundle_selection : null,
                );
                if ($derived !== null) {
                    $weight += $derived * (float) $item->qty;

                    continue;
                }
            }
            $weight += SaleUnit::lineShippingWeight(
                $product,
                (float) $item->qty,
                $this->variantWeight($item),
            );
        }

        return round($weight, 3);
    }

    public function quoteShipping(Cart $cart, ?ShippingMethod $method, ?string $countryCode = null, $items = null): float
    {
        if (! $method) {
            return 0.0;
        }
        $cart->loadMissing('items.product');
        $source = $items !== null ? collect($items) : $cart->items;
        $subtotal = 0.0;
        foreach ($source as $item) {
            $subtotal += (float) $item->unit_price * (float) $item->qty;
        }
        $weight = $this->cartWeight($cart, $source);

        return $method->quoteFor($subtotal, $weight, [
            'country_code' => $countryCode,
            'cart_subtotal' => $subtotal,
            'cart_weight' => $weight,
        ]);
    }

    /**
     * @return array{
     *   subtotal: float, discount: float, tax: float, shipping: float, grand_total: float,
     *   currency: string, currency_rate: float, lines: array, tax_details: array,
     *   discount_rule_id: int|null
     * }
     */
    /**
     * @param  iterable<int, CartItem>|null  $items
     * @return array<string, mixed>
     */
    public function summaryForItems(
        Cart $cart,
        $items,
        float $shipping = 0,
        ?string $countryCode = null,
        ?string $regionCode = null,
        ?Customer $customer = null,
        ?string $currencyCode = null,
        bool $applyPayableCredits = true,
        ?ShippingMethod $shippingMethod = null,
    ): array {
        $original = $cart->relationLoaded('items') ? $cart->items : null;
        $cart->setRelation('items', collect($items)->values());
        try {
            return $this->summary(
                $cart,
                $shipping,
                $countryCode,
                $regionCode,
                $customer,
                $currencyCode,
                $applyPayableCredits,
                $shippingMethod,
            );
        } finally {
            if ($original !== null) {
                $cart->setRelation('items', $original);
            } else {
                $cart->unsetRelation('items');
            }
        }
    }

    public function summary(
        Cart $cart,
        float $shipping = 0,
        ?string $countryCode = null,
        ?string $regionCode = null,
        ?Customer $customer = null,
        ?string $currencyCode = null,
        bool $applyPayableCredits = true,
        ?ShippingMethod $shippingMethod = null,
    ): array {
        $cart->load(['items.product.categories', 'items.product.serviceSetting', 'items.product.translations', 'discountRule.conditions']);
        $customer ??= $cart->customer;
        $currency = $currencyCode ? strtoupper($currencyCode) : Money::currentCode();
        $context = DiscountContext::current($customer, $countryCode, $regionCode, $currency);

        $priced = $this->catalogLines($cart, $context);
        $subtotal = (float) array_sum(array_map(fn (array $line) => $line['line_total'], $priced));
        $context->cartSubtotal = $subtotal;

        $preorders = app(\App\Domain\Catalog\Services\PreorderService::class);
        $onlyFinalPayment = $cart->items->isNotEmpty() && $cart->items->every(
            fn (CartItem $item) => $preorders->isFinalPaymentData(is_array($item->preorder_data) ? $item->preorder_data : null)
        );
        $cartDiscount = $onlyFinalPayment
            ? ['amount' => 0.0, 'rule' => null]
            : $this->discounts->cartReduction($cart, $context, $priced);
        $discount = $cartDiscount['amount'];
        $taxableBase = max(0, $subtotal - $discount);

        $lines = [];
        $taxTotal = 0.0;
        $taxDetails = [];
        $lineCount = max(1, count($priced));
        $allocatedDiscount = 0.0;
        $i = 0;

        foreach ($priced as $row) {
            $i++;
            $item = $row['item'];
            $lineTotal = $row['line_total'];
            $share = $subtotal > 0 ? ($lineTotal / $subtotal) * $discount : ($discount / $lineCount);
            if ($i === count($priced)) {
                $share = $discount - $allocatedDiscount;
            }
            $allocatedDiscount += $share;
            $lineTaxable = max(0, $lineTotal - $share);

            $resolved = $this->tax->resolve(
                $item->product?->tax_class_id,
                $countryCode,
                $regionCode,
                $customer
            );
            $layers = $this->tax->resolveLayers(
                $item->product?->tax_class_id,
                $countryCode,
                $regionCode,
                $customer
            );
            $applied = $this->tax->applyLayers($lineTaxable, $layers, PricingSettings::taxInclusiveInput());
            $lineTax = $applied['tax'];
            $taxTotal += $lineTax;
            foreach ($applied['details'] as $key => $amount) {
                $taxDetails[$key] = ($taxDetails[$key] ?? 0) + $amount;
            }

            $lines[] = [
                'cart_item_id' => (int) $item->id,
                'product_id' => $item->product_id,
                'tax_class_id' => $item->product?->tax_class_id,
                'qty' => $item->qty,
                'unit_price' => $row['unit_price'],
                'line_total' => $lineTotal,
                'discount_share' => round($share, 2),
                'taxable' => round($lineTaxable, 2),
                'tax_rate' => $applied['effective_rate'] ?: $resolved['rate'],
                'tax_amount' => $lineTax,
                'tax_name' => $resolved['name'] ?: ($layers[0]['name'] ?? null),
            ];
        }

        $shipTax = 0.0;
        $inclusiveInput = PricingSettings::taxInclusiveInput();
        // Shipping tax only when enabled and the method has an explicit tax class — no product fallbacks.
        $shipClass = PricingSettings::shippingTax() && $shipping > 0 && $shippingMethod?->tax_class_id
            ? (int) $shippingMethod->tax_class_id
            : null;
        if ($shipClass) {
            $shipResolved = $this->tax->resolve($shipClass, $countryCode, $regionCode, $customer);
            $shipLayers = $this->tax->resolveLayers($shipClass, $countryCode, $regionCode, $customer);
            $shipApplied = $this->tax->applyLayers($shipping, $shipLayers, $inclusiveInput);
            $shipTax = $shipApplied['tax'];
            if ($shipTax > 0) {
                $taxTotal += $shipTax;
                foreach ($shipApplied['details'] as $key => $amount) {
                    $taxDetails[$key] = ($taxDetails[$key] ?? 0) + $amount;
                }
                if ($shipApplied['details'] === []) {
                    $key = ($shipResolved['name'] ?? 'Shipping tax').'|'.$shipResolved['rate'];
                    $taxDetails[$key] = ($taxDetails[$key] ?? 0) + $shipTax;
                }
            }
        }

        $taxTotal = round($taxTotal, 2);
        $shipTax = round($shipTax, 2);
        // Inclusive amounts already contain tax — do not add extracted shipping tax again.
        $grand = $inclusiveInput
            ? round($taxableBase + $shipping, 2)
            : round($taxableBase + $taxTotal + $shipping, 2);

        $credits = [
            'grand_total' => $grand,
            'gift_wrap_fee' => 0.0,
            'gift_card_code' => null,
            'gift_card_credit' => 0.0,
            'store_credit' => 0.0,
            'store_credit_balance' => $customer ? app(\App\Domain\Customer\Services\StoreCreditService::class)->balance($customer) : 0.0,
            'loyalty' => 0.0,
            'loyalty_points' => 0,
            'apply_store_credit' => true,
            'apply_loyalty' => true,
        ];
        if ($applyPayableCredits) {
            $credits = $this->applyPayableCredits($cart, $grand, $customer, $currency);
            $grand = (float) $credits['grand_total'];
        }
        $giftWrapFee = (float) $credits['gift_wrap_fee'];
        $giftCardCode = (string) ($credits['gift_card_code'] ?? '');
        $giftCardCredit = (float) $credits['gift_card_credit'];
        $storeCredit = (float) $credits['store_credit'];
        $storeCreditBalance = (float) $credits['store_credit_balance'];
        $loyalty = (float) $credits['loyalty'];
        $loyaltyBalance = (int) $credits['loyalty_points'];
        $applyStoreCredit = (bool) $credits['apply_store_credit'];
        $applyLoyalty = (bool) $credits['apply_loyalty'];

        if (strtoupper($currency) !== strtoupper(Money::defaultCode())) {
            $fx = fn (float $amount) => Money::fromDefault($amount, $currency);
            $subtotal = $fx($subtotal);
            $discount = $fx($discount);
            $taxTotal = $fx($taxTotal);
            $shipping = $fx($shipping);
            $shipTax = $fx($shipTax);
            $giftCardCredit = $fx($giftCardCredit);
            $giftWrapFee = $fx($giftWrapFee);
            $storeCredit = $fx($storeCredit);
            $storeCreditBalance = $fx($storeCreditBalance);
            $loyalty = $fx($loyalty);
            $grand = $fx($grand);
            $lines = array_map(function (array $line) use ($fx) {
                foreach (['unit_price', 'line_total', 'discount_share', 'taxable', 'tax_amount'] as $key) {
                    $line[$key] = $fx((float) $line[$key]);
                }

                return $line;
            }, $lines);
            foreach ($taxDetails as $key => $amount) {
                $taxDetails[$key] = $fx((float) $amount);
            }
        }

        $adjust = ModuleBuses::collectCheckoutAdjustments([
            'subtotal' => round($subtotal, 2),
            'discount' => round($discount, 2),
            'tax' => $taxTotal,
            'shipping' => round($shipping, 2),
            'grand_total' => $grand,
            'currency' => $currency,
            'cart' => $cart,
            'customer' => $customer,
            'cart_meta' => is_array($cart->meta) ? $cart->meta : [],
        ]);

        $feeTotal = (float) ($adjust['fees'] ?? 0);
        $creditTotal = (float) ($adjust['credits'] ?? 0);
        $travel = $this->serviceTravelFees($cart);
        $rentalFees = $this->rentalFees($cart);
        $feeTotal = round($feeTotal + (float) $travel['total'] + (float) $rentalFees['total'], 2);
        $adjustments = array_values(array_merge(
            is_array($adjust['adjustments'] ?? null) ? $adjust['adjustments'] : [],
            $travel['adjustments'],
            $rentalFees['adjustments'],
        ));
        if ($feeTotal > 0 && strtoupper($currency) !== strtoupper(Money::defaultCode())) {
            // Fees returned by adjusters are already in the same currency space as $grand.
        }
        $grandWithFees = round(max(0, $grand + $feeTotal - $creditTotal), 2);
        $dueNow = $adjust['due_now'];
        $balanceDue = $dueNow !== null ? round(max(0, $grandWithFees - $dueNow), 2) : 0.0;

        return $this->withStorefrontPriceDisplay([
            'subtotal' => round($subtotal, 2),
            'discount' => round($discount, 2),
            'tax' => $taxTotal,
            'shipping' => round($shipping, 2),
            'shipping_tax' => $shipTax,
            'fee_total' => round($feeTotal, 2),
            'travel_fee_total' => (float) $travel['total'],
            'rental_deposit_total' => (float) $rentalFees['deposit'],
            'rental_cleaning_fee_total' => (float) $rentalFees['cleaning'],
            'credit_total' => round($creditTotal, 2),
            'grand_total' => $grandWithFees,
            'due_now' => $dueNow,
            'balance_due' => $balanceDue,
            'adjustments' => $adjustments,
            'currency' => $currency,
            'currency_rate' => Money::rateFor($currency),
            'lines' => $lines,
            'tax_details' => collect($taxDetails)->map(fn ($amount, $key) => [
                'label' => explode('|', (string) $key)[0],
                'rate' => (float) explode('|', (string) $key)[1],
                'amount' => round($amount, 2),
            ])->values()->all(),
            'discount_rule_id' => $cartDiscount['rule']?->id,
            'discount_code' => $cartDiscount['rule']?->code,
            'discount_name' => $cartDiscount['rule']?->name,
            'gift_card_code' => $giftCardCode !== '' ? $giftCardCode : null,
            'gift_card_credit' => round($giftCardCredit, 2),
            'gift_wrap' => $giftWrapFee > 0,
            'gift_wrap_fee' => round($giftWrapFee, 2),
            'store_credit' => round($storeCredit, 2),
            'store_credit_balance' => round($storeCreditBalance, 2),
            'loyalty' => round($loyalty, 2),
            'loyalty_points' => $loyaltyBalance,
            'apply_store_credit' => $applyStoreCredit,
            'apply_loyalty' => $applyLoyalty,
        ]);
    }

    /**
     * Add storefront display amounts so mini-cart / cart UI match PDP (tax incl vs excl).
     * Does not change grand_total or tax math — only presentation fields.
     *
     * @param  array<string, mixed>  $totals
     * @return array<string, mixed>
     */
    protected function withStorefrontPriceDisplay(array $totals): array
    {
        $inclusiveInput = PricingSettings::taxInclusiveInput();
        $showIncl = PricingSettings::storefrontShowsInclusive();
        $lines = [];
        $displaySubtotal = 0.0;

        foreach ($totals['lines'] ?? [] as $line) {
            $qty = max(0.0001, (float) ($line['qty'] ?? 1));
            $unit = (float) ($line['unit_price'] ?? 0);
            $lineTotal = (float) ($line['line_total'] ?? 0);
            $tax = (float) ($line['tax_amount'] ?? 0);

            if ($inclusiveInput) {
                $inclLine = $lineTotal;
                $exclLine = max(0, round($lineTotal - $tax, 2));
                $inclUnit = $unit;
                $exclUnit = round($exclLine / $qty, 2);
            } else {
                $exclLine = $lineTotal;
                $inclLine = round($lineTotal + $tax, 2);
                $exclUnit = $unit;
                $inclUnit = round($inclLine / $qty, 2);
            }

            $displayUnit = $showIncl ? $inclUnit : $exclUnit;
            $displayLine = $showIncl ? $inclLine : $exclLine;
            $displaySubtotal += $displayLine;

            $lines[] = array_merge($line, [
                'unit_price_excl' => $exclUnit,
                'unit_price_incl' => $inclUnit,
                'line_total_excl' => $exclLine,
                'line_total_incl' => $inclLine,
                'display_unit_price' => $displayUnit,
                'display_line_total' => $displayLine,
            ]);
        }

        $totals['lines'] = $lines;
        $totals['display_subtotal'] = round($displaySubtotal, 2);
        $totals['storefront_display'] = PricingSettings::storefrontDisplay();
        $totals['prices_include_tax'] = $showIncl;

        return $totals;
    }

    /**
     * Gift wrap / gift card / store credit / loyalty after merchandise + shipping.
     *
     * @return array{
     *   grand_total: float,
     *   gift_wrap_fee: float,
     *   gift_card_code: ?string,
     *   gift_card_credit: float,
     *   store_credit: float,
     *   store_credit_balance: float,
     *   loyalty: float,
     *   loyalty_points: int,
     *   apply_store_credit: bool,
     *   apply_loyalty: bool
     * }
     */
    public function applyPayableCredits(Cart $cart, float $grand, ?Customer $customer, string $currency): array
    {
        $meta = is_array($cart->meta) ? $cart->meta : [];
        $giftWrapFee = 0.0;
        if (! empty($meta['gift_wrap']) && CartCheckoutSettings::giftWrapEnabled()) {
            $giftWrapFee = CartCheckoutSettings::giftWrapFee();
            $grand = round($grand + $giftWrapFee, 2);
        }

        $giftCardCode = trim((string) ($meta['gift_card_code'] ?? ''));
        $giftCardCredit = 0.0;
        if ($giftCardCode !== '') {
            try {
                $preview = $this->giftCards->preview($giftCardCode);
                $giftCardCredit = min((float) $preview['balance'], $grand);
                $grand = round(max(0, $grand - $giftCardCredit), 2);
            } catch (ValidationException) {
                $giftCardCode = '';
                $giftCardCredit = 0.0;
            }
        }

        $storeCredit = 0.0;
        $storeCreditBalance = $customer ? app(\App\Domain\Customer\Services\StoreCreditService::class)->balance($customer) : 0.0;
        $applyStoreCredit = $customer && (! array_key_exists('apply_store_credit', $meta) || ! empty($meta['apply_store_credit']));
        if ($applyStoreCredit && $storeCreditBalance > 0) {
            $storeCredit = min($storeCreditBalance, $grand);
            $grand = round(max(0, $grand - $storeCredit), 2);
        }

        $loyalty = 0.0;
        $loyaltyBalance = 0;
        $applyLoyalty = $customer && \App\Domain\Settings\LoyaltySettings::enabled()
            && (! array_key_exists('apply_loyalty', $meta) || ! empty($meta['apply_loyalty']));
        if ($applyLoyalty && $customer) {
            $loyaltyBalance = app(\App\Domain\Customer\Services\LoyaltyService::class)->balance($customer);
            $loyaltyValue = app(\App\Domain\Customer\Services\LoyaltyService::class)->valueOf($loyaltyBalance);
            $loyalty = min($loyaltyValue, $grand);
            $grand = round(max(0, $grand - $loyalty), 2);
        }

        return [
            'grand_total' => round($grand, 2),
            'gift_wrap_fee' => round($giftWrapFee, 2),
            'gift_card_code' => $giftCardCode !== '' ? $giftCardCode : null,
            'gift_card_credit' => round($giftCardCredit, 2),
            'store_credit' => round($storeCredit, 2),
            'store_credit_balance' => round($storeCreditBalance, 2),
            'loyalty' => round($loyalty, 2),
            'loyalty_points' => $loyaltyBalance,
            'apply_store_credit' => $applyStoreCredit,
            'apply_loyalty' => $applyLoyalty,
        ];
    }

    protected function taxOnAmount(float $amount, float $rate): float
    {
        if ($amount <= 0 || $rate <= 0) {
            return 0.0;
        }
        if (PricingSettings::taxInclusiveInput()) {
            return round($amount * $rate / (100 + $rate), 2);
        }

        return round($amount * ($rate / 100), 2);
    }

    /**
     * @return list<array{item: CartItem, product: ?Product, qty: float, unit_price: float, line_total: float}>
     */
    protected function catalogLines(Cart $cart, DiscountContext $context): array
    {
        $lines = [];
        foreach ($cart->items as $item) {
            $product = $item->product;
            $qty = (float) $item->qty;
            $unit = $product
                ? $this->lineUnitPrice(
                    $product,
                    $qty,
                    $context,
                    $item->subscription_plan_id ? (int) $item->subscription_plan_id : null,
                    is_array($item->gift_card_data) ? $item->gift_card_data : null,
                    $item->combination_id ? (int) $item->combination_id : null,
                    $item->rental_duration ? (int) $item->rental_duration : null,
                )
                : (float) $item->unit_price;
            // Group pack lines and bundle selection quotes keep the unit frozen at add time.
            // Active pre-order lines also keep charge/deposit unit price frozen.
            $lockedQuote = ! empty((is_array($cart->meta) ? $cart->meta : [])['quote_id']);
            $lockedPreorder = is_array($item->preorder_data) && ! empty($item->preorder_data['enabled']);
            if ($lockedQuote || $item->parent_product_id || ! empty($item->bundle_selection) || $item->rental_duration || $lockedPreorder) {
                $unit = (float) $item->unit_price;
            }
            $lines[] = [
                'item' => $item,
                'product' => $product,
                'qty' => $qty,
                'unit_price' => $unit,
                'line_total' => round($unit * $qty, 2),
            ];
        }

        return $lines;
    }

    protected function merge(Cart $from, Cart $to): void
    {
        $to->loadMissing('items');
        foreach ($from->items as $item) {
            $match = $to->items->first(function (CartItem $row) use ($item) {
                return (int) $row->product_id === (int) $item->product_id
                    && (int) ($row->parent_product_id ?: 0) === (int) ($item->parent_product_id ?: 0)
                    && (int) ($row->subscription_plan_id ?: 0) === (int) ($item->subscription_plan_id ?: 0)
                    && (int) ($row->combination_id ?: 0) === (int) ($item->combination_id ?: 0)
                    && (string) ($row->booking_date?->toDateString() ?? '') === (string) ($item->booking_date?->toDateString() ?? '')
                    && $this->customizations->sameCustomization($row->customization, $item->customization);
            });
            if ($match) {
                $match->loadMissing('product');
                $product = $match->product;
                $match->qty = $product
                    ? $this->quantityForLine($product, (float) $item->qty, (float) $match->qty)
                    : ((float) $match->qty + (float) $item->qty);
                $match->save();
            } else {
                $item->loadMissing('product');
                $to->items()->create([
                    'product_id' => $item->product_id,
                    'combination_id' => $item->combination_id,
                    'subscription_plan_id' => $item->subscription_plan_id,
                    'qty' => $item->product
                        ? $this->quantityForLine($item->product, (float) $item->qty)
                        : $item->qty,
                    'unit_price' => $item->unit_price,
                    'customization' => $item->customization,
                    'gift_card_data' => $item->gift_card_data,
                    'booking_date' => $item->booking_date,
                ]);
            }
        }
        $from->delete();
    }

    protected function resolvePlanId(Product $product, ?int $subscriptionPlanId): ?int
    {
        $enabled = (bool) $product->subscription_enabled;
        $option = $product->purchase_option ?: 'one_time';
        $allowsSubscription = $enabled && in_array($option, ['subscription', 'both'], true);
        $requiresSubscription = $enabled && $option === 'subscription';

        if (! $allowsSubscription) {
            if ($subscriptionPlanId) {
                throw ValidationException::withMessages([
                    'subscription_plan_id' => 'This product is not available as a subscription.',
                ]);
            }

            return null;
        }

        if (! $subscriptionPlanId) {
            if ($requiresSubscription) {
                throw ValidationException::withMessages([
                    'subscription_plan_id' => 'Choose a subscription plan for this product.',
                ]);
            }

            return null;
        }

        // Subscriptions require a customer account (saved methods, self-service, renewals).
        if (! auth('customer')->check()) {
            throw ValidationException::withMessages([
                'subscription_plan_id' => 'Sign in to add a subscription to your cart.',
            ]);
        }

        $plan = SubscriptionPlan::query()->find($subscriptionPlanId);
        if (! $plan) {
            throw ValidationException::withMessages([
                'subscription_plan_id' => 'This subscription plan is not available.',
            ]);
        }

        $this->subscriptions->assignmentPrice($product, $plan);

        return $plan->id;
    }

    protected function unitPrice(Product $product, ?int $planId): float
    {
        if (! $planId) {
            return (float) $product->sellingPrice();
        }

        $plan = SubscriptionPlan::query()->findOrFail($planId);

        return $this->subscriptions->assignmentPrice($product, $plan);
    }

    /**
     * @param  array<string, mixed>|null  $giftCardPayload
     */
    protected function lineUnitPrice(Product $product, float $qty, DiscountContext $context, ?int $planId, ?array $giftCardPayload, ?int $combinationId, ?int $rentalDuration = null): float
    {
        if ($giftCardPayload) {
            return (float) ($giftCardPayload['selling_price'] ?? $product->sellingPrice());
        }
        if ($planId) {
            return $this->unitPrice($product, $planId);
        }
        if ($combinationId) {
            $variant = ProductVariant::query()
                ->where('product_id', $product->id)
                ->where('id', $combinationId)
                ->where('is_enabled', true)
                ->first();
            if ($variant && $variant->price !== null && $variant->price !== '') {
                return \App\Domain\Settings\CustomerSettings::applyGroupDiscount($variant->sellingPrice(), $context->customer);
            }
        }

        $unit = $this->discounts->catalogUnitPrice($product, $context->forProduct($product, $qty));
        if ($product->isService()) {
            $product->loadMissing('serviceSetting');

            return app(\App\Domain\Catalog\Services\ServiceProductService::class)
                ->applyPricingModel($product, $unit);
        }
        if ($product->isRental()) {
            $product->loadMissing(['rentalSetting', 'rentalPricingTiers']);
            $duration = $rentalDuration ?: max(1, (int) ($product->rentalSetting?->default_duration ?: 1));

            return app(\App\Domain\Catalog\Services\RentalProductService::class)
                ->unitPriceForDuration($product, $duration, $unit);
        }

        return $unit;
    }

    protected function variantWeight(\App\Domain\Checkout\Models\CartItem $item): ?float
    {
        if (! $item->combination_id) {
            return null;
        }
        $weight = ProductVariant::query()->whereKey($item->combination_id)->value('weight');

        return $weight === null || $weight === '' ? null : (float) $weight;
    }

    protected function resolveCombinationId(Product $product, ?int $combinationId): ?int
    {
        if ($product->isCombination()) {
            if (! $combinationId) {
                throw ValidationException::withMessages([
                    'combination_id' => 'Choose a combination for this product.',
                ]);
            }
            $variant = ProductVariant::query()
                ->where('product_id', $product->id)
                ->where('id', $combinationId)
                ->where('is_enabled', true)
                ->first();
            if (! $variant) {
                throw ValidationException::withMessages([
                    'combination_id' => 'That combination is not available.',
                ]);
            }

            return (int) $variant->id;
        }

        if (! $combinationId) {
            return null;
        }

        $exists = ProductVariant::query()
            ->where('product_id', $product->id)
            ->where('id', $combinationId)
            ->where('is_enabled', true)
            ->exists();
        if (! $exists) {
            throw ValidationException::withMessages([
                'combination_id' => 'That combination is not available.',
            ]);
        }

        return $combinationId;
    }

    protected function resolveBookingDate(Product $product, ?string $bookingDate): ?string
    {
        $services = app(\App\Domain\Catalog\Services\ServiceProductService::class);
        $needs = $services->requiresBookingDate($product);
        $date = trim((string) $bookingDate);
        if ($date === '') {
            if ($needs) {
                throw ValidationException::withMessages([
                    'booking_date' => 'Choose a date for this booking.',
                ]);
            }

            return null;
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw ValidationException::withMessages([
                'booking_date' => 'Use a valid date (YYYY-MM-DD).',
            ]);
        }

        if ($product->isService()) {
            $product->loadMissing('serviceSetting');
            $services->assertBookingAllowed($product, $date);
        }

        return $date;
    }

    /**
     * @return array{total: float, adjustments: list<array{code: string, label: string, amount: float}>}
     */
    protected function serviceTravelFees(Cart $cart): array
    {
        $services = app(\App\Domain\Catalog\Services\ServiceProductService::class);
        $total = 0.0;
        $adjustments = [];
        foreach ($cart->items as $item) {
            $product = $item->product;
            if (! $product?->isService()) {
                continue;
            }
            $product->loadMissing('serviceSetting');
            $per = $services->travelFeePerUnit($product);
            if ($per <= 0) {
                continue;
            }
            $amount = round($per * (float) $item->qty, 2);
            $total += $amount;
            $name = $product->relationLoaded('translations')
                ? ((string) ($product->translation()?->name ?: $product->sku))
                : (string) $product->sku;
            $adjustments[] = [
                'code' => 'service_travel_fee_'.$item->id,
                'type' => 'fee',
                'label' => 'Travel fee: '.$name,
                'amount' => $amount,
            ];
        }

        return ['total' => round($total, 2), 'adjustments' => $adjustments];
    }

    /**
     * @return array{total: float, deposit: float, cleaning: float, adjustments: list<array{code: string, type: string, label: string, amount: float}>}
     */
    protected function rentalFees(Cart $cart): array
    {
        $total = 0.0;
        $deposit = 0.0;
        $cleaning = 0.0;
        $adjustments = [];
        foreach ($cart->items as $item) {
            $product = $item->product;
            if (! $product?->isRental()) {
                continue;
            }
            $data = is_array($item->rental_data) ? $item->rental_data : [];
            $dep = round(((float) ($data['deposit'] ?? 0)) * (float) $item->qty, 2);
            $clean = round(((float) ($data['cleaning_fee'] ?? 0)) * (float) $item->qty, 2);
            $name = $product->relationLoaded('translations')
                ? ((string) ($product->translation()?->name ?: $product->sku))
                : (string) $product->sku;
            if ($dep > 0) {
                $deposit += $dep;
                $total += $dep;
                $adjustments[] = [
                    'code' => 'rental_deposit_'.$item->id,
                    'type' => 'deposit',
                    'label' => 'Security deposit: '.$name,
                    'amount' => $dep,
                ];
            }
            if ($clean > 0) {
                $cleaning += $clean;
                $total += $clean;
                $adjustments[] = [
                    'code' => 'rental_cleaning_'.$item->id,
                    'type' => 'fee',
                    'label' => 'Cleaning fee: '.$name,
                    'amount' => $clean,
                ];
            }
        }

        return [
            'total' => round($total, 2),
            'deposit' => round($deposit, 2),
            'cleaning' => round($cleaning, 2),
            'adjustments' => $adjustments,
        ];
    }
}
