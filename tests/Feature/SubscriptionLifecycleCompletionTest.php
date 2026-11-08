<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Subscription\Models\CustomerSubscription;
use App\Domain\Subscription\Models\ProductSubscriptionPlan;
use App\Domain\Subscription\Models\SubscriptionPlan;
use App\Domain\Subscription\Services\SubscriptionBilling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ChargeableRenewal;
use Tests\TestCase;

/**
 * Payment-gated signup, duration, cancel-at-period-end wiring, reactivate, change-plan,
 * guest block, and refund/cancel of signup order.
 */
class SubscriptionLifecycleCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function customer(string $email = 'life@example.test'): Customer
    {
        return Customer::factory()->create(['email' => $email]);
    }

    private function plan(array $overrides = []): SubscriptionPlan
    {
        return SubscriptionPlan::query()->create(array_merge([
            'code' => 'PLAN-'.uniqid(),
            'name' => 'Life Plan',
            'billing_cycle' => 'monthly',
            'interval_count' => 1,
            'cycle' => 1,
            'frequency' => 'month',
            'price' => 20,
            'is_enabled' => true,
            'duration' => 0,
        ], $overrides));
    }

    /**
     * @return array{0: Order, 1: OrderItem, 2: Product, 3: SubscriptionPlan}
     */
    private function unpaidOrder(?Customer $customer = null, ?SubscriptionPlan $plan = null): array
    {
        $customer ??= $this->customer();
        $plan ??= $this->plan();
        $product = Product::query()->create([
            'type' => 'subscription',
            'sku' => 'SUB-'.uniqid(),
            'price' => 20,
            'manage_stock' => false,
            'is_enabled' => true,
            'subscription_enabled' => true,
        ]);
        ProductSubscriptionPlan::query()->create([
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'price' => 20,
            'is_enabled' => true,
        ]);

        $order = Order::createTrusted([
            'number' => 'ORD-PEND-'.uniqid(),
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'pending',
            'currency_code' => 'USD',
            'subtotal' => 20,
            'grand_total' => 20,
            'payment_status' => 'pending',
            'amount_due' => 20,
            'billing_address' => ['first_name' => 'A', 'line1' => 'x', 'city' => 'y', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'A', 'line1' => 'x', 'city' => 'y', 'country_code' => 'US'],
        ]);
        $item = OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'sku' => $product->sku,
            'name' => 'Subscription',
            'qty' => 1,
            'unit_price' => 20,
            'total' => 20,
        ]);

        return [$order->refresh(), $item, $product, $plan];
    }

    private function subscribePaid(?Customer $customer = null, ?SubscriptionPlan $plan = null): CustomerSubscription
    {
        [$order, $item] = $this->unpaidOrder($customer, $plan);
        $order->updateTrusted([
            'payment_status' => 'paid',
            'amount_paid' => 20,
            'amount_due' => 0,
            'status' => 'processing',
        ]);
        app(SubscriptionBilling::class)->startFromOrder($order->fresh());

        return CustomerSubscription::query()->where('order_item_id', $item->id)->firstOrFail();
    }

    public function test_pending_order_creates_pending_subscriptions(): void
    {
        [$order] = $this->unpaidOrder();

        $row = CustomerSubscription::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($row);
        $this->assertSame(CustomerSubscription::STATUS_PENDING, $row->status);
        $this->assertNull($row->next_renewal_at);
        $this->assertNull($row->started_at);
    }

    public function test_paid_order_creates_a_subscription(): void
    {
        $sub = $this->subscribePaid();

        $this->assertSame(CustomerSubscription::STATUS_ACTIVE, $sub->status);
        $this->assertNotNull($sub->customer_id);
    }

    public function test_marking_pending_order_paid_activates_existing_subscription_idempotently(): void
    {
        [$order, $item] = $this->unpaidOrder();
        $billing = app(SubscriptionBilling::class);
        $pending = CustomerSubscription::query()->where('order_item_id', $item->id)->firstOrFail();
        $this->assertSame(CustomerSubscription::STATUS_PENDING, $pending->status);

        $order->updateTrusted(['payment_status' => 'paid', 'amount_paid' => 20, 'amount_due' => 0]);
        $billing->startFromOrder($order->fresh());
        $billing->startFromOrder($order->fresh());

        $this->assertSame(1, CustomerSubscription::query()->where('order_item_id', $item->id)->count());
        $activated = $pending->fresh();
        $this->assertSame(CustomerSubscription::STATUS_ACTIVE, $activated->status);
        $this->assertSame($pending->id, $activated->id);
        $this->assertNotNull($activated->next_renewal_at);
    }

    public function test_failed_payment_cancels_pending_subscription(): void
    {
        [$order, $item] = $this->unpaidOrder();
        $this->assertSame(CustomerSubscription::STATUS_PENDING, CustomerSubscription::query()->where('order_item_id', $item->id)->value('status'));

        $order->updateTrusted(['payment_status' => 'failed']);

        $row = CustomerSubscription::query()->where('order_item_id', $item->id)->firstOrFail();
        $this->assertSame(CustomerSubscription::STATUS_CANCELLED, $row->status);
    }

    public function test_guest_order_without_customer_does_not_create_subscription(): void
    {
        $plan = $this->plan();
        $product = Product::query()->create([
            'type' => 'subscription',
            'sku' => 'SUB-G-'.uniqid(),
            'price' => 20,
            'manage_stock' => false,
            'is_enabled' => true,
        ]);
        $order = Order::createTrusted([
            'number' => 'ORD-GUEST-'.uniqid(),
            'customer_id' => null,
            'email' => 'guest@example.test',
            'status' => 'processing',
            'currency_code' => 'USD',
            'subtotal' => 20,
            'grand_total' => 20,
            'payment_status' => 'paid',
            'amount_paid' => 20,
            'amount_due' => 0,
            'billing_address' => ['first_name' => 'A', 'line1' => 'x', 'city' => 'y', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'A', 'line1' => 'x', 'city' => 'y', 'country_code' => 'US'],
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'sku' => $product->sku,
            'name' => 'Subscription',
            'qty' => 1,
            'unit_price' => 20,
            'total' => 20,
        ]);

        app(SubscriptionBilling::class)->startFromOrder($order->fresh());

        $this->assertSame(0, CustomerSubscription::query()->where('order_id', $order->id)->count());
    }

    public function test_signup_order_refund_cancels_subscription(): void
    {
        $sub = $this->subscribePaid();
        $order = $sub->order;

        $order->updateTrusted(['payment_status' => 'refunded', 'status' => 'refunded']);
        app(SubscriptionBilling::class)->handleSignupOrderClosed($order->fresh());

        $this->assertSame(CustomerSubscription::STATUS_CANCELLED, $sub->fresh()->status);
    }

    public function test_plan_duration_ends_subscription_after_configured_cycles(): void
    {
        $plan = $this->plan(['duration' => 2]);
        $sub = $this->subscribePaid($this->customer(), $plan);
        ChargeableRenewal::attach($sub);

        $billing = app(SubscriptionBilling::class);
        for ($i = 0; $i < 2; $i++) {
            $sub->fresh()->forceFill(['next_renewal_at' => now()->subMinute()])->save();
            $billing->tick(10);
        }

        $fresh = $sub->fresh();
        $this->assertSame(CustomerSubscription::STATUS_CANCELLED, $fresh->status);
        $this->assertSame(2, (int) $fresh->cycles_completed);
        $this->assertNull($fresh->next_renewal_at);
    }

    public function test_cancel_at_period_end_via_service_and_resume(): void
    {
        $billing = app(SubscriptionBilling::class);
        $sub = $this->subscribePaid();
        ChargeableRenewal::attach($sub);

        $billing->cancel($sub, atPeriodEnd: true);
        $this->assertTrue($sub->fresh()->cancel_at_period_end);
        $this->assertSame(CustomerSubscription::STATUS_ACTIVE, $sub->fresh()->status);

        $billing->resumeCancelAtPeriodEnd($sub->fresh());
        $this->assertFalse($sub->fresh()->cancel_at_period_end);

        $billing->cancel($sub->fresh(), atPeriodEnd: true);
        $sub->fresh()->forceFill(['next_renewal_at' => now()->subMinute()])->save();
        $billing->tick(10);

        $this->assertSame(CustomerSubscription::STATUS_CANCELLED, $sub->fresh()->status);
    }

    public function test_reactivate_cancelled_subscription(): void
    {
        $billing = app(SubscriptionBilling::class);
        $sub = $this->subscribePaid();
        $billing->cancel($sub);

        $billing->reactivate($sub->fresh());

        $fresh = $sub->fresh();
        $this->assertSame(CustomerSubscription::STATUS_ACTIVE, $fresh->status);
        $this->assertNull($fresh->cancelled_at);
        $this->assertNotNull($fresh->next_renewal_at);
        $this->assertTrue($fresh->next_renewal_at->isFuture());
    }

    public function test_change_plan_updates_plan_and_order_item_price(): void
    {
        $customer = $this->customer();
        $planA = $this->plan(['code' => 'A-'.uniqid()]);
        $planB = $this->plan(['code' => 'B-'.uniqid()]);
        [$order, $item, $product] = $this->unpaidOrder($customer, $planA);
        ProductSubscriptionPlan::query()->create([
            'product_id' => $product->id,
            'subscription_plan_id' => $planB->id,
            'price' => 45,
            'is_enabled' => true,
        ]);
        $order->updateTrusted(['payment_status' => 'paid', 'amount_paid' => 20, 'amount_due' => 0]);
        app(SubscriptionBilling::class)->startFromOrder($order->fresh());
        $sub = CustomerSubscription::query()->where('order_item_id', $item->id)->firstOrFail();

        app(SubscriptionBilling::class)->changePlan($sub, (int) $planB->id);

        $fresh = $sub->fresh(['orderItem']);
        $this->assertSame((int) $planB->id, (int) $fresh->subscription_plan_id);
        $this->assertSame(45.0, (float) $fresh->orderItem->unit_price);
    }

    public function test_renewal_order_payment_does_not_spawn_another_subscription(): void
    {
        $sub = $this->subscribePaid();
        ChargeableRenewal::attach($sub);
        $before = CustomerSubscription::query()->count();

        $sub->forceFill(['next_renewal_at' => now()->subMinute()])->save();
        app(SubscriptionBilling::class)->tick(10);

        $this->assertSame($before, CustomerSubscription::query()->count());
    }

    public function test_customer_api_cancel_at_period_end_and_reactivate(): void
    {
        $customer = $this->customer('api-sub@example.test');
        $sub = $this->subscribePaid($customer);

        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/account/subscriptions/'.$sub->id.'/cancel-at-period-end')
            ->assertOk();

        $this->assertTrue($sub->fresh()->cancel_at_period_end);
        $this->assertSame(CustomerSubscription::STATUS_ACTIVE, $sub->fresh()->status);

        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/account/subscriptions/'.$sub->id.'/cancel')
            ->assertOk();

        $this->assertSame(CustomerSubscription::STATUS_CANCELLED, $sub->fresh()->status);

        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/account/subscriptions/'.$sub->id.'/reactivate')
            ->assertOk();

        $this->assertSame(CustomerSubscription::STATUS_ACTIVE, $sub->fresh()->status);
    }
}
