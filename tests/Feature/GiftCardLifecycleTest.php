<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\GiftCard;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductGiftCard;
use App\Domain\Catalog\Services\GiftCardService;
use App\Domain\Checkout\Models\Cart;
use App\Domain\Checkout\Services\CartService;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Mail\ShopHtmlMail;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Deep coverage for gift card issuance, redemption rules, refunds and delivery.
 */
class GiftCardLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function giftCardProduct(array $config = [], array $amounts = []): Product
    {
        $this->actingAs(Admin::query()->first() ?: Admin::factory()->create(), 'admin');

        $payload = $this->withProductCategories([
            'type' => 'gift_card',
            'name' => 'Store Gift Card',
            'slug' => 'store-gift-card-'.uniqid(),
            'sku' => 'GC-'.strtoupper(uniqid()),
            'price' => 100,
            'is_enabled' => true,
            'gift_card' => array_merge([
                'amount_type' => 'fixed',
                'delivery_method' => 'email',
                'validity_type' => 'never',
                'allow_online_redemption' => true,
                'allow_offline_redemption' => false,
                'allow_partial_redemption' => true,
                'usage_type' => 'multiple',
                'code_length' => 12,
            ], $config),
            'gift_card_amounts' => $amounts ?: [
                ['value' => 100, 'selling_price' => 100, 'is_enabled' => true, 'position' => 0],
            ],
        ]);

        $this->post('/admin/products', $payload)->assertProductSaveRedirect();

        return Product::query()->orderByDesc('id')->firstOrFail();
    }

    /**
     * Create an order for a gift card product with the given item gift_card_data.
     */
    protected function orderWithGiftCardItem(Product $product, array $giftCardData, float $qty = 1, float $unitPrice = 100): Order
    {
        $order = Order::createTrusted([
            'number' => 'WENTO-'.strtoupper(uniqid()),
            'email' => 'buyer@example.com',
            'status' => 'pending',
            'currency_code' => 'USD',
            'subtotal' => $unitPrice * $qty,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'grand_total' => $unitPrice * $qty,
            'payment_status' => 'pending',
            'billing_address' => ['first_name' => 'B', 'line1' => '1 Main', 'city' => 'Town', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'B', 'line1' => '1 Main', 'city' => 'Town', 'country_code' => 'US'],
        ]);

        $order->items()->create([
            'product_id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->sku,
            'qty' => $qty,
            'unit_price' => $unitPrice,
            'total' => $unitPrice * $qty,
            'gift_card_data' => $giftCardData,
        ]);

        return $order->fresh();
    }

    protected function activeCard(array $attributes = []): GiftCard
    {
        $product = Product::query()->where('type', 'gift_card')->first()
            ?: $this->giftCardProduct();

        return GiftCard::query()->create(array_merge([
            'product_id' => $product->id,
            'code' => 'CARD'.strtoupper(uniqid()),
            'initial_balance' => 100,
            'current_balance' => 100,
            'selected_amount' => 100,
            'status' => 'active',
            'issued_at' => now(),
        ], $attributes));
    }

    /** --- Issuance ------------------------------------------------------- */

    public function test_payment_issues_one_card_per_quantity_even_when_items_were_never_loaded(): void
    {
        $this->seed();
        $product = $this->giftCardProduct();
        $amount = $product->giftCardAmounts()->firstOrFail();

        $order = $this->orderWithGiftCardItem($product, [
            'fixed_amount_id' => $amount->id,
            'amount' => 100,
            'selling_price' => 100,
            'recipient_email' => 'alex@example.com',
        ], qty: 3, unitPrice: 100);

        // The critical regression: no items relation was touched before payment,
        // and the same in-memory instance is reused for the paid transition.
        Mail::fake();
        $order->updateTrusted(['payment_status' => 'paid', 'amount_paid' => 300]);

        $this->assertSame(3, GiftCard::query()->count(), 'One card must be issued per purchased quantity.');
    }

    public function test_repeated_payment_events_do_not_duplicate_cards(): void
    {
        $this->seed();
        $product = $this->giftCardProduct();
        $amount = $product->giftCardAmounts()->firstOrFail();

        $order = $this->orderWithGiftCardItem($product, [
            'fixed_amount_id' => $amount->id,
            'amount' => 100,
            'recipient_email' => 'alex@example.com',
        ]);

        Mail::fake();
        $order->updateTrusted(['payment_status' => 'paid', 'amount_paid' => 100]);
        $this->assertSame(1, GiftCard::query()->count());

        // Simulate a retry / webhook replay / explicit service call.
        app(GiftCardService::class)->issueForPaidOrder($order->fresh());
        app(GiftCardService::class)->issueForPaidOrder($order->fresh());

        $this->assertSame(1, GiftCard::query()->count(), 'Issuance must stay idempotent.');
    }

    public function test_payment_failure_does_not_issue_cards(): void
    {
        $this->seed();
        $product = $this->giftCardProduct();
        $amount = $product->giftCardAmounts()->firstOrFail();

        $order = $this->orderWithGiftCardItem($product, [
            'fixed_amount_id' => $amount->id,
            'amount' => 100,
        ]);

        $order->updateTrusted(['payment_status' => 'failed']);

        $this->assertSame(0, GiftCard::query()->count());
    }

    public function test_stale_items_relation_does_not_block_issuance(): void
    {
        $this->seed();
        $product = $this->giftCardProduct();

        // Create order, touch items (populating an empty/partial relation), then add the line.
        $order = Order::createTrusted([
            'number' => 'WENTO-STALE',
            'email' => 'buyer@example.com',
            'status' => 'pending',
            'currency_code' => 'USD',
            'subtotal' => 100,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'grand_total' => 100,
            'payment_status' => 'pending',
            'billing_address' => ['first_name' => 'B', 'line1' => '1', 'city' => 'T', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'B', 'line1' => '1', 'city' => 'T', 'country_code' => 'US'],
        ]);
        $order->items; // populates relation while empty

        $order->items()->create([
            'product_id' => $product->id,
            'sku' => $product->sku,
            'name' => 'GC',
            'qty' => 1,
            'unit_price' => 100,
            'total' => 100,
            'gift_card_data' => ['amount' => 100, 'recipient_email' => 'a@example.com'],
        ]);

        Mail::fake();
        $order->updateTrusted(['payment_status' => 'paid']);

        $this->assertSame(1, GiftCard::query()->count(), 'A stale items relation must not suppress issuance.');
    }

    public function test_admin_order_edit_marking_paid_issues_gift_card_after_rebuilding_items(): void
    {
        $this->seed();
        Mail::fake();
        $admin = Admin::query()->first() ?: Admin::factory()->create();
        $this->actingAs($admin, 'admin');

        $product = $this->giftCardProduct(['delivery_method' => 'both']);
        $order = $this->orderWithGiftCardItem($product, [
            'amount' => 88,
            'recipient_email' => 'gift-recipient@example.com',
            'recipient_name' => 'Alex',
        ], qty: 1, unitPrice: 88);
        $customer = Customer::factory()->create(['email' => 'buyer-wallet@example.com']);
        $order->updateTrusted(['customer_id' => $customer->id, 'email' => $customer->email]);

        $this->assertSame(0, GiftCard::query()->where('order_id', $order->id)->count());

        // Reproduce the admin Edit save path: rebuild items + flip payment to paid.
        $this->put('/admin/orders/'.$order->id, [
            'email' => $customer->email,
            'customer_id' => $customer->id,
            'status' => 'shipped',
            'currency_code' => 'USD',
            'payment_status' => 'paid',
            'billing_address' => [
                'first_name' => 'Buyer',
                'last_name' => 'Demo',
                'line1' => '1 Main',
                'city' => 'Town',
                'postal_code' => '10001',
                'country_code' => 'US',
            ],
            'shipping_address' => [
                'first_name' => 'Buyer',
                'last_name' => 'Demo',
                'line1' => '1 Main',
                'city' => 'Town',
                'postal_code' => '10001',
                'country_code' => 'US',
            ],
            'items' => [[
                'product_id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->sku,
                'qty' => 1,
                'unit_price' => 88,
                'tax_rate' => 0,
                'discount' => 0,
                'discount_type' => 'amount',
            ]],
        ])->assertRedirect();

        $card = GiftCard::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($card, 'Admin edit that marks the order paid must issue the purchased gift card.');
        $this->assertSame('active', $card->status);
        $this->assertEquals(88.0, (float) $card->initial_balance);
        $this->assertSame($customer->id, (int) $card->customer_id);
        $this->assertSame('gift-recipient@example.com', $card->recipient_email);

        $wallet = app(GiftCardService::class)->walletFor($customer->fresh());
        $this->assertNotEmpty($wallet);
        $this->assertSame($card->code, $wallet[0]['code']);

        $this->get('/admin/gift-cards')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Catalog/GiftCards/Index')
            ->has('cards.data', 1)
        );
    }

    /** --- Redemption ----------------------------------------------------- */

    public function test_partial_redemption_keeps_remaining_balance_and_ledger(): void
    {
        $this->seed();
        $this->giftCardProduct(['allow_partial_redemption' => true]);
        $card = $this->activeCard(['current_balance' => 100, 'initial_balance' => 100]);

        app(GiftCardService::class)->redeem($card->code, 40);

        $card->refresh();
        $this->assertEquals(60, (float) $card->current_balance);
        $this->assertSame('active', $card->status);

        $tx = $card->transactions()->where('type', 'redemption')->firstOrFail();
        $this->assertEquals(100, (float) $tx->balance_before);
        $this->assertEquals(60, (float) $tx->balance_after);
    }

    public function test_partial_redemption_disabled_rejects_partial_use(): void
    {
        $this->seed();
        $this->giftCardProduct(['allow_partial_redemption' => false]);
        $card = $this->activeCard(['current_balance' => 100, 'initial_balance' => 100]);

        $this->expectException(ValidationException::class);
        app(GiftCardService::class)->redeem($card->code, 40);
    }

    public function test_single_use_card_is_consumed_even_on_partial_redemption(): void
    {
        $this->seed();
        $this->giftCardProduct(['usage_type' => 'single', 'allow_partial_redemption' => true]);
        $card = $this->activeCard(['current_balance' => 100, 'initial_balance' => 100]);

        app(GiftCardService::class)->redeem($card->code, 30);

        $card->refresh();
        $this->assertEquals(70, (float) $card->current_balance);
        $this->assertSame('used', $card->status, 'A single-use card must not be redeemable twice.');

        $this->expectException(ValidationException::class);
        app(GiftCardService::class)->redeem($card->code, 10);
    }

    public function test_multiple_use_card_allows_repeated_redemption(): void
    {
        $this->seed();
        $this->giftCardProduct(['usage_type' => 'multiple']);
        $card = $this->activeCard(['current_balance' => 100, 'initial_balance' => 100]);
        $service = app(GiftCardService::class);

        $service->redeem($card->code, 30);
        $service->redeem($card->code, 30);

        $this->assertEquals(40, (float) $card->fresh()->current_balance);
    }

    public function test_redemption_beyond_balance_is_rejected(): void
    {
        $this->seed();
        $this->giftCardProduct();
        $card = $this->activeCard(['current_balance' => 50, 'initial_balance' => 50]);

        $this->expectException(ValidationException::class);
        app(GiftCardService::class)->redeem($card->code, 80);
    }

    public function test_balance_never_goes_negative_after_failed_redemption(): void
    {
        $this->seed();
        $this->giftCardProduct();
        $card = $this->activeCard(['current_balance' => 50, 'initial_balance' => 50]);

        try {
            app(GiftCardService::class)->redeem($card->code, 80);
        } catch (ValidationException) {
            // expected
        }

        $this->assertEquals(50, (float) $card->fresh()->current_balance);
    }

    public function test_unknown_code_is_rejected(): void
    {
        $this->seed();
        $this->giftCardProduct();

        $this->expectException(ValidationException::class);
        app(GiftCardService::class)->redeem('DOES-NOT-EXIST', 10);
    }

    public function test_disabled_cancelled_and_used_cards_cannot_be_redeemed(): void
    {
        $this->seed();
        $this->giftCardProduct();

        foreach (['disabled', 'cancelled', 'used'] as $status) {
            $card = $this->activeCard(['status' => $status]);

            try {
                app(GiftCardService::class)->redeem($card->code, 10);
                $this->fail("Card with status {$status} should not be redeemable.");
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_online_redemption_disabled_blocks_preview(): void
    {
        $this->seed();
        $this->giftCardProduct(['allow_online_redemption' => false]);
        $card = $this->activeCard();

        $this->expectException(ValidationException::class);
        app(GiftCardService::class)->preview($card->code);
    }

    /** --- Order amount restrictions -------------------------------------- */

    public function test_minimum_order_amount_is_enforced(): void
    {
        $this->seed();
        $this->giftCardProduct(['min_order_amount' => 200]);
        $card = $this->activeCard();

        $order = $this->orderWithGiftCardItem(
            Product::query()->where('type', 'gift_card')->firstOrFail(),
            ['amount' => 100],
            unitPrice: 50,
        );

        $this->expectException(ValidationException::class);
        app(GiftCardService::class)->redeem($card->code, 50, $order);
    }

    public function test_maximum_order_amount_is_enforced(): void
    {
        $this->seed();
        $this->giftCardProduct(['max_order_amount' => 50]);
        $card = $this->activeCard();

        $order = $this->orderWithGiftCardItem(
            Product::query()->where('type', 'gift_card')->firstOrFail(),
            ['amount' => 100],
            unitPrice: 500,
        );

        $this->expectException(ValidationException::class);
        app(GiftCardService::class)->redeem($card->code, 50, $order);
    }

    public function test_null_order_amount_limits_are_unlimited(): void
    {
        $this->seed();
        $this->giftCardProduct(['min_order_amount' => null, 'max_order_amount' => null]);
        $card = $this->activeCard();

        $order = $this->orderWithGiftCardItem(
            Product::query()->where('type', 'gift_card')->firstOrFail(),
            ['amount' => 100],
            unitPrice: 5000,
        );

        app(GiftCardService::class)->redeem($card->code, 40, $order);

        $this->assertEquals(60, (float) $card->fresh()->current_balance);
    }

    /** --- Expiry --------------------------------------------------------- */

    public function test_expired_card_cannot_be_redeemed_and_is_marked_expired(): void
    {
        $this->seed();
        $this->giftCardProduct();
        $card = $this->activeCard(['expires_at' => now()->subDay()]);

        try {
            app(GiftCardService::class)->redeem($card->code, 10);
            $this->fail('Expired card should not be redeemable.');
        } catch (ValidationException) {
            $this->assertSame('expired', $card->fresh()->status);
        }
    }

    public function test_expiry_days_is_calculated_from_issue_date(): void
    {
        $this->seed();
        $product = $this->giftCardProduct(['validity_type' => 'days', 'validity_days' => 30]);
        $amount = $product->giftCardAmounts()->firstOrFail();

        $order = $this->orderWithGiftCardItem($product, [
            'fixed_amount_id' => $amount->id,
            'amount' => 100,
        ]);

        Mail::fake();
        $order->updateTrusted(['payment_status' => 'paid']);

        $card = GiftCard::query()->firstOrFail();
        $this->assertNotNull($card->expires_at);
        $this->assertEqualsWithDelta(30, now()->diffInDays($card->expires_at), 1);
    }

    public function test_fixed_expiry_date_is_used_as_configured(): void
    {
        $this->seed();
        $product = $this->giftCardProduct([
            'validity_type' => 'fixed_date',
            'expiry_date' => now()->addMonths(6)->toDateString(),
        ]);
        $amount = $product->giftCardAmounts()->firstOrFail();

        $order = $this->orderWithGiftCardItem($product, [
            'fixed_amount_id' => $amount->id,
            'amount' => 100,
        ]);

        Mail::fake();
        $order->updateTrusted(['payment_status' => 'paid']);

        $card = GiftCard::query()->firstOrFail();
        $this->assertNotNull($card->expires_at);
        $this->assertSame(
            now()->addMonths(6)->toDateString(),
            $card->expires_at->toDateString(),
        );
    }

    public function test_expire_due_cards_sweeps_only_past_expiry(): void
    {
        $this->seed();
        $this->giftCardProduct();
        $expired = $this->activeCard(['expires_at' => now()->subDay()]);
        $valid = $this->activeCard(['expires_at' => now()->addDay()]);

        $count = app(GiftCardService::class)->expireDueCards();

        $this->assertSame(1, $count);
        $this->assertSame('expired', $expired->fresh()->status);
        $this->assertSame('active', $valid->fresh()->status);
    }

    public function test_preflight_revalidation_returns_capped_credit(): void
    {
        $this->seed();
        $this->giftCardProduct();
        $card = $this->activeCard(['current_balance' => 30]);

        // Quoted 100 but only 30 remains -> credit must be capped, never negative.
        $result = app(GiftCardService::class)->revalidateForTotals($card->code, 100, 200);

        $this->assertEquals(30, $result['credit']);
    }

    public function test_preflight_revalidation_rejects_drained_card_before_capture(): void
    {
        $this->seed();
        $this->giftCardProduct();
        $card = $this->activeCard(['current_balance' => 0, 'status' => 'used']);

        $this->expectException(ValidationException::class);
        app(GiftCardService::class)->revalidateForTotals($card->code, 50, 200);
    }

    /** --- Refunds -------------------------------------------------------- */

    public function test_full_refund_cancels_untouched_card_and_writes_ledger(): void
    {
        $this->seed();
        $product = $this->giftCardProduct();
        $amount = $product->giftCardAmounts()->firstOrFail();

        $order = $this->orderWithGiftCardItem($product, [
            'fixed_amount_id' => $amount->id,
            'amount' => 100,
        ]);

        Mail::fake();
        $order->updateTrusted(['payment_status' => 'paid']);
        $card = GiftCard::query()->firstOrFail();

        $order->updateTrusted(['status' => 'refunded']);

        $card->refresh();
        $this->assertSame('cancelled', $card->status);
        $this->assertTrue($card->transactions()->where('type', 'cancel')->exists());
    }

    public function test_refund_via_payment_status_also_handles_cards(): void
    {
        $this->seed();
        $product = $this->giftCardProduct();
        $amount = $product->giftCardAmounts()->firstOrFail();

        $order = $this->orderWithGiftCardItem($product, [
            'fixed_amount_id' => $amount->id,
            'amount' => 100,
        ]);

        Mail::fake();
        $order->updateTrusted(['payment_status' => 'paid']);
        $order->updateTrusted(['payment_status' => 'refunded']);

        $this->assertSame('cancelled', GiftCard::query()->firstOrFail()->status);
    }

    public function test_refund_does_not_restore_balance_of_redeemed_card(): void
    {
        $this->seed();
        $product = $this->giftCardProduct();
        $amount = $product->giftCardAmounts()->firstOrFail();

        $order = $this->orderWithGiftCardItem($product, [
            'fixed_amount_id' => $amount->id,
            'amount' => 100,
        ]);

        Mail::fake();
        $order->updateTrusted(['payment_status' => 'paid']);
        $card = GiftCard::query()->firstOrFail();

        app(GiftCardService::class)->redeem($card->code, 40, $order);
        $this->assertEquals(60, (float) $card->fresh()->current_balance);

        $order->updateTrusted(['status' => 'refunded']);

        $card->refresh();
        // Balance must NOT be inflated back to 100.
        $this->assertEquals(60, (float) $card->current_balance);
        $this->assertSame('disabled', $card->status);
    }

    public function test_repeated_refund_events_are_idempotent(): void
    {
        $this->seed();
        $product = $this->giftCardProduct();
        $amount = $product->giftCardAmounts()->firstOrFail();

        $order = $this->orderWithGiftCardItem($product, [
            'fixed_amount_id' => $amount->id,
            'amount' => 100,
        ]);

        Mail::fake();
        $order->updateTrusted(['payment_status' => 'paid']);
        $card = GiftCard::query()->firstOrFail();

        $order->updateTrusted(['status' => 'refunded']);
        $order->updateTrusted(['status' => 'refunded']);

        $this->assertSame(1, $card->transactions()->where('type', 'cancel')->count());
    }

    public function test_fully_redeemed_card_is_not_reflagged_on_refund(): void
    {
        $this->seed();
        $product = $this->giftCardProduct();
        $amount = $product->giftCardAmounts()->firstOrFail();

        $order = $this->orderWithGiftCardItem($product, [
            'fixed_amount_id' => $amount->id,
            'amount' => 100,
        ]);

        Mail::fake();
        $order->updateTrusted(['payment_status' => 'paid']);
        $card = GiftCard::query()->firstOrFail();

        app(GiftCardService::class)->redeem($card->code, 100, $order);
        $this->assertSame('used', $card->fresh()->status);

        $order->updateTrusted(['status' => 'refunded']);

        $this->assertSame('used', $card->fresh()->status);
    }

    /** --- Delivery ------------------------------------------------------- */

    public function test_account_delivery_does_not_email_the_card(): void
    {
        $this->seed();
        $product = $this->giftCardProduct(['delivery_method' => 'account']);
        $amount = $product->giftCardAmounts()->firstOrFail();

        $order = $this->orderWithGiftCardItem($product, [
            'fixed_amount_id' => $amount->id,
            'amount' => 100,
            'recipient_email' => 'alex@example.com',
        ]);

        Mail::fake();
        $order->updateTrusted(['payment_status' => 'paid']);

        // Order-created mail is unrelated; the *card* must not be emailed.
        Mail::assertNotSent(ShopHtmlMail::class, fn (ShopHtmlMail $mail) => $mail->hasTo('alex@example.com'));
        $this->assertSame(1, GiftCard::query()->count());
    }

    public function test_both_delivery_sends_email(): void
    {
        $this->seed();
        $product = $this->giftCardProduct(['delivery_method' => 'both']);
        $amount = $product->giftCardAmounts()->firstOrFail();

        $order = $this->orderWithGiftCardItem($product, [
            'fixed_amount_id' => $amount->id,
            'amount' => 100,
            'recipient_email' => 'alex@example.com',
        ]);

        Mail::fake();
        $order->updateTrusted(['payment_status' => 'paid']);

        Mail::assertSent(ShopHtmlMail::class, fn (ShopHtmlMail $mail) => $mail->hasTo('alex@example.com'));
    }

    public function test_email_delivery_without_any_address_logs_and_keeps_card(): void
    {
        $this->seed();
        $product = $this->giftCardProduct(['delivery_method' => 'email']);

        // No recipient email and no order email -> nothing deliverable.
        $order = $this->orderWithGiftCardItem($product, ['amount' => 100]);
        $order->updateTrusted(['email' => '']);

        Mail::fake();
        Log::spy();

        $order->updateTrusted(['payment_status' => 'paid']);

        $this->assertSame(1, GiftCard::query()->count(), 'A missing email must not lose the card.');
        Log::shouldHaveReceived('warning')
            ->with('gift_card.issued_without_email', \Mockery::type('array'))
            ->atLeast()->once();
    }

    public function test_resend_email_targets_recipient(): void
    {
        $this->seed();
        $product = $this->giftCardProduct(['delivery_method' => 'email']);
        $card = $this->activeCard([
            'product_id' => $product->id,
            'recipient_email' => 'alex@example.com',
        ]);

        Mail::fake();
        $sent = app(GiftCardService::class)->resendEmail($card);

        $this->assertTrue($sent);
        Mail::assertSent(ShopHtmlMail::class, fn (ShopHtmlMail $m) => $m->hasTo('alex@example.com'));
    }

    /** --- Code generation ------------------------------------------------ */

    public function test_codes_are_unique_and_respect_configured_length(): void
    {
        $this->seed();
        $this->giftCardProduct(['code_length' => 16]);
        $config = ProductGiftCard::query()->firstOrFail();

        $method = new \ReflectionMethod(GiftCardService::class, 'buildCode');
        $method->setAccessible(true);
        $service = app(GiftCardService::class);

        $codes = [];
        for ($i = 0; $i < 50; $i++) {
            $code = $method->invoke($service, $config);
            $codes[] = $code;
        }

        $this->assertCount(50, array_unique($codes), 'Generated codes must be unique.');
        // 16 chars => 4 dash-separated groups.
        $this->assertSame(16 + 3, strlen($codes[0]));
    }

    public function test_numeric_charset_only_produces_digits(): void
    {
        $this->seed();
        $this->giftCardProduct(['code_charset' => 'numeric', 'code_length' => 6]);
        $config = ProductGiftCard::query()->firstOrFail();

        $method = new \ReflectionMethod(GiftCardService::class, 'buildCode');
        $method->setAccessible(true);
        $code = $method->invoke(app(GiftCardService::class), $config);

        $this->assertMatchesRegularExpression('/^[0-9-]+$/', $code);
    }

    /** --- Wallet --------------------------------------------------------- */

    public function test_wallet_lists_linked_and_recipient_cards_only(): void
    {
        $this->seed();
        $this->giftCardProduct();
        $me = Customer::factory()->create(['email' => 'me@example.com']);
        $other = Customer::factory()->create(['email' => 'other@example.com']);

        $mine = $this->activeCard(['customer_id' => $me->id]);
        $byEmail = $this->activeCard(['recipient_email' => 'me@example.com']);
        $this->activeCard(['customer_id' => $other->id]);
        $this->activeCard(['recipient_email' => 'other@example.com']);

        $wallet = app(GiftCardService::class)->walletFor($me);
        $codes = array_column($wallet, 'code');

        $this->assertContains($mine->code, $codes);
        $this->assertContains($byEmail->code, $codes);
        $this->assertCount(2, $codes, 'A customer must not see another customer’s cards.');
    }

    public function test_wallet_includes_expired_and_disabled_cards_with_flags(): void
    {
        $this->seed();
        $this->giftCardProduct();
        $customer = Customer::factory()->create(['email' => 'me@example.com']);

        $this->activeCard(['customer_id' => $customer->id, 'status' => 'disabled']);
        $this->activeCard(['customer_id' => $customer->id, 'expires_at' => now()->subDay()]);

        $wallet = app(GiftCardService::class)->walletFor($customer);

        $this->assertCount(2, $wallet);
        $this->assertTrue(collect($wallet)->contains(fn ($row) => $row['is_expired'] === true));
        $this->assertTrue(collect($wallet)->contains(fn ($row) => $row['status'] === 'disabled'));
    }

    /** --- Admin ---------------------------------------------------------- */

    public function test_admin_gift_card_create_redirects_to_product_edit(): void
    {
        $this->seed();
        $product = $this->giftCardProduct();
        $admin = Admin::query()->first();

        $this->actingAs($admin, 'admin')
            ->get('/admin/gift-cards/create?product_id='.$product->id)
            ->assertRedirect('/admin/products/'.$product->id.'/edit?tab=gift_card');

        $this->actingAs($admin, 'admin')
            ->get('/admin/gift-cards/create')
            ->assertRedirect('/admin/products/create?type=gift_card');
    }

    public function test_admin_can_view_gift_card_list_and_detail(): void
    {
        $this->seed();
        $this->giftCardProduct();
        $card = $this->activeCard();
        $admin = Admin::query()->first();

        $this->actingAs($admin, 'admin')->get('/admin/gift-cards')->assertOk();

        $this->actingAs($admin, 'admin')
            ->get('/admin/gift-cards/'.$card->id)
            ->assertOk();
    }

    public function test_admin_can_disable_a_card_with_ledger_entry(): void
    {
        $this->seed();
        $this->giftCardProduct();
        $card = $this->activeCard();
        $admin = Admin::query()->first();

        $this->actingAs($admin, 'admin')
            ->post('/admin/gift-cards/'.$card->id.'/status', ['status' => 'disabled', 'note' => 'fraud check'])
            ->assertRedirect();

        $this->assertSame('disabled', $card->fresh()->status);
        $this->assertTrue($card->transactions()->where('type', 'adjustment')->exists());
    }

    public function test_admin_can_manually_issue_a_card(): void
    {
        $this->seed();
        $product = $this->giftCardProduct(['delivery_method' => 'account']);
        $admin = Admin::query()->first();

        Mail::fake();
        $this->actingAs($admin, 'admin')
            ->post('/admin/gift-cards', [
                'product_id' => $product->id,
                'amount' => 250,
                'recipient_email' => 'alex@example.com',
                'reason' => 'goodwill',
            ])
            ->assertRedirect();

        $card = GiftCard::query()->firstOrFail();
        $this->assertEquals(250, (float) $card->initial_balance);
        $this->assertSame('active', $card->status);
        $this->assertTrue($card->transactions()->where('type', 'credit')->exists());
    }

    public function test_admin_manual_issue_rejects_invalid_amount(): void
    {
        $this->seed();
        $product = $this->giftCardProduct();
        $admin = Admin::query()->first();

        $this->actingAs($admin, 'admin')
            ->post('/admin/gift-cards', [
                'product_id' => $product->id,
                'amount' => 0,
            ])
            ->assertSessionHasErrors('amount');
    }

    public function test_admin_gift_card_pages_require_authentication(): void
    {
        $this->seed();
        $this->giftCardProduct();

        // Drop the seeded admin session so the guard is genuinely unauthenticated.
        $this->app['auth']->forgetGuards();

        $this->get('/admin/gift-cards')->assertRedirect('/admin/login');
        $this->assertGuest('admin');
    }

    /** --- Restrictions --------------------------------------------------- */

    public function test_restrictions_are_persisted_and_normalized(): void
    {
        $this->seed();
        $realIds = \App\Domain\Catalog\Models\Category::query()->limit(2)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (count($realIds) < 2) {
            $realIds[] = (int) \App\Domain\Catalog\Models\Category::query()->create([
                'is_enabled' => true,
                'position' => 50,
            ])->id;
        }

        $product = $this->giftCardProduct(['restrictions' => [
            'category_ids' => [$realIds[0], $realIds[0], $realIds[1]],
            'product_ids' => [],
            'exclude_category_ids' => [],
            'exclude_product_ids' => [],
        ]]);

        $config = app(GiftCardService::class)->presentConfig($product);

        $this->assertSame([$realIds[0], $realIds[1]], $config['restrictions']['category_ids'], 'Duplicate ids must be collapsed.');
        $this->assertSame([], $config['restrictions']['product_ids']);
    }

    public function test_empty_restrictions_are_stored_as_null(): void
    {
        $this->seed();
        $product = $this->giftCardProduct(['restrictions' => [
            'category_ids' => [],
            'product_ids' => [],
            'exclude_category_ids' => [],
            'exclude_product_ids' => [],
        ]]);

        $this->assertNull(ProductGiftCard::query()->where('product_id', $product->id)->value('restrictions'));
    }

    public function test_allowed_category_restriction_blocks_unlisted_products(): void
    {
        $this->seed();
        $categoryId = (int) \App\Domain\Catalog\Models\Category::query()->value('id');
        $other = \App\Domain\Catalog\Models\Category::query()->create([
            'is_enabled' => true,
            'position' => 99,
        ]);

        // Only the other category is allowed, but the order line sits in $categoryId.
        $product = $this->giftCardProduct(['restrictions' => [
            'category_ids' => [$other->id],
        ]]);
        $card = $this->activeCard(['product_id' => $product->id]);

        $order = $this->orderWithGiftCardItem($product, ['amount' => 100]);

        $this->expectException(ValidationException::class);
        app(GiftCardService::class)->revalidateForTotals($card->code, 50, 100, [
            ['product_id' => $product->id, 'category_ids' => [$categoryId]],
        ]);
    }

    public function test_allowed_category_restriction_permits_matching_products(): void    {
        $this->seed();
        $categoryId = (int) \App\Domain\Catalog\Models\Category::query()->value('id');

        $product = $this->giftCardProduct(['restrictions' => [
            'category_ids' => [$categoryId],
        ]]);
        $card = $this->activeCard(['product_id' => $product->id]);

        $result = app(GiftCardService::class)->revalidateForTotals($card->code, 50, 100, [
            ['product_id' => $product->id, 'category_ids' => [$categoryId]],
        ]);

        $this->assertEquals(50, $result['credit']);
    }

    public function test_excluded_product_restriction_blocks_that_product(): void
    {
        $this->seed();
        $excluded = Product::query()->where('sku', 'DEMO-001')->firstOrFail();

        $product = $this->giftCardProduct(['restrictions' => [
            'exclude_product_ids' => [$excluded->id],
        ]]);
        $card = $this->activeCard(['product_id' => $product->id]);

        $this->expectException(ValidationException::class);
        app(GiftCardService::class)->revalidateForTotals($card->code, 50, 100, [
            ['product_id' => $excluded->id, 'category_ids' => []],
        ]);
    }

    public function test_excluded_category_restriction_blocks_that_category(): void
    {
        $this->seed();
        $categoryId = (int) \App\Domain\Catalog\Models\Category::query()->value('id');
        $product = $this->giftCardProduct(['restrictions' => [
            'exclude_category_ids' => [$categoryId],
        ]]);
        $card = $this->activeCard(['product_id' => $product->id]);

        $this->expectException(ValidationException::class);
        app(GiftCardService::class)->revalidateForTotals($card->code, 50, 100, [
            ['product_id' => 999, 'category_ids' => [$categoryId]],
        ]);
    }

    public function test_no_restrictions_allows_any_product(): void
    {
        $this->seed();
        $product = $this->giftCardProduct();
        $card = $this->activeCard(['product_id' => $product->id]);
        $other = Product::query()->where('sku', 'DEMO-001')->firstOrFail();

        $result = app(GiftCardService::class)->revalidateForTotals($card->code, 50, 100, [
            ['product_id' => $other->id, 'category_ids' => [3]],
        ]);

        $this->assertEquals(50, $result['credit']);
    }

    public function test_restriction_lines_hydrate_missing_categories(): void
    {
        $this->seed();
        $product = $this->giftCardProduct();

        $lines = app(GiftCardService::class)->restrictionLines(
            collect([(object) ['product_id' => $product->id]]),
        );

        $hydrated = app(GiftCardService::class)->loadRestrictionCategories($lines);

        $this->assertNotEmpty($hydrated[0]['category_ids'], 'Category ids must be resolved from the product.');
    }
    public function test_restriction_ids_are_validated_server_side(): void
    {
        $this->seed();
        $admin = Admin::query()->first();

        $this->actingAs($admin, 'admin')
            ->post('/admin/products', $this->withProductCategories([
                'type' => 'gift_card',
                'name' => 'GC',
                'slug' => 'gc-bad-restriction',
                'sku' => 'GC-BAD-RESTRICT',
                'price' => 100,
                'is_enabled' => true,
                'gift_card' => [
                    'amount_type' => 'fixed',
                    'restrictions' => ['category_ids' => [999999]],
                ],
                'gift_card_amounts' => [['value' => 100, 'is_enabled' => true, 'position' => 0]],
            ]))
            ->assertSessionHasErrors('gift_card.restrictions.category_ids.0');
    }

    /** --- Cart integration ----------------------------------------------- */

    public function test_gift_card_code_credits_cart_and_is_capped_by_total(): void
    {
        $this->seed();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $card = $this->activeCard(['product_id' => $product->id, 'current_balance' => 5, 'initial_balance' => 5]);

        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/cart/gift-card', ['code' => $card->code])->assertOk();

        $totals = $this->getJson('/api/v1/cart')->json('totals');
        $this->assertEqualsWithDelta(5, (float) $totals['gift_card_credit'], 0.01);
    }

    /** --- Regression ----------------------------------------------------- */

    public function test_gift_card_does_not_require_shipping_and_ignores_stock(): void
    {
        $this->seed();
        $product = $this->giftCardProduct();
        $product->refresh();

        $this->assertFalse($product->requiresShipping());
        $this->assertFalse((bool) $product->manage_stock);
    }

    public function test_mixed_cart_with_gift_card_and_normal_product_totals_correctly(): void
    {
        $this->seed();
        $giftProduct = $this->giftCardProduct();
        $amount = $giftProduct->giftCardAmounts()->firstOrFail();
        $normal = Product::query()->where('sku', 'DEMO-001')->firstOrFail();

        $cart = Cart::query()->create(['session_id' => 'mixed-cart']);

        app(CartService::class)->add($cart, $normal, 1);
        app(CartService::class)->add($cart, $giftProduct, 1, [], [], null, [
            'fixed_amount_id' => $amount->id,
            'amount' => 100,
            'selling_price' => 100,
            'recipient_email' => 'alex@example.com',
        ]);

        $totals = app(CartService::class)->summary($cart);

        $this->assertGreaterThan(0, $totals['subtotal']);
        $this->assertCount(2, $cart->fresh()->items);
    }
}
