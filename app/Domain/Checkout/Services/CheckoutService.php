<?php

namespace App\Domain\Checkout\Services;

use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Checkout\CheckoutSnapshot;
use App\Domain\Checkout\Models\Cart;
use App\Domain\Checkout\Models\CheckoutGroup;
use App\Domain\Customer\Models\Customer;
use App\Domain\Localization\Money;
use App\Domain\Settings\CustomerSettings;
use App\Domain\Marketplace\Services\SellerService;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Services\OrderNumberService;
use App\Domain\Payment\CheckoutLifecycle;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Payment\PaymentCaptureResult;
use App\Domain\Payment\PaymentGatewayRegistry;
use App\Domain\Payment\PaymentVerificationResult;
use App\Domain\Payment\Services\PaymentCheckoutService;
use App\Domain\Promo\Models\DiscountRule;
use App\Domain\Promo\Models\DiscountRedemption;
use App\Domain\Settings\CartCheckoutSettings;
use App\Domain\Settings\InventorySettings;
use App\Domain\Settings\OrderSettings;
use App\Domain\Settings\ProductSettings;
use App\Domain\Settings\ShopMailer;
use App\Domain\Order\OrderStageService;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Domain\Store\StoreContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CheckoutService
{
    public function __construct(
        protected CartService $carts,
        protected InventoryService $inventory,
        protected PaymentGatewayRegistry $gateways,
        protected OrderNumberService $orderNumbers,
        protected CheckoutSnapshotBuilder $snapshots,
    ) {}

    /**
     * @return array{order: Order, payment: PaymentCaptureResult, seller_orders: list<array<string, mixed>>}
     */
    public function place(Cart $cart, array $payload, ?Customer $customer = null): array
    {
        // Never trust client financial / ownership fields.
        unset(
            $payload['seller_id'],
            $payload['product_price'],
            $payload['unit_price'],
            $payload['subtotal'],
            $payload['tax_total'],
            $payload['tax_amount'],
            $payload['discount_total'],
            $payload['discount_amount'],
            $payload['shipping_total'],
            $payload['shipping_amount'],
            $payload['grand_total'],
            $payload['order_total'],
            $payload['seller_total'],
            $payload['seller_orders'],
        );

        $payload = wento_action('checkout.place', 'before', $payload);

        $paymentMethodId = (int) ($payload['payment_method_id'] ?? 0);
        $payment = PaymentMethod::query()->where('is_enabled', true)->find($paymentMethodId);
        if (! $payment) {
            abort(422, 'Payment method is unavailable.');
        }

        $driver = strtolower((string) ($payment->driver ?: 'offline'));
        $gateway = $this->gateways->get($driver);

        if ($gateway->checkoutLifecycle() === CheckoutLifecycle::PaymentFirst) {
            $result = app(PaymentCheckoutService::class)->settle($cart, $payload, $customer);
            $this->loginCheckoutCustomer($result['order'] ?? null);

            return [
                'order' => $result['order'] ?? null,
                'orders' => $result['orders'] ?? [],
                'checkout_group' => $result['checkout_group'] ?? null,
                'payment' => PaymentCaptureResult::fromArray(is_array($result['payment'] ?? null) ? $result['payment'] : []),
                'seller_orders' => $result['seller_orders'] ?? [],
                'checkout_lifecycle' => $result['checkout_lifecycle'] ?? CheckoutLifecycle::PaymentFirst->value,
                'session_token' => $result['session_token'] ?? null,
            ];
        }

        return $this->placeOrderFirst($cart, $payload, $customer);
    }

    /**
     * @return array{order: Order, payment: PaymentCaptureResult, seller_orders: list<array<string, mixed>>}
     */
    protected function placeOrderFirst(Cart $cart, array $payload, ?Customer $customer = null): array
    {
        $result = DB::transaction(function () use ($cart, $payload, $customer) {
            $snapshot = $this->snapshots->build($cart, $payload, $customer);

            return $this->fulfillFromSnapshot($snapshot, null, deferGatewayCapture: true);
        });

        if (! empty($result['deferred_capture'])) {
            $result = $this->completeDeferredCapture($result);
        }

        $this->loginCheckoutCustomer($result['order'] ?? null);

        session([
            'wento.last_order' => $result['order']->number,
            'wento.last_order_email' => $result['order']->email,
            'wento.last_checkout_reference' => $result['order']->checkout_reference,
        ]);

        return [
            'order' => $result['order'],
            'orders' => $result['orders'] ?? [$result['order']],
            'checkout_group' => $result['checkout_group'] ?? null,
            'payment' => $result['payment'] ?? PaymentCaptureResult::pending(),
            'seller_orders' => $result['seller_orders'],
        ];
    }

    /**
     * @return array{
     *     order: Order,
     *     payment: PaymentCaptureResult|null,
     *     seller_orders: list<array<string, mixed>>,
     *     deferred_capture?: bool,
     *     capture_payload?: array<string, mixed>,
     *     payment_driver?: string,
     *     has_due_now?: bool,
     *     amount_due?: float|null,
     *     grand?: float,
     * }
     */
    public function fulfillFromSnapshot(
        CheckoutSnapshot $snapshot,
        ?PaymentVerificationResult $verification = null,
        bool $deferGatewayCapture = false,
    ): array {
        $cart = $snapshot->cart;
        $payload = $snapshot->payload;
        $customer = $snapshot->customer;
        $payment = $snapshot->paymentMethod;
        $shipping = $snapshot->shippingMethod;
        $totals = $snapshot->totals;
        $hasDueNow = $snapshot->hasDueNow;
        $dueNow = $snapshot->captureAmount;
        $amountDue = $snapshot->amountDue;
        $grand = $snapshot->grandTotal;
        $feeTotal = round((float) ($totals['fee_total'] ?? 0), 2);

        $initialStatus = ($hasDueNow && $amountDue !== null && $amountDue > 0.009) ? 'partial' : 'pending';
        if ($verification?->verified) {
            $initialStatus = ($hasDueNow && $amountDue !== null && $amountDue > 0.009) ? 'partial' : 'paid';
        }

        $initialOrderStatus = OrderSettings::defaultStatus();

        $wantsAccount = ! CustomerSettings::guestCheckout()
            || ! empty($payload['create_account'])
            || ! empty($payload['password']);
        if (! $customer && $wantsAccount) {
            $customer = $this->openCheckoutAccount($cart, $payload);
        }

        $cart->loadMissing('items');
        $hasSubscriptionLine = $cart->items->contains(
            static fn ($item) => (int) ($item->subscription_plan_id ?: 0) > 0
        );
        if ($hasSubscriptionLine && ! $customer) {
            throw ValidationException::withMessages([
                'email' => 'Sign in or create an account to purchase a subscription.',
            ]);
        }
        $this->carts->assertSellerMinimumOrders($cart);

        $groups = $snapshot->shippingGroups;
        if ($groups === []) {
            $groups = [[
                'method' => $shipping,
                'items' => $cart->items,
                'totals' => $totals,
            ]];
        }

        $sharedNumber = $this->orderNumbers->next();
        $checkoutGroup = CheckoutGroup::createTrusted([
            'reference' => $sharedNumber,
            'customer_id' => $customer?->id,
            'email' => $payload['email'] ?? $customer?->email,
            'payment_method_id' => $payment->id,
            'payment_status' => $initialStatus,
            'currency_code' => $totals['currency'],
            'grand_total' => $grand,
            'capture_amount' => $dueNow,
            'store_id' => $cart->store_id ?: app(StoreContext::class)->requireId(),
        ]);

        $createdOrders = [];
        $discountByOrderItemId = [];
        $groupCount = count($groups);
        $allocatedPaid = 0.0;
        $cart->loadMissing('items.product.translations');

        foreach ($groups as $index => $group) {
            $sliceTotals = $group['totals'] ?? $totals;
            $sliceShipping = array_key_exists('method', $group) ? $group['method'] : $shipping;
            $sliceItems = collect($group['items'] ?? $cart->items);
            $sliceGrand = round((float) ($sliceTotals['grand_total'] ?? 0), 2);
            $sliceFee = round((float) ($sliceTotals['fee_total'] ?? 0), 2);
            $isLast = $index === $groupCount - 1;
            $slicePaid = $verification?->verified
                ? ($hasDueNow ? round($grand > 0 ? ($sliceGrand / $grand) * $dueNow : 0, 2) : $sliceGrand)
                : ($hasDueNow ? round($grand > 0 ? ($sliceGrand / $grand) * $dueNow : 0, 2) : null);
            if ($hasDueNow && $isLast && $slicePaid !== null) {
                $slicePaid = round($dueNow - $allocatedPaid, 2);
            } elseif ($slicePaid !== null) {
                $allocatedPaid = round($allocatedPaid + $slicePaid, 2);
            }
            $sliceDue = $verification?->verified && ! ($hasDueNow && $amountDue !== null && $amountDue > 0.009)
                ? 0
                : ($hasDueNow ? round(max(0, $sliceGrand - (float) $slicePaid), 2) : $amountDue);

            $order = Order::createTrusted([
                'number' => $sharedNumber,
                'checkout_group_id' => $checkoutGroup->id,
                'checkout_reference' => $sharedNumber,
                'customer_id' => $customer?->id,
                'email' => $payload['email'] ?? $customer?->email,
                'status' => $initialOrderStatus,
                'currency_code' => $sliceTotals['currency'] ?? $totals['currency'],
                'currency_rate' => $sliceTotals['currency_rate'] ?? $totals['currency_rate'],
                'subtotal' => $sliceTotals['subtotal'] ?? 0,
                'tax_total' => $sliceTotals['tax'] ?? 0,
                'tax_details' => $sliceTotals['tax_details'] ?? [],
                'shipping_total' => $sliceTotals['shipping'] ?? 0,
                'discount_total' => $sliceTotals['discount'] ?? 0,
                'fee_total' => $sliceFee,
                'grand_total' => $sliceGrand,
                'payment_method_id' => $payment->id,
                'shipping_method_id' => $sliceShipping?->id,
                'payment_status' => $initialStatus,
                'amount_paid' => $slicePaid,
                'amount_due' => $sliceDue,
                'payment_details' => $this->mergeVerificationPaymentDetails(
                    array_filter(array_merge(
                        $this->orderPaymentDetails($sliceTotals, $hasDueNow, $slicePaid) ?: [],
                        [
                            'checkout_reference' => $sharedNumber,
                            'checkout_capture_amount' => $dueNow,
                        ],
                    )),
                    $verification,
                ),
                'billing_address' => $payload['billing_address'],
                'shipping_address' => $payload['shipping_address'] ?? $payload['billing_address'],
                'customer_note' => OrderSettings::customerNotesEnabled()
                    ? ($payload['customer_note'] ?? null)
                    : null,
                'store_id' => $cart->store_id ?: app(StoreContext::class)->requireId(),
            ]);

            $linesByCartItem = collect($sliceTotals['lines'] ?? [])->keyBy('cart_item_id');
            foreach ($sliceItems as $item) {
            $product = $item->product;
            $name = $product->translation()?->name ?? $product->sku;
            $line = $linesByCartItem->get($item->id, []);
            $unitPrice = (float) ($line['unit_price'] ?? $item->unit_price);
            $lineTotal = (float) ($line['line_total'] ?? round($unitPrice * (float) $item->qty, 2));

            $variant = null;
            if ($item->combination_id) {
                $variant = \App\Domain\Catalog\Models\ProductVariant::query()
                    ->where('product_id', $product->id)
                    ->whereKey((int) $item->combination_id)
                    ->first();
                if ($variant) {
                    $label = $variant->attributeLabel();
                    if ($label !== '') {
                        $name = $name.' — '.$label;
                    }
                }
            }

            if ($item->parent_product_id) {
                $parent = \App\Domain\Catalog\Models\Product::query()->with('translations')->find((int) $item->parent_product_id);
                $parentName = $parent?->translation()?->name;
                if ($parentName) {
                    $name = $name.' ('.$parentName.')';
                }
            }

            $warehouseId = null;
            // Rentals / active pre-orders skip permanent stock deduct at place.
            $isRental = $product->isRental();
            $preorders = app(\App\Domain\Catalog\Services\PreorderService::class);
            $isFinalPayment = $preorders->isFinalPaymentData(is_array($item->preorder_data) ? $item->preorder_data : null);
            $isPreorder = is_array($item->preorder_data) && ! empty($item->preorder_data['enabled']) && ! $isFinalPayment;
            if (! $isRental && ! $isPreorder && ! $isFinalPayment && ! $product->isGiftCard() && ($product->manage_stock || $product->isComposite() || ($product->isCombination() && $item->combination_id))) {
                $warehouseId = $this->inventory->pickWarehouse($product, (float) $item->qty)->id;
            }

            $rentalData = is_array($item->rental_data) ? $item->rental_data : null;
            if ($isRental) {
                $product->loadMissing(['rentalSetting', 'rentalAssets']);
                $rentals = app(\App\Domain\Catalog\Services\RentalProductService::class);
                $start = $item->booking_date?->toDateString();
                $end = $item->booking_end_date?->toDateString() ?: $start;
                if ($start && $end) {
                    $rentals->resolvePeriod($product, $start, $end, $item->rental_duration ? (int) $item->rental_duration : null, (float) $item->qty);
                    $assetIds = $rentals->reserveAssets($product, (float) $item->qty, $start, $end);
                    $rentalData = $rentals->buildRentalData($product, $unitPrice, $assetIds);
                }
            }

            $preorderData = is_array($item->preorder_data) ? $item->preorder_data : null;
            if ($isFinalPayment) {
                $sourceId = (int) ($preorderData['source_order_item_id'] ?? 0);
                $source = $sourceId > 0
                    ? \App\Domain\Order\Models\OrderItem::query()->with(['order', 'product.preorderSetting'])->lockForUpdate()->find($sourceId)
                    : null;
                if (! $source || ! $source->order || (int) $source->order->customer_id !== (int) ($customer?->id ?? 0)) {
                    throw ValidationException::withMessages([
                        'payment' => 'This pre-order final payment is no longer valid.',
                    ]);
                }
                $settled = $preorders->assertCanStartFinalPayment($source);
                $remaining = round((float) $settled['remaining'], 2);
                $requested = round($unitPrice * (float) $item->qty, 2);
                $charge = round(min($requested, $remaining), 2);
                if ($charge <= 0.009) {
                    throw ValidationException::withMessages([
                        'payment' => 'This pre-order is already fully paid.',
                    ]);
                }
                $unitPrice = $charge;
                $lineTotal = $charge;
                $preorderData['charge_unit_price'] = $charge;
                $preorderData['full_unit_price'] = $charge;
                $preorderData['deposit'] = $charge;
                $preorderData['remaining_balance'] = $charge;
                $preorderData['source_order_item_id'] = (int) $source->id;
                $preorderData['source_order_id'] = (int) $source->order_id;
                $preorderData['source_order_number'] = $source->order?->number;
                if (! str_contains($name, 'Final payment')) {
                    $name = $name.' — Final payment';
                }
            } elseif ($isPreorder) {
                $preorders->assertCanPurchase($product, (float) $item->qty, 0, $customer, true);
                $preorders->reserveOrders($product, (float) $item->qty);
                $fallbackFull = isset($preorderData['full_unit_price'])
                    ? (float) $preorderData['full_unit_price']
                    : null;
                $preorderData = $preorders->buildPreorderData($product, (float) $item->qty, $fallbackFull);
                // Charge now = deposit or full preorder price (already on the cart line).
                $unitPrice = (float) ($preorderData['charge_unit_price'] ?? $unitPrice);
                $lineTotal = round($unitPrice * (float) $item->qty, 2);
            }

            $orderItem = $order->items()->create([
                'product_id' => $product->id,
                'parent_product_id' => $item->parent_product_id,
                'tax_class_id' => $line['tax_class_id'] ?? $product->tax_class_id,
                'subscription_plan_id' => $item->subscription_plan_id,
                'sku' => $variant?->sku ?: $product->sku,
                'name' => $name,
                'qty' => $item->qty,
                'unit_price' => $unitPrice,
                'tax_rate' => $line['tax_rate'] ?? 0,
                'tax_amount' => $line['tax_amount'] ?? 0,
                'total' => $lineTotal,
                'customization' => $item->customization,
                'bundle_selection' => $item->bundle_selection,
                'gift_card_data' => $item->gift_card_data,
                'combination_id' => $item->combination_id,
                'booking_date' => $item->booking_date,
                'booking_end_date' => $item->booking_end_date,
                'rental_duration' => $item->rental_duration,
                'rental_data' => $rentalData,
                'preorder_data' => $preorderData,
                'warehouse_id' => $warehouseId,
            ]);

            $discountByOrderItemId[(int) $orderItem->id] = (float) ($line['discount_share'] ?? 0);

            $bundleSelection = is_array($item->bundle_selection) ? $item->bundle_selection : null;

            if (! $isRental && ! $isPreorder && ! $isFinalPayment && ! $product->isGiftCard() && ($product->manage_stock || $product->isComposite() || ($product->isCombination() && $item->combination_id))) {
                $ref = $order->number ?? ('ORD-'.$order->id);
                $deductNow = OrderSettings::shouldDeductForStatus($initialOrderStatus);
                if ($deductNow) {
                    $this->inventory->deduct(
                        $product,
                        (float) $item->qty,
                        \App\Domain\Catalog\Models\InventoryMovement::ACTION_SALE,
                        $ref,
                        $warehouseId ? \App\Domain\Catalog\Models\Warehouse::query()->find($warehouseId) : null,
                        $item->combination_id ? (int) $item->combination_id : null,
                        $bundleSelection,
                    );
                } elseif (OrderSettings::shouldReserveOnPlace()) {
                    $this->releaseExpiredReservations();
                    $this->inventory->reserve(
                        $product,
                        (float) $item->qty,
                        $ref,
                        null,
                        $item->combination_id ? (int) $item->combination_id : null,
                        $bundleSelection,
                    );
                }
            }
        }

            $order->unsetRelation('items');
            $order->load('items');
            $this->linkFinalPaymentSources($order, $customer);

            $order->statusHistories()->create([
                'status' => $initialOrderStatus,
                'comment' => $verification?->verified ? 'Order placed after payment' : 'Order placed',
            ]);
            app(\App\Domain\Subscription\Services\SubscriptionBilling::class)->startFromOrder($order->fresh('items'));
            $createdOrders[] = $order;
        }

        $order = $createdOrders[0];

        if (! empty($totals['discount_rule_id'])) {
            // Atomic claim: only one concurrent checkout can take the last
            // slot. This runs inside the enclosing order transaction, so a
            // failed claim rolls the whole order back instead of overselling
            // the coupon.
            $claimed = DiscountRule::query()
                ->where('id', $totals['discount_rule_id'])
                ->where(function ($query) {
                    $query->whereNull('max_uses')->orWhereColumn('used_count', '<', 'max_uses');
                })
                ->increment('used_count');
            if ($claimed === 0) {
                throw ValidationException::withMessages(['coupon' => 'This coupon has just been fully redeemed.']);
            }
            $rule = DiscountRule::query()->find($totals['discount_rule_id']);
            if ($rule && $rule->max_uses_per_customer !== null) {
                if ($customer) {
                    Customer::query()->whereKey($customer->id)->lockForUpdate()->first();
                }
                $redeemEmail = strtolower(trim((string) ($customer?->email ?? $order->email ?? '')));
                $used = DiscountRedemption::usedByRule((int) $rule->id, $customer?->id, $redeemEmail ?: null);
                if ($used >= max(1, (int) $rule->max_uses_per_customer)) {
                    throw ValidationException::withMessages(['coupon' => 'You have already used this coupon the maximum number of times.']);
                }
            }
            DiscountRedemption::firstOrCreate(
                ['discount_rule_id' => $totals['discount_rule_id'], 'order_id' => $order->id],
                [
                    'customer_id' => $customer?->id,
                    'email' => strtolower(trim((string) ($customer?->email ?? $order->email ?? ''))) ?: null,
                    'store_id' => $order->store_id ?? null,
                ]
            );
        }

        $gateway = $this->gateways->get((string) ($payment->driver ?: 'offline'));
        $deferredCapture = $deferGatewayCapture && ! $verification?->verified && ! $gateway->supportsTransactionalCapture();
        $capturePayload = array_merge($payload, [
            'capture_amount' => $dueNow,
            'grand_total' => $grand,
            'amount_due' => $amountDue,
        ]);

        // Re-validate gift card credit immediately before the gateway call so a
        // card drained in another session cannot be discovered only after the
        // customer has been charged. The row lock taken here (inside the enclosing
        // transaction) is held until redemption, so the balance cannot change
        // underneath us between validation and debit.
        $giftCardCredit = (float) ($totals['gift_card_credit'] ?? 0);
        $giftCardCode = (string) ($totals['gift_card_code'] ?? '');
        $preCreditGrand = round($grand + $giftCardCredit, 2);
        if ($giftCardCredit > 0 && $giftCardCode !== '') {
            $giftCards = app(\App\Domain\Catalog\Services\GiftCardService::class);
            $revalidated = $giftCards->revalidateForTotals(
                $giftCardCode,
                $giftCardCredit,
                $preCreditGrand,
                $giftCards->restrictionLines(
                    collect($createdOrders)->flatMap(fn (Order $slice) => $slice->items()->with('product.categories')->get())
                ),
            );
            $giftCardCode = $revalidated['code'];
            $giftCardCredit = $revalidated['credit'];

            // A shrinking credit changes what the customer still owes, so the
            // gateway must capture the corrected amount rather than the stale
            // quote. Only safe when the gateway has not been charged yet.
            $corrected = round(max(0, $preCreditGrand - $giftCardCredit), 2);
            if (! $verification?->verified && ! $deferredCapture && $dueNow !== null && abs($corrected - (float) $dueNow) > 0.009) {
                $dueNow = $corrected;
                $amountDue = $corrected;
                $totals['due_now'] = $corrected;
                $totals['balance_due'] = $corrected;
                $capturePayload['capture_amount'] = $dueNow;
                $capturePayload['amount_due'] = $amountDue;
            }

            $totals['gift_card_credit'] = $giftCardCredit;
            $grand = $corrected;
        }

        $capture = null;

        // External capture amount after gift-card credit. Zero means the gift card
        // covers the order — commit immediately (no deferred hold needed).
        $externalCaptureAmount = (float) ($dueNow ?? $grand);
        $useGiftCardHold = $deferredCapture && $externalCaptureAmount > 0.009;

        if ($verification?->verified) {
            $capture = PaymentCaptureResult::paid($verification->meta);
            foreach ($createdOrders as $slice) {
                if ($slice->payment_status === 'paid') {
                    app(\App\Domain\Catalog\Services\PreorderService::class)->applyCapturedBalanceFromPaymentOrder($slice->fresh('items'));
                    app(\App\Domain\Subscription\Services\SubscriptionBilling::class)->startFromOrder($slice->fresh());
                    if ($slice->customer_id) {
                        $buyer = Customer::query()->find($slice->customer_id);
                        if ($buyer) {
                            app(\App\Domain\Customer\Services\LoyaltyService::class)->earn($buyer, $slice->fresh());
                        }
                    }
                }
            }
        } elseif (! $deferredCapture) {
            $capture = $gateway->capture($order, $capturePayload);
            if ($capture->status === 'failed') {
                throw ValidationException::withMessages([
                    'payment' => $capture->message ?: 'Payment failed.',
                ]);
            }
            foreach ($createdOrders as $slice) {
                $this->applyCaptureToOrder(
                    $slice,
                    $capture,
                    $hasDueNow,
                    $amountDue,
                    (float) $slice->grand_total,
                );
            }
        }

        if ($giftCardCredit > 0 && $giftCardCode !== '') {
            $giftCards = app(\App\Domain\Catalog\Services\GiftCardService::class);
            if ($useGiftCardHold) {
                $giftCards->hold($giftCardCode, $giftCardCredit, $order);
            } else {
                $giftCards->redeem($giftCardCode, $giftCardCredit, $order);
            }
        }

        if (! empty($totals['store_credit']) && $customer) {
            app(\App\Domain\Customer\Services\StoreCreditService::class)->debit(
                $customer,
                (float) $totals['store_credit'],
                'order:'.$order->number,
                (int) $order->id,
            );
        }

        if (! empty($totals['loyalty']) && $customer) {
            $points = app(\App\Domain\Customer\Services\LoyaltyService::class)->pointsForAmount((float) $totals['loyalty']);
            app(\App\Domain\Customer\Services\LoyaltyService::class)->redeem(
                $customer,
                $points,
                'order:'.$order->number,
                (int) $order->id,
            );
        }

        $meta = is_array($cart->meta) ? $cart->meta : [];
        $quoteId = (int) ($meta['quote_id'] ?? 0);
        if ($quoteId > 0) {
            $quote = \App\Domain\Customer\Models\B2bQuote::query()->find($quoteId);
            if ($quote) {
                app(\App\Domain\Customer\Services\QuoteService::class)->markConverted($quote, (int) $order->id);
            }
        }

        foreach ($createdOrders as $slice) {
            app(\App\Domain\Order\Services\OrderDownloadService::class)->issueFor($slice);
        }

        $cart->items()->delete();
        unset($meta['gift_card_code'], $meta['apply_store_credit'], $meta['apply_loyalty'], $meta['gift_wrap'], $meta['gift_message'], $meta['quote_id']);
        $cart->update([
            'coupon_id' => null,
            'discount_rule_id' => null,
            'meta' => $meta === [] ? null : $meta,
        ]);

        $sellerOrders = [];
        try {
            foreach ($createdOrders as $sliceOrder) {
                app(SellerService::class)->captureOrder($sliceOrder, $discountByOrderItemId);
                if ($sliceOrder->fresh()->payment_status === 'paid') {
                    app(SellerService::class)->markOrderPaid($sliceOrder->fresh());
                }
                $sellerOrders = array_merge($sellerOrders, $this->presentSellerOrders($sliceOrder->fresh(['sellerOrders.seller', 'sellerOrders.items'])));
            }
        } catch (\Throwable $e) {
            Log::warning('marketplace.checkout.seller_capture_failed', [
                'order_id' => $order->id,
                'order_number' => $order->number,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }

        foreach ($createdOrders as $sliceOrder) {
            $sliceOrder->refresh()->loadMissing('items.product');
            $placeAfter = [
                'id' => $sliceOrder->id,
                'number' => $sliceOrder->number,
                'customer_id' => $sliceOrder->customer_id,
                'email' => $sliceOrder->email,
                'payment_status' => $sliceOrder->payment_status,
                'grand_total' => $sliceOrder->grand_total,
                'fee_total' => $sliceOrder->fee_total,
                'amount_paid' => $sliceOrder->amount_paid,
                'amount_due' => $sliceOrder->amount_due,
                'currency_code' => $sliceOrder->currency_code,
                'checkout_reference' => $sliceOrder->checkout_reference,
                'items' => $sliceOrder->items->map(static fn ($item) => [
                    'product_id' => (int) $item->product_id,
                    'sku' => (string) $item->sku,
                    'name' => (string) $item->name,
                    'qty' => (float) $item->qty,
                ])->values()->all(),
            ];
            wento_hook('checkout.order.placed', ['order' => $sliceOrder]);
            wento_action('order.create', 'after', ['id' => $sliceOrder->id, 'number' => $sliceOrder->number]);
            wento_action('checkout.place', 'after', $placeAfter);
            app(ShopMailer::class)->orderCreated($sliceOrder);
        }

        $order = $order->fresh(['items.product', 'paymentMethod', 'shippingMethod', 'sellerOrders.seller', 'sellerOrders.items', 'checkoutGroup']);
        $checkoutGroup->updateTrusted([
            'payment_status' => $order->payment_status,
            'payment_details' => is_array($order->payment_details) ? $order->payment_details : null,
        ]);

        return [
            'order' => $order,
            'orders' => $createdOrders,
            'checkout_group' => $checkoutGroup->fresh(),
            'payment' => $capture,
            'seller_orders' => $sellerOrders,
            'deferred_capture' => $deferredCapture,
            'capture_payload' => $capturePayload,
            'payment_driver' => (string) ($payment->driver ?: 'offline'),
            'has_due_now' => $hasDueNow,
            'amount_due' => $amountDue,
            'grand' => $grand,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $details
     * @return array<string, mixed>|null
     */
    protected function mergeVerificationPaymentDetails(?array $details, ?PaymentVerificationResult $verification): ?array
    {
        if (! $verification?->verified || $verification->meta === []) {
            return $details;
        }

        $details = is_array($details) ? $details : [];
        $gatewayMeta = is_array($verification->meta['gateway'] ?? null)
            ? $verification->meta['gateway']
            : $verification->meta;
        $details['gateway'] = array_merge((array) ($details['gateway'] ?? []), $gatewayMeta);

        return $details;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function presentSellerOrdersPublic(Order $order): array
    {
        return $this->presentSellerOrders($order);
    }


    /**
     * @param  array<string, mixed>  $prepared
     * @return array{order: Order, payment: PaymentCaptureResult, seller_orders: list<array<string, mixed>>}
     */
    protected function completeDeferredCapture(array $prepared): array
    {
        $order = Order::query()->with(['sellerOrders.seller', 'items.product'])->findOrFail($prepared['order']->id);
        $siblings = $this->checkoutGroupOrders($order);
        $gateway = $this->gateways->get($prepared['payment_driver']);
        $capture = $gateway->capture($order, $prepared['capture_payload']);

        if ($capture->status === 'failed') {
            DB::transaction(function () use ($siblings) {
                foreach ($siblings as $slice) {
                    $this->markSlicePaymentFailed($slice);
                }
            });

            throw ValidationException::withMessages([
                'payment' => $capture->message ?: 'Payment failed.',
            ]);
        }

        DB::transaction(function () use ($siblings, $capture, $prepared) {
        if ($capture->status === 'paid') {
            app(\App\Domain\Catalog\Services\GiftCardService::class)->commitHold($siblings->first());
        }
        foreach ($siblings as $slice) {
            $locked = Order::query()->whereKey($slice->id)->lockForUpdate()->firstOrFail();
            $this->applyCaptureToOrder(
                $locked,
                $capture,
                (bool) $prepared['has_due_now'],
                $prepared['amount_due'],
                (float) $locked->grand_total,
            );
            if ($locked->fresh()->payment_status === 'paid') {
                app(SellerService::class)->markOrderPaid($locked->fresh());
            }
        }
        });

        $order = $order->fresh(['items', 'sellerOrders.seller', 'sellerOrders.items']);
        $sellerOrders = [];
        foreach ($this->checkoutGroupOrders($order) as $slice) {
            $sellerOrders = array_merge($sellerOrders, $this->presentSellerOrders($slice->fresh(['sellerOrders.seller', 'sellerOrders.items'])));
        }

        return [
            'order' => $order,
            'orders' => $this->checkoutGroupOrders($order),
            'checkout_group' => $order->checkoutGroup,
            'payment' => $capture,
            'seller_orders' => $sellerOrders,
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, Order>
     */
    protected function checkoutGroupOrders(Order $order)
    {
        if (! $order->checkout_group_id) {
            return collect([$order]);
        }

        return Order::query()
            ->where('checkout_group_id', $order->checkout_group_id)
            ->orderBy('id')
            ->get();
    }

    protected function markSlicePaymentFailed(Order $order): void
    {
        $order->loadMissing(['sellerOrders.seller', 'items.product']);
        app(\App\Domain\Catalog\Services\GiftCardService::class)->releaseHold(
            $order,
            \App\Domain\Catalog\Services\GiftCardService::RELEASE_REASON_PAYMENT_FAILED,
        );

        $sellers = app(SellerService::class);
        foreach ($order->sellerOrders as $sellerOrder) {
            if ($sellerOrder->seller) {
                $sellers->cancelSellerOrder($sellerOrder->seller, $sellerOrder);
            }
        }
        $failStatus = 'cancelled';
        $order->updateTrusted([
            'status' => $failStatus,
            'payment_status' => 'failed',
        ]);
        $this->releaseReservedStock($order);
        if (
            $failStatus === 'cancelled'
            && OrderSettings::shouldDeductOnPlace()
            && OrderSettings::restockOnCancellation()
            && $order->sellerOrders->isEmpty()
        ) {
            $order->loadMissing(['items.product']);
            foreach ($order->items as $item) {
                if (! $item->product) {
                    continue;
                }
                $this->inventory->restock(
                    $item->product,
                    (float) $item->qty,
                    \App\Domain\Catalog\Models\InventoryMovement::ACTION_RESTOCK,
                    'Order #'.$order->number,
                    null,
                    $item->combination_id ? (int) $item->combination_id : null,
                );
            }
        }
        $order->statusHistories()->create([
            'status' => $failStatus,
            'comment' => 'Payment failed',
        ]);
        if ($order->checkoutGroup) {
            $order->checkoutGroup->updateTrusted(['payment_status' => 'failed']);
        }
    }

    protected function applyCaptureToOrder(
        Order $order,
        PaymentCaptureResult $capture,
        bool $hasDueNow,
        mixed $amountDue,
        float $grand,
    ): void {
        $details = is_array($order->payment_details) ? $order->payment_details : [];
        $persisted = $order->newQuery()->whereKey($order->getKey())->value('payment_details');
        if (is_string($persisted)) {
            $persisted = json_decode($persisted, true) ?: [];
        }
        if (is_array($persisted) && $persisted !== []) {
            $details = array_merge($persisted, $details);
        }
        if ($capture->meta !== []) {
            $details['gateway'] = array_merge((array) ($details['gateway'] ?? []), $capture->meta);
        }
        if ($details !== []) {
            $order->payment_details = $details;
            $order->save();
        }

        if ($capture->status === 'paid') {
            if ($hasDueNow && $amountDue !== null && $amountDue > 0.009) {
                $order->updateTrusted(['payment_status' => 'partial']);
            } else {
                $order->updateTrusted([
                    'payment_status' => 'paid',
                    'amount_paid' => $grand,
                    'amount_due' => 0,
                ]);
                $this->issueGiftCardsForPaidOrder($order);
                $this->deductStockForPayment($order);
                app(\App\Domain\Catalog\Services\PreorderService::class)->applyCapturedBalanceFromPaymentOrder($order->fresh('items'));
                app(\App\Domain\Subscription\Services\SubscriptionBilling::class)->startFromOrder($order->fresh());
                if ($order->customer_id) {
                    $buyer = Customer::query()->find($order->customer_id);
                    if ($buyer) {
                        app(\App\Domain\Customer\Services\LoyaltyService::class)->earn($buyer, $order->fresh());
                    }
                }
            }
        } elseif ($hasDueNow && $amountDue !== null && $amountDue > 0.009) {
            $order->updateTrusted(['payment_status' => 'partial']);
        }
    }

    /**
     * Explicit gift card issuance on the payment-success path.
     *
     * The OrderObserver also issues on the paid transition; GiftCardService
     * guards on cards already issued per order item, so running both is safe and
     * guarantees issuance even if the model event is missed (retries, webhooks,
     * direct gateway callbacks).
     */
    protected function issueGiftCardsForPaidOrder(Order $order): void
    {
        try {
            app(\App\Domain\Catalog\Services\GiftCardService::class)->issueForPaidOrder($order);
        } catch (\Throwable $e) {
            Log::error('gift_card.issuance_failed', [
                'order_id' => $order->id,
                'order_number' => $order->number,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function markSandboxPaid(Order $order, string $driver): Order
    {
        if (! \App\Domain\Payment\PaymentSettings::sandboxPay()) {
            abort(403, 'Sandbox pay is disabled.');
        }
        $order->loadMissing('paymentMethod');
        if (strtolower((string) ($order->paymentMethod?->driver ?: '')) !== strtolower($driver)) {
            abort(404);
        }
        if ($order->payment_status === 'paid' && (float) ($order->amount_due ?? 0) <= 0.009) {
            return $order;
        }

        $this->applyCaptureToOrder(
            $order,
            PaymentCaptureResult::paid(['sandbox' => true, 'driver' => $driver]),
            false,
            0,
            (float) $order->grand_total,
        );
        foreach ($this->checkoutGroupOrders($order) as $slice) {
            if ((int) $slice->id === (int) $order->id) {
                continue;
            }
            if ($slice->payment_status === 'paid' && (float) ($slice->amount_due ?? 0) <= 0.009) {
                continue;
            }
            $this->applyCaptureToOrder(
                $slice,
                PaymentCaptureResult::paid(['sandbox' => true, 'driver' => $driver]),
                false,
                0,
                (float) $slice->grand_total,
            );
        }

        return $order->fresh();
    }

    public function markLocalPaid(Order $order, array $meta = []): Order
    {
        if ($order->payment_status === 'paid' && (float) ($order->amount_due ?? 0) <= 0.009) {
            return $order;
        }
        $this->applyCaptureToOrder(
            $order,
            PaymentCaptureResult::paid($meta),
            false,
            0,
            (float) $order->grand_total,
        );

        return $order->fresh();
    }

    protected function deductStockForPayment(Order $order): void
    {
        if (! OrderSettings::shouldDeductOnPayment()) {
            return;
        }

        $order->loadMissing(['items.product']);
        $ref = $order->number ?? ('ORD-'.$order->id);
        foreach ($order->items as $item) {
            $product = $item->product;
            if (! $product || $product->isGiftCard()) {
                continue;
            }
            if (is_array($item->preorder_data) && ! empty($item->preorder_data['enabled'])) {
                continue;
            }
            if (app(\App\Domain\Catalog\Services\PreorderService::class)->isFinalPaymentData(is_array($item->preorder_data) ? $item->preorder_data : null)) {
                continue;
            }
            if ($product->isRental()) {
                continue;
            }
            if (! $product->manage_stock && ! $product->isComposite() && ! ($product->isCombination() && $item->combination_id)) {
                continue;
            }
            if (OrderSettings::shouldReserveOnPlace() || OrderSettings::reserveStockUnpaid()) {
                $this->inventory->release(
                    $product,
                    (float) $item->qty,
                    $ref,
                    $item->warehouse_id ? \App\Domain\Catalog\Models\Warehouse::query()->find($item->warehouse_id) : null,
                    $item->combination_id ? (int) $item->combination_id : null,
                );
            }
            $this->inventory->deduct(
                $product,
                (float) $item->qty,
                \App\Domain\Catalog\Models\InventoryMovement::ACTION_SALE,
                $ref,
                $item->warehouse_id ? \App\Domain\Catalog\Models\Warehouse::query()->find($item->warehouse_id) : null,
                $item->combination_id ? (int) $item->combination_id : null,
            );
        }
    }

    public function releaseExpiredReservations(): int
    {
        static $ran = false;
        if ($ran) {
            return 0;
        }
        $ran = true;

        if (! OrderSettings::shouldReserveOnPlace() && ! OrderSettings::reserveStockUnpaid()) {
            return 0;
        }

        $cutoff = now()->subMinutes(OrderSettings::stockReservationMinutes());
        $released = 0;

        Order::query()
            ->whereIn('payment_status', ['pending', 'partial'])
            ->whereNotIn('status', ['cancelled', 'refunded', 'completed', 'shipped'])
            ->where('created_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(50, function ($orders) use (&$released) {
                foreach ($orders as $order) {
                    $this->releaseReservedStock($order);
                    $released++;
                }
            });

        return $released;
    }

    protected function releaseReservedStock(Order $order): void
    {
        $order->loadMissing(['items.product']);
        $ref = $order->number ?? ('ORD-'.$order->id);
        foreach ($order->items as $item) {
            $product = $item->product;
            if (! $product) {
                continue;
            }
            if (! $product->manage_stock && ! ($product->isCombination() && $item->combination_id)) {
                continue;
            }
            $this->inventory->release(
                $product,
                (float) $item->qty,
                $ref,
                null,
                $item->combination_id ? (int) $item->combination_id : null,
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function presentSellerOrders(Order $order): array
    {
        $order->loadMissing(['sellerOrders.seller', 'sellerOrders.items']);

        return $order->sellerOrders->map(static function ($sellerOrder) {
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
        })->values()->all();
    }

    protected function cartRequiresShipping(Cart $cart): bool
    {
        $preorders = app(\App\Domain\Catalog\Services\PreorderService::class);
        foreach ($cart->items as $item) {
            if ($preorders->isFinalPaymentData(is_array($item->preorder_data) ? $item->preorder_data : null)) {
                continue;
            }
            if ($item->product?->requiresShipping()) {
                return true;
            }
        }

        return false;
    }

    protected function linkFinalPaymentSources(Order $order, ?Customer $customer): void
    {
        $order->loadMissing('items');
        $preorders = app(\App\Domain\Catalog\Services\PreorderService::class);
        $sources = [];
        foreach ($order->items as $line) {
            $data = is_array($line->preorder_data) ? $line->preorder_data : [];
            if (! $preorders->isFinalPaymentData($data)) {
                continue;
            }
            $sourceId = (int) ($data['source_order_item_id'] ?? 0);
            $source = $sourceId > 0 ? \App\Domain\Order\Models\OrderItem::query()->find($sourceId) : null;
            if ($source) {
                $preorders->linkPendingBalancePayment($order, $source);
                $sources[] = [
                    'order_id' => (int) ($data['source_order_id'] ?? $source->order_id),
                    'order_number' => $data['source_order_number'] ?? $source->order?->number,
                    'order_item_id' => $sourceId,
                ];
            }
        }
        if ($sources === []) {
            return;
        }
        $details = is_array($order->payment_details) ? $order->payment_details : [];
        $details['preorder_final_payment'] = true;
        $details['source_orders'] = $sources;
        $order->payment_details = $details;
        $order->save();
    }

    /**
     * @param  array<string, mixed>  $totals
     * @return array<string, mixed>|null
     */
    protected function orderPaymentDetails(array $totals, bool $hasDueNow, mixed $dueNow): ?array
    {
        $details = [];
        if (! empty($totals['adjustments'])) {
            $details['adjustments'] = $totals['adjustments'];
        }
        if ($hasDueNow) {
            $details['due_now'] = $dueNow;
        }
        if (! empty($totals['gift_card_code'])) {
            $details['gift_card_code'] = $totals['gift_card_code'];
            $details['gift_card_credit'] = $totals['gift_card_credit'] ?? 0;
        }
        if (! empty($totals['gift_wrap_fee'])) {
            $details['gift_wrap'] = true;
            $details['gift_wrap_fee'] = $totals['gift_wrap_fee'];
        }
        if (! empty($totals['store_credit'])) {
            $details['store_credit'] = $totals['store_credit'];
        }
        if (! empty($totals['gift_wrap_fee'])) {
            $details['gift_wrap'] = true;
            $details['gift_wrap_fee'] = $totals['gift_wrap_fee'];
        }
        if (! empty($totals['store_credit'])) {
            $details['store_credit'] = $totals['store_credit'];
        }

        return $details === [] ? null : $details;
    }

    protected function loginCheckoutCustomer(mixed $order): void
    {
        if (! $order || empty($order->customer_id) || Auth::guard('customer')->check()) {
            return;
        }
        $created = Customer::query()->find($order->customer_id);
        if (! $created) {
            return;
        }
        Auth::guard('customer')->login($created);
        if (request()->hasSession()) {
            request()->session()->regenerate();
        }
    }

    /**
     * Create the shopper, save the checkout address, then the caller places the order.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function openCheckoutAccount(Cart $cart, array $payload): Customer
    {
        if (! CustomerSettings::guestCheckout() && ! CustomerSettings::registrationEnabled()) {
            throw ValidationException::withMessages([
                'email' => 'An account is required to place an order. Registration is closed, so sign in if you already have an account.',
            ]);
        }

        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        if ($email === '') {
            throw ValidationException::withMessages([
                'email' => 'Enter your email address.',
            ]);
        }
        if (Customer::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'email' => 'An account with this email already exists. Sign in to place your order.',
            ]);
        }

        $rawPassword = (string) ($payload['password'] ?? '');
        if ($rawPassword === '') {
            throw ValidationException::withMessages([
                'password' => 'Enter a password to create your account.',
            ]);
        }

        $billing = is_array($payload['billing_address'] ?? null) ? $payload['billing_address'] : [];
        $shipping = is_array($payload['shipping_address'] ?? null) ? $payload['shipping_address'] : [];
        $named = $this->addressHasName($shipping) ? $shipping : $billing;
        $firstName = trim((string) ($payload['first_name'] ?? $named['first_name'] ?? ''));
        $lastName = trim((string) ($payload['last_name'] ?? $named['last_name'] ?? ''));
        if ($firstName === '' || $lastName === '') {
            throw ValidationException::withMessages(array_filter([
                'first_name' => $firstName === '' ? 'Enter your first name.' : null,
                'last_name' => $lastName === '' ? 'Enter your last name.' : null,
            ]));
        }
        if (CustomerSettings::dateOfBirthMode() === 'required' && trim((string) ($payload['date_of_birth'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'date_of_birth' => 'Enter your date of birth.',
            ]);
        }
        $name = trim($firstName.' '.$lastName);
        $group = CustomerSettings::resolveDefaultGroup();
        $home = (int) ($cart->store_id ?: app(StoreContext::class)->customerHomeId() ?: app(StoreContext::class)->requireId());
        $gender = strtolower(trim((string) ($payload['gender'] ?? '')));

        $customer = Customer::createTrusted([
            'email' => $email,
            'password' => $rawPassword,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'name' => $name !== '' ? $name : 'Customer',
            'phone' => $named['phone'] ?? null,
            'date_of_birth' => CustomerSettings::dateOfBirthMode() === 'hidden' ? null : (trim((string) ($payload['date_of_birth'] ?? '')) ?: null),
            'gender' => in_array($gender, ['male', 'female', 'other'], true) ? $gender : null,
            'store_id' => $home,
            'is_enabled' => true,
            'email_verified_at' => now(),
            'customer_group' => $group?->code,
            'customer_group_id' => $group?->id,
        ]);

        $same = $this->checkoutAddressesMatch($shipping, $billing);
        if ($this->addressHasLine($shipping)) {
            $this->storeCheckoutAddress($customer, $shipping, true, $same || ! $this->addressHasLine($billing));
        }
        if (! $same && $this->addressHasLine($billing)) {
            $this->storeCheckoutAddress($customer, $billing, ! $this->addressHasLine($shipping), true);
        } elseif (! $this->addressHasLine($shipping) && $this->addressHasLine($billing)) {
            $this->storeCheckoutAddress($customer, $billing, true, true);
        }

        return $customer;
    }

    /**
     * @param  array<string, mixed>  $address
     */
    protected function storeCheckoutAddress(Customer $customer, array $address, bool $shipping, bool $billing): void
    {
        $customer->addresses()->create([
            'label' => $billing && ! $shipping ? 'Billing' : 'Home',
            'first_name' => trim((string) ($address['first_name'] ?? '')) ?: 'Customer',
            'last_name' => $address['last_name'] ?? null,
            'phone' => $address['phone'] ?? null,
            'company' => $address['company'] ?? null,
            'tax_id' => $address['tax_id'] ?? null,
            'line1' => trim((string) ($address['line1'] ?? '')),
            'line2' => $address['line2'] ?? null,
            'city' => $address['city'] ?? '',
            'state' => $address['state'] ?? null,
            'postal_code' => $address['postal_code'] ?? null,
            'country_code' => $address['country_code'] ?? '',
            'is_default_shipping' => $shipping,
            'is_default_billing' => $billing,
        ]);
    }

    /**
     * @param  array<string, mixed>  $address
     */
    protected function addressHasLine(array $address): bool
    {
        return trim((string) ($address['line1'] ?? '')) !== '';
    }

    /**
     * @param  array<string, mixed>  $address
     */
    protected function addressHasName(array $address): bool
    {
        return trim((string) ($address['first_name'] ?? '')) !== '';
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    protected function checkoutAddressesMatch(array $left, array $right): bool
    {
        if (! $this->addressHasLine($left) || ! $this->addressHasLine($right)) {
            return true;
        }

        $key = static fn (array $address): string => strtolower(implode('|', [
            trim((string) ($address['line1'] ?? '')),
            trim((string) ($address['city'] ?? '')),
            trim((string) ($address['postal_code'] ?? '')),
            trim((string) ($address['country_code'] ?? '')),
        ]));

        return $key($left) === $key($right);
    }
}
