<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Payment\CheckoutLifecycle;
use App\Domain\Payment\Contracts\ChargesSavedMethods;
use App\Domain\Payment\Contracts\PaymentGateway;
use App\Domain\Payment\Models\SavedPaymentMethod;
use App\Domain\Payment\PaymentCaptureResult;
use App\Domain\Payment\PaymentGatewayRegistry;
use App\Domain\Payment\SavedChargeResult;
use App\Domain\Payment\Services\SavedMethodCharger;
use App\Domain\Settings\ShopSetting;
use App\Domain\Subscription\Models\CustomerSubscription;
use App\Domain\Subscription\Models\SubscriptionPlan;
use App\Domain\Subscription\Services\SubscriptionBilling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exercises the real off-session charging path — the classes that are skipped whenever a
 * test runs in sandbox mode: SavedMethodCharger, SavedChargeResult, SavedPaymentMethod,
 * ChargesSavedMethods and the gateway registry.
 *
 * A fake gateway stands in for Stripe so the whole path runs without network calls.
 */
class SavedMethodChargingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        // Real charging is enabled; the fake gateway supplies the capability.
        ShopSetting::put('payments.charge_saved_methods', true);

        // The fake gateway holds static state; reset it so one test cannot leak into the
        // next (which caused order-dependent failures).
        FakeRecurringGateway::reset();

        app(PaymentGatewayRegistry::class)->register(new FakeRecurringGateway);
    }

    private function orderWithCustomer(string $driver = 'fake-recurring'): Order
    {
        $customer = Customer::factory()->create(['email' => 'saved-'.uniqid().'@example.test']);

        $method = \App\Domain\Payment\Models\PaymentMethod::query()->create([
            'code' => 'FAKE-'.uniqid(),
            'name' => 'Fake Recurring',
            'driver' => $driver,
            'is_enabled' => true,
        ]);

        return Order::createTrusted([
            'number' => 'ORD-SAVED-'.uniqid(),
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'pending',
            'currency_code' => 'USD',
            'subtotal' => 40,
            'grand_total' => 40,
            'payment_method_id' => $method->id,
            'payment_status' => 'pending',
            'amount_due' => 40,
            'billing_address' => ['first_name' => 'A', 'line1' => 'x', 'city' => 'y', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'A', 'line1' => 'x', 'city' => 'y', 'country_code' => 'US'],
        ]);
    }

    private function saveMethod(Customer $customer, string $reference = 'pm_saved_ok', array $overrides = []): SavedPaymentMethod
    {
        return SavedPaymentMethod::query()->create(array_merge([
            'customer_id' => $customer->id,
            'driver' => 'fake-recurring',
            'reference' => $reference,
            'brand' => 'visa',
            'last4' => '4242',
            'is_default' => true,
        ], $overrides));
    }

    public function test_registry_reports_only_capable_gateways_as_recurring(): void
    {
        $registry = app(PaymentGatewayRegistry::class);

        $this->assertContains('fake-recurring', $registry->recurringCapableIds());
        // Offline / redirect gateways must never claim they can charge off-session.
        $this->assertNotContains('offline', $registry->recurringCapableIds());
    }

    public function test_charger_requires_a_capable_gateway(): void
    {
        $charger = app(SavedMethodCharger::class);
        $order = $this->orderWithCustomer('offline');

        $this->assertFalse($charger->canCharge($order), 'offline must not be chargeable off-session');
        $this->assertNull($charger->charge($order, 'key'), 'it must not pretend to charge');
    }

    public function test_charger_finds_the_customers_saved_method(): void
    {
        $charger = app(SavedMethodCharger::class);
        $order = $this->orderWithCustomer();
        $method = $this->saveMethod($order->customer);

        $found = $charger->methodFor($order);

        $this->assertNotNull($found);
        $this->assertSame($method->id, $found->id);
    }

    public function test_charger_ignores_revoked_and_expired_methods(): void
    {
        $charger = app(SavedMethodCharger::class);
        $order = $this->orderWithCustomer();

        $this->saveMethod($order->customer, 'pm_revoked', ['revoked_at' => now()]);
        $this->saveMethod($order->customer, 'pm_expired', ['exp_year' => now()->subYear()->year]);

        $this->assertNull($charger->methodFor($order), 'a revoked or expired card must not be selected');
    }

    public function test_charger_sends_a_stable_idempotency_key(): void
    {
        FakeRecurringGateway::$lastPayload = null;
        $charger = app(SavedMethodCharger::class);
        $order = $this->orderWithCustomer();
        $this->saveMethod($order->customer);

        $key = $charger->idempotencyKey('subscription', 7, '2026-10-01', 1);
        $charger->charge($order, $key);

        $this->assertSame($key, FakeRecurringGateway::$lastPayload['idempotency_key'] ?? null);
        // Same inputs must always produce the same key, or a retry could double-charge.
        $this->assertSame($key, $charger->idempotencyKey('subscription', 7, '2026-10-01', 1));
        $this->assertNotSame($key, $charger->idempotencyKey('subscription', 7, '2026-10-01', 2));
    }

    public function test_gateway_returns_paid_and_the_charger_passes_it_through(): void
    {
        FakeRecurringGateway::$nextStatus = 'paid';
        $charger = app(SavedMethodCharger::class);
        $order = $this->orderWithCustomer();
        $this->saveMethod($order->customer);

        $result = $charger->charge($order, 'k1');

        $this->assertNotNull($result);
        $this->assertTrue($result->isPaid());
        $this->assertFalse($result->needsCustomerAction());
    }

    public function test_sca_challenge_is_surfaced_not_treated_as_failure(): void
    {
        FakeRecurringGateway::$nextStatus = 'requires_action';
        // Must be a PSP host the redirect allowlist accepts, otherwise the link is
        // dropped — see test_sca_link_from_an_unlisted_host_is_dropped.
        FakeRecurringGateway::$nextUrl = 'https://checkout.stripe.com/c/pay/3ds-test';

        $charger = app(SavedMethodCharger::class);
        $order = $this->orderWithCustomer();
        $method = $this->saveMethod($order->customer);

        $result = $charger->charge($order, 'k2');

        $this->assertNotNull($result);
        $this->assertFalse($result->isPaid(), 'an SCA challenge is not a completed payment');
        $this->assertTrue($result->needsCustomerAction());
        $this->assertSame('https://checkout.stripe.com/c/pay/3ds-test', $result->redirectUrl);
        // The pending link is remembered so the merchant can see it.
        $this->assertSame('https://checkout.stripe.com/c/pay/3ds-test', $method->fresh()->pending_action_url);
    }

    public function test_sca_link_from_an_unlisted_host_degrades_to_failure(): void
    {
        FakeRecurringGateway::$nextStatus = 'requires_action';
        // A gateway whose 3DS host is not on the redirect allowlist cannot hand the
        // customer a usable link, so the charge must be recorded as failed rather than
        // sitting in "awaiting authentication" forever with the subscription still active.
        FakeRecurringGateway::$nextUrl = 'https://bank.example.test/3ds/abc';

        $order = $this->orderWithCustomer();
        $this->saveMethod($order->customer);

        $result = app(SavedMethodCharger::class)->charge($order, 'k6');

        $this->assertNotNull($result);
        $this->assertSame(SavedChargeResult::FAILED, $result->status);
        $this->assertNull($result->redirectUrl);
        $this->assertFalse($result->needsCustomerAction());
        $this->assertNotNull($result->message);
    }

    public function test_declined_charge_returns_the_gateway_message(): void
    {
        FakeRecurringGateway::$nextStatus = 'declined';
        $charger = app(SavedMethodCharger::class);
        $order = $this->orderWithCustomer();
        $this->saveMethod($order->customer);

        $result = $charger->charge($order, 'k3');

        $this->assertNotNull($result);
        $this->assertSame(SavedChargeResult::FAILED, $result->status);
        $this->assertSame('Card declined by issuer.', $result->message);
    }

    public function test_gateway_exception_becomes_a_recorded_failure_not_a_crash(): void
    {
        FakeRecurringGateway::$nextStatus = 'throw';
        $charger = app(SavedMethodCharger::class);
        $order = $this->orderWithCustomer();
        $this->saveMethod($order->customer);

        $result = $charger->charge($order, 'k4');

        $this->assertNotNull($result);
        $this->assertSame(SavedChargeResult::FAILED, $result->status);
        $this->assertNotNull($result->message);
    }

    public function test_unusable_reference_is_revoked_and_skipped(): void
    {
        FakeRecurringGateway::$referenceUsable = false;
        $charger = app(SavedMethodCharger::class);
        $order = $this->orderWithCustomer();
        $method = $this->saveMethod($order->customer);

        $result = $charger->charge($order, 'k5');

        $this->assertNull($result, 'a dead reference must not produce a charge');
        $this->assertNotNull($method->fresh()->revoked_at, 'the dead method must be revoked');
    }

    public function test_setting_disables_automatic_charging_entirely(): void
    {
        ShopSetting::put('payments.charge_saved_methods', false);

        $this->assertFalse(SavedMethodCharger::enabled());
    }

    public function test_renewal_charges_a_saved_card_and_marks_the_order_paid(): void
    {
        FakeRecurringGateway::$nextStatus = 'paid';
        $plan = SubscriptionPlan::query()->create([
            'code' => 'SAVED-'.uniqid(),
            'name' => 'Saved Card Plan',
            'billing_cycle' => 'monthly',
            'cycle' => 1,
            'frequency' => 'month',
            'is_enabled' => true,
        ]);
        $product = Product::query()->create([
            'type' => 'subscription',
            'sku' => 'SAVED-'.uniqid(),
            'price' => 40,
            'manage_stock' => false,
            'is_enabled' => true,
        ]);

        // Source order carrying the recurring payment method.
        $source = $this->orderWithCustomer();
        $source->updateTrusted(['status' => 'processing', 'payment_status' => 'paid', 'amount_paid' => 40, 'amount_due' => 0]);
        $item = OrderItem::query()->create([
            'order_id' => $source->id,
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'sku' => $product->sku,
            'name' => 'Saved Card Subscription',
            'qty' => 1,
            'unit_price' => 40,
            'total' => 40,
        ]);

        $customer = Customer::query()->findOrFail($source->customer_id);
        $this->saveMethod($customer);

        app(SubscriptionBilling::class)->startFromOrder($source->refresh());
        $sub = CustomerSubscription::query()->where('order_item_id', $item->id)->firstOrFail();
        $sub->forceFill(['next_renewal_at' => now()->subMinute()])->save();

        $processed = app(SubscriptionBilling::class)->tick(10);

        $fresh = $sub->fresh();
        $this->assertSame(1, $processed);
        $this->assertSame(CustomerSubscription::STATUS_ACTIVE, $fresh->status);
        $this->assertSame(1, (int) $fresh->cycles_completed);
        $this->assertSame(0, (int) $fresh->dunning_attempts);

        $renewal = Order::query()->find($fresh->last_renewal_order_id);
        $this->assertNotNull($renewal);
        $this->assertSame('paid', $renewal->payment_status, 'the saved card must actually have been charged');
        $this->assertSame('0.00', (string) $renewal->amount_due);
    }

    public function test_renewal_failure_records_the_gateway_reason_on_the_subscription(): void
    {
        FakeRecurringGateway::$nextStatus = 'declined';
        $plan = SubscriptionPlan::query()->create([
            'code' => 'DECL-'.uniqid(),
            'name' => 'Declined Plan',
            'billing_cycle' => 'monthly',
            'cycle' => 1,
            'frequency' => 'month',
            'is_enabled' => true,
        ]);
        $product = Product::query()->create([
            'type' => 'subscription',
            'sku' => 'DECL-'.uniqid(),
            'price' => 40,
            'manage_stock' => false,
            'is_enabled' => true,
        ]);
        $source = $this->orderWithCustomer();
        $source->updateTrusted(['status' => 'processing', 'payment_status' => 'paid', 'amount_paid' => 40, 'amount_due' => 0]);
        $item = OrderItem::query()->create([
            'order_id' => $source->id,
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'sku' => $product->sku,
            'name' => 'Declined Subscription',
            'qty' => 1,
            'unit_price' => 40,
            'total' => 40,
        ]);
        $customer = Customer::query()->findOrFail($source->customer_id);
        $this->saveMethod($customer);

        app(SubscriptionBilling::class)->startFromOrder($source->refresh());
        $sub = CustomerSubscription::query()->where('order_item_id', $item->id)->firstOrFail();
        $sub->forceFill(['next_renewal_at' => now()->subMinute()])->save();

        app(SubscriptionBilling::class)->tick(10);

        $fresh = $sub->fresh();
        $this->assertSame(CustomerSubscription::STATUS_PAST_DUE, $fresh->status);
        $this->assertSame('Card declined by issuer.', $fresh->last_renewal_error, 'support must see why it failed');
        $this->assertSame(0, (int) $fresh->cycles_completed);
    }
}

