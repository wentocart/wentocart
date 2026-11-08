<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\GiftCard;
use App\Domain\Catalog\Models\GiftCardTransaction;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\GiftCardService;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Payment\CheckoutLifecycle;
use App\Domain\Payment\Contracts\PaymentGateway;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Payment\PaymentCaptureResult;
use App\Domain\Payment\PaymentGatewayRegistry;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * P0 money-path coverage: HOLD → COMMIT/RELEASE for deferred PSP + gift cards.
 */
class GiftCardDeferredPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    protected function activeCard(float $balance = 500, array $overrides = []): GiftCard
    {
        $admin = Admin::query()->first() ?: Admin::factory()->create();
        $this->actingAs($admin, 'admin');

        $payload = $this->withProductCategories([
            'type' => 'gift_card',
            'name' => 'GC Product',
            'slug' => 'gc-'.uniqid(),
            'sku' => 'GC-'.strtoupper(uniqid()),
            'price' => $balance,
            'is_enabled' => true,
            'gift_card' => [
                'amount_type' => 'fixed',
                'delivery_method' => 'email',
                'validity_type' => 'never',
                'allow_online_redemption' => true,
                'allow_offline_redemption' => true,
                'allow_partial_redemption' => true,
                'usage_type' => 'multiple',
                'code_length' => 12,
            ],
            'gift_card_amounts' => [
                ['value' => $balance, 'selling_price' => $balance, 'is_enabled' => true, 'position' => 0],
            ],
        ]);
        $this->post('/admin/products', $payload)->assertProductSaveRedirect();
        $product = Product::query()->orderByDesc('id')->firstOrFail();

        \Illuminate\Support\Facades\Auth::guard('admin')->logout();

        return GiftCard::query()->create(array_merge([
            'product_id' => $product->id,
            'code' => 'GC'.strtoupper(uniqid()),
            'initial_balance' => $balance,
            'current_balance' => $balance,
            'selected_amount' => $balance,
            'status' => 'active',
            'issued_at' => now(),
        ], $overrides));
    }

    protected function sellableProduct(float $price = 1000): Product
    {
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $product->update(['price' => $price, 'is_enabled' => true]);

        return $product->fresh();
    }

    protected function registerBuyer(string $email = 'buyer@example.com'): Customer
    {
        $this->postJson('/api/v1/auth/logout');
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Pat Buyer',
            'email' => $email,
            'password' => 'Password1a',
            'password_confirmation' => 'Password1a',
        ])->assertOk();

        return Customer::query()->where('email', $email)->firstOrFail();
    }

    /** @return array<string, mixed> */
    protected function checkoutPayload(PaymentMethod $payment): array
    {
        $shipping = ShippingMethod::query()->where('code', 'flat')->first()
            ?: ShippingMethod::query()->where('is_enabled', true)->firstOrFail();

        return [
            'email' => 'buyer@example.com',
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Pat', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Pat', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ];
    }

    protected function registerFakeGateway(string $driver, callable $capture): PaymentMethod
    {
        $fake = new class($driver, $capture) implements PaymentGateway
        {
            public function __construct(private string $driverName, private $captureFn) {}

            public function driver(): string
            {
                return $this->driverName;
            }

            public function checkoutLifecycle(): CheckoutLifecycle
            {
                return CheckoutLifecycle::OrderFirst;
            }

            public function supportsTransactionalCapture(): bool
            {
                return false;
            }

            public function capture(Order $order, array $payload = []): PaymentCaptureResult
            {
                return ($this->captureFn)($order, $payload);
            }
        };
        app(PaymentGatewayRegistry::class)->register($fake);

        $payment = PaymentMethod::query()->whereNull('seller_id')->where('is_enabled', true)->firstOrFail();
        $payment->update(['driver' => $driver]);

        return $payment->fresh();
    }

    public function test_deferred_payment_success_consumes_gift_card_once(): void
    {
        $card = $this->activeCard(500);
        $product = $this->sellableProduct(1000);
        $payment = $this->registerFakeGateway('fake_gc_ok', fn () => PaymentCaptureResult::paid(['ok' => true]));

        $this->registerBuyer();
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/cart/gift-card', ['code' => $card->code])->assertOk()
            ->assertJsonPath('totals.gift_card_credit', 500);

        $this->postJson('/api/v1/checkout', $this->checkoutPayload($payment))->assertSuccessful();

        $card->refresh();
        $this->assertEqualsWithDelta(0, (float) $card->current_balance, 0.01);
        $this->assertSame(1, GiftCardTransaction::query()
            ->where('gift_card_id', $card->id)
            ->where('type', GiftCardService::TYPE_REDEMPTION)
            ->count());
        $this->assertSame(0, GiftCardTransaction::query()
            ->where('gift_card_id', $card->id)
            ->where('type', GiftCardService::TYPE_HOLD)
            ->where('metadata->state', GiftCardService::HOLD_STATE_OPEN)
            ->count());
    }

    public function test_deferred_payment_failure_restores_gift_card_balance(): void
    {
        $card = $this->activeCard(500);
        $product = $this->sellableProduct(1000);
        $payment = $this->registerFakeGateway('fake_gc_fail', fn () => PaymentCaptureResult::failed('Card declined'));

        $this->registerBuyer();
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/cart/gift-card', ['code' => $card->code])->assertOk();

        $this->postJson('/api/v1/checkout', $this->checkoutPayload($payment))->assertStatus(422);

        $card->refresh();
        $this->assertEqualsWithDelta(500, (float) $card->current_balance, 0.01);
        $this->assertSame('active', $card->status);
        $this->assertSame(1, GiftCardTransaction::query()
            ->where('gift_card_id', $card->id)
            ->where('type', GiftCardService::TYPE_HOLD)
            ->count());
        $this->assertSame(1, GiftCardTransaction::query()
            ->where('gift_card_id', $card->id)
            ->where('type', GiftCardService::TYPE_RELEASE)
            ->count());
        $this->assertSame(0, GiftCardTransaction::query()
            ->where('gift_card_id', $card->id)
            ->where('type', GiftCardService::TYPE_REDEMPTION)
            ->count());
    }

    public function test_deferred_payment_cancel_releases_hold(): void
    {
        $card = $this->activeCard(500);
        $product = $this->sellableProduct(1000);
        $calls = 0;
        $payment = $this->registerFakeGateway('fake_gc_redirect', function () use (&$calls) {
            $calls++;

            return PaymentCaptureResult::redirect('https://example.test/pay', ['intent' => 'x']);
        });

        $this->registerBuyer();
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/cart/gift-card', ['code' => $card->code])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload($payment))->assertSuccessful();

        $card->refresh();
        $this->assertEqualsWithDelta(0, (float) $card->current_balance, 0.01, 'Hold debits balance while pending');
        $this->assertSame(1, GiftCardTransaction::query()
            ->where('gift_card_id', $card->id)
            ->where('type', GiftCardService::TYPE_HOLD)
            ->where('metadata->state', GiftCardService::HOLD_STATE_OPEN)
            ->count());

        $order = Order::query()->latest('id')->firstOrFail();
        $order->updateTrusted(['status' => 'cancelled']);

        $card->refresh();
        $this->assertEqualsWithDelta(500, (float) $card->current_balance, 0.01);
        $this->assertSame(1, GiftCardTransaction::query()
            ->where('gift_card_id', $card->id)
            ->where('type', GiftCardService::TYPE_RELEASE)
            ->count());
    }

    public function test_duplicate_paid_webhook_does_not_double_consume(): void
    {
        $card = $this->activeCard(500);
        $order = Order::createTrusted([
            'number' => 'WENTO-GC-'.uniqid(),
            'email' => 'buyer@example.com',
            'status' => 'pending',
            'currency_code' => 'USD',
            'subtotal' => 1000,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'grand_total' => 500,
            'payment_status' => 'pending',
            'payment_details' => ['gift_card_code' => $card->code, 'gift_card_credit' => 500],
            'billing_address' => ['first_name' => 'P', 'line1' => '1', 'city' => 'X', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'P', 'line1' => '1', 'city' => 'X', 'country_code' => 'US'],
        ]);

        $service = app(GiftCardService::class);
        $service->hold($card->code, 500, $order);
        $service->commitHold($order);
        $service->commitHold($order); // duplicate webhook / retry

        $order->updateTrusted(['payment_status' => 'paid']); // observer safety net
        $service->commitHold($order->fresh());

        $card->refresh();
        $this->assertEqualsWithDelta(0, (float) $card->current_balance, 0.01);
        $this->assertSame(1, GiftCardTransaction::query()
            ->where('gift_card_id', $card->id)
            ->where('type', GiftCardService::TYPE_REDEMPTION)
            ->count());
    }

    public function test_retry_after_failure_consumes_only_on_success(): void
    {
        $card = $this->activeCard(500);
        $product = $this->sellableProduct(1000);
        $fail = true;
        $payment = $this->registerFakeGateway('fake_gc_retry', function () use (&$fail) {
            if ($fail) {
                $fail = false;

                return PaymentCaptureResult::failed('Temporary decline');
            }

            return PaymentCaptureResult::paid(['retry' => true]);
        });

        $this->registerBuyer();
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/cart/gift-card', ['code' => $card->code])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload($payment))->assertStatus(422);

        $card->refresh();
        $this->assertEqualsWithDelta(500, (float) $card->current_balance, 0.01);

        // Cart was cleared on place attempt — rebuild.
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/cart/gift-card', ['code' => $card->code])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload($payment))->assertSuccessful();

        $card->refresh();
        $this->assertEqualsWithDelta(0, (float) $card->current_balance, 0.01);
        $this->assertSame(1, GiftCardTransaction::query()
            ->where('gift_card_id', $card->id)
            ->where('type', GiftCardService::TYPE_REDEMPTION)
            ->count());
    }

    public function test_order_cancelled_while_payment_pending_releases_reservation(): void
    {
        $card = $this->activeCard(250);
        $order = Order::createTrusted([
            'number' => 'WENTO-PEND-'.uniqid(),
            'email' => 'buyer@example.com',
            'status' => 'pending',
            'currency_code' => 'USD',
            'subtotal' => 400,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'grand_total' => 150,
            'payment_status' => 'pending',
            'payment_details' => ['gift_card_credit' => 250],
            'billing_address' => ['first_name' => 'P', 'line1' => '1', 'city' => 'X', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'P', 'line1' => '1', 'city' => 'X', 'country_code' => 'US'],
        ]);

        app(GiftCardService::class)->hold($card->code, 250, $order);
        $this->assertEqualsWithDelta(0, (float) $card->fresh()->current_balance, 0.01);

        $order->updateTrusted(['status' => 'cancelled']);

        $this->assertEqualsWithDelta(250, (float) $card->fresh()->current_balance, 0.01);
    }

    public function test_concurrent_redemption_only_one_succeeds(): void
    {
        $card = $this->activeCard(100);
        $service = app(GiftCardService::class);

        $ok = 0;
        $fail = 0;
        try {
            DB::transaction(function () use ($service, $card, &$ok) {
                $service->redeem($card->code, 100);
                $ok++;
                // Nested attempt under same connection would see updated balance.
            });
        } catch (ValidationException) {
            $fail++;
        }

        try {
            $service->redeem($card->code, 100);
            $ok++;
        } catch (ValidationException) {
            $fail++;
        }

        $this->assertSame(1, $ok);
        $this->assertSame(1, $fail);
        $this->assertEqualsWithDelta(0, (float) $card->fresh()->current_balance, 0.01);
    }

    public function test_cart_apply_validates_min_order_against_pre_credit_total(): void
    {
        $admin = Admin::query()->first() ?: Admin::factory()->create();
        $this->actingAs($admin, 'admin');
        $payload = $this->withProductCategories([
            'type' => 'gift_card',
            'name' => 'Restricted GC',
            'slug' => 'gc-min-'.uniqid(),
            'sku' => 'GCMIN-'.strtoupper(uniqid()),
            'price' => 50,
            'is_enabled' => true,
            'gift_card' => [
                'amount_type' => 'fixed',
                'delivery_method' => 'email',
                'validity_type' => 'never',
                'allow_online_redemption' => true,
                'allow_partial_redemption' => true,
                'usage_type' => 'multiple',
                'code_length' => 12,
                'min_order_amount' => 5000,
            ],
            'gift_card_amounts' => [
                ['value' => 50, 'selling_price' => 50, 'is_enabled' => true, 'position' => 0],
            ],
        ]);
        $this->post('/admin/products', $payload)->assertRedirect();
        $gcProduct = Product::query()->orderByDesc('id')->firstOrFail();
        \Illuminate\Support\Facades\Auth::guard('admin')->logout();
        $card = GiftCard::query()->create([
            'product_id' => $gcProduct->id,
            'code' => 'MIN'.strtoupper(uniqid()),
            'initial_balance' => 50,
            'current_balance' => 50,
            'selected_amount' => 50,
            'status' => 'active',
            'issued_at' => now(),
        ]);

        $product = $this->sellableProduct(100);
        $this->registerBuyer('min@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/cart/gift-card', ['code' => $card->code])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['gift_card']);
    }

    public function test_apply_lockout_after_repeated_invalid_codes(): void
    {
        $product = $this->sellableProduct(50);
        $this->registerBuyer('lock@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();

        for ($i = 0; $i < GiftCardService::APPLY_FAIL_LIMIT; $i++) {
            $this->postJson('/api/v1/cart/gift-card', ['code' => 'INVALID'.$i])->assertStatus(422);
        }

        $this->postJson('/api/v1/cart/gift-card', ['code' => 'STILLBAD'])
            ->assertStatus(422)
            ->assertJsonFragment(['gift_card' => ['Too many invalid gift card attempts. Please try again later.']]);
    }

    public function test_expire_command_is_idempotent(): void
    {
        $card = $this->activeCard(40, ['expires_at' => now()->subDay()]);
        $this->artisan('wento:gift-cards-expire')->assertSuccessful();
        $this->assertSame('expired', $card->fresh()->status);
        $this->artisan('wento:gift-cards-expire')->assertSuccessful();
        $this->assertSame('expired', $card->fresh()->status);
    }

    public function test_cart_remove_gift_card_endpoint(): void
    {
        $card = $this->activeCard(80);
        $product = $this->sellableProduct(100);
        $this->registerBuyer('remove@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/cart/gift-card', ['code' => $card->code])->assertOk()
            ->assertJsonPath('totals.gift_card_credit', 80);
        $this->deleteJson('/api/v1/cart/gift-card')->assertOk()
            ->assertJsonPath('totals.gift_card_credit', 0);
    }

    public function test_admin_customer_search_returns_results_shape(): void
    {
        Customer::createTrusted([
            'email' => 'findme@example.com',
            'name' => 'Find Me',
            'first_name' => 'Find',
            'last_name' => 'Me',
            'password' => 'password',
            'is_enabled' => true,
        ]);
        $this->actingAs(Admin::query()->first(), 'admin')
            ->getJson('/admin/gift-cards/customers?q=findme')
            ->assertOk()
            ->assertJsonStructure(['results' => [['id', 'name']]]);
    }
}