/**
 * Stand-in for a real recurring gateway (Stripe). Records what it was asked to do so the
 * tests can assert the charger's behaviour, without any network access.
 */
class FakeRecurringGateway implements ChargesSavedMethods, PaymentGateway
{
    public static ?string $nextStatus = 'paid';

    public static ?string $nextUrl = null;

    public static bool $referenceUsable = true;

    public static ?array $lastPayload = null;

    /** Static state must not leak between tests. */
    public static function reset(): void
    {
        self::$nextStatus = 'paid';
        self::$nextUrl = null;
        self::$referenceUsable = true;
        self::$lastPayload = null;
    }

    public function driver(): string
    {
        return 'fake-recurring';
    }

    public function checkoutLifecycle(): CheckoutLifecycle
    {
        return CheckoutLifecycle::OrderFirst;
    }

    public function capture(Order $order, array $payload = []): PaymentCaptureResult
    {
        return PaymentCaptureResult::pending();
    }

    public function supportsTransactionalCapture(): bool
    {
        return false;
    }

    public function storedReferenceIsUsable(?string $reference): bool
    {
        return self::$referenceUsable;
    }

    public function chargeStored(Order $order, string $reference, array $payload = []): SavedChargeResult
    {
        self::$lastPayload = $payload + ['reference' => $reference];

        return match (self::$nextStatus) {
            'paid' => SavedChargeResult::paid(['fake' => true]),
            'requires_action' => SavedChargeResult::requiresAction(self::$nextUrl ?: 'https://bank.test/3ds'),
            'throw' => throw new \RuntimeException('gateway exploded'),
            default => SavedChargeResult::failed('Card declined by issuer.'),
        };
    }
}
