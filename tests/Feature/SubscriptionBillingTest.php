<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Subscription\Models\CustomerSubscription;
use App\Domain\Subscription\Models\SubscriptionPlan;
use App\Domain\Subscription\Services\SubscriptionBilling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ChargeableRenewal;
use Tests\TestCase;

/**
 * The billing engine: signup, trials, renewal, dunning, lifecycle.
 *
 * These paths had zero coverage before, which is how a sandbox-only renewal loop
 * shipped unnoticed.
 */
class SubscriptionBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function customer(string $email = 'billing@example.test'): Customer
    {
        return Customer::factory()->create(['email' => $email]);
    }

    private function plan(array $overrides = []): SubscriptionPlan
    {
        return SubscriptionPlan::query()->create(array_merge([
            'code' => 'PLAN-'.uniqid(),
            'name' => 'Billing Plan',
            'billing_cycle' => 'monthly',
            'interval_count' => 1,
            'cycle' => 1,
            'frequency' => 'month',
            'price' => 20,
            'is_enabled' => true,
        ], $overrides));
    }

    private function subscribe(Customer $customer, ?SubscriptionPlan $plan = null, int $qty = 1): CustomerSubscription
    {
        $plan ??= $this->plan();

        $product = Product::query()->create([
            'type' => 'subscription',
            'sku' => 'SUB-'.uniqid(),
            'price' => 20,
            'manage_stock' => false,
            'is_enabled' => true,
        ]);

        $order = Order::createTrusted([
            'number' => 'ORD-SUB-'.uniqid(),
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'processing',
            'currency_code' => 'USD',
            'subtotal' => 20 * $qty,
            'grand_total' => 20 * $qty,
            'payment_status' => 'paid',
            'amount_paid' => 20 * $qty,
            'amount_due' => 0,
            'billing_address' => ['first_name' => 'A', 'line1' => 'x', 'city' => 'y', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'A', 'line1' => 'x', 'city' => 'y', 'country_code' => 'US'],
        ]);

        $item = OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'sku' => $product->sku,
            'name' => 'Subscription',
            'qty' => $qty,
            'unit_price' => 20,
            'total' => 20 * $qty,
        ]);

        app(SubscriptionBilling::class)->startFromOrder($order->refresh());

        return CustomerSubscription::query()->where('order_item_id', $item->id)->firstOrFail();
    }

    private function makeDue(CustomerSubscription $sub): void
    {
        $sub->forceFill(['next_renewal_at' => now()->subMinute()])->save();
    }

    public function test_signup_creates_an_active_subscription_with_a_next_renewal(): void
    {
        $sub = $this->subscribe($this->customer());

        $this->assertSame(CustomerSubscription::STATUS_ACTIVE, $sub->status);
        $this->assertNotNull($sub->next_renewal_at);
        $this->assertTrue($sub->next_renewal_at->isFuture());
        $this->assertSame(0, (int) $sub->cycles_completed);
        $this->assertNull($sub->trial_ends_at);
    }

    public function test_signup_is_idempotent_per_order_item(): void
    {
        $customer = $this->customer();
        $sub = $this->subscribe($customer);

        // Replaying the payment event must not create a second subscription.
        app(SubscriptionBilling::class)->startFromOrder($sub->order->refresh());

        $this->assertSame(1, CustomerSubscription::query()->where('order_item_id', $sub->order_item_id)->count());
    }

    public function test_pause_resume_and_skip_transitions(): void
    {
        $billing = app(SubscriptionBilling::class);
        $sub = $this->subscribe($this->customer());

        $billing->pause($sub);
        $this->assertSame(CustomerSubscription::STATUS_PAUSED, $sub->fresh()->status);

        $billing->resume($sub);
        $this->assertSame(CustomerSubscription::STATUS_ACTIVE, $sub->fresh()->status);

        $before = $sub->fresh()->next_renewal_at->copy();
        $billing->skip($sub);
        $this->assertTrue($sub->fresh()->next_renewal_at->gt($before), 'skip must push the renewal date out');
    }

    public function test_cancel_sets_status_and_timestamp(): void
    {
        $sub = $this->subscribe($this->customer());
        app(SubscriptionBilling::class)->cancel($sub);

        $fresh = $sub->fresh();
        $this->assertSame(CustomerSubscription::STATUS_CANCELLED, $fresh->status);
        $this->assertNotNull($fresh->cancelled_at);
    }

    public function test_cancelled_subscription_is_never_renewed(): void
    {
        $billing = app(SubscriptionBilling::class);
        $sub = $this->subscribe($this->customer());
        $billing->cancel($sub);

        $this->makeDue($sub->fresh());

        $this->assertSame(0, $billing->tick(10), 'cancelled subscriptions must not be picked up');
    }

    public function test_paused_subscription_is_never_renewed(): void
    {
        $billing = app(SubscriptionBilling::class);
        $sub = $this->subscribe($this->customer());
        $billing->pause($sub);

        $this->makeDue($sub->fresh());

        $this->assertSame(0, $billing->tick(10), 'paused subscriptions must not be picked up');
    }

    public function test_trial_is_applied_at_signup_and_not_billed_immediately(): void
    {
        $plan = $this->plan([
            'trial_enabled' => true,
            'trial_duration' => 7,
            'trial_frequency' => 'day',
            'trial_cycle' => 1,
        ]);

        $sub = $this->subscribe($this->customer(), $plan);

        $this->assertSame(CustomerSubscription::STATUS_TRIALING, $sub->status, 'a trial plan must start trialing');
        $this->assertNotNull($sub->trial_ends_at);
        $this->assertTrue($sub->trial_ends_at->isFuture());
        $this->assertTrue($sub->onTrial());
        // The first renewal is the end of the trial, not one billing cycle out.
        $this->assertSame(
            $sub->trial_ends_at->toDateString(),
            $sub->next_renewal_at->toDateString(),
            'next renewal must be the trial end date',
        );
    }

    public function test_trial_without_duration_does_not_grant_free_service(): void
    {
        $plan = $this->plan([
            'trial_enabled' => true,
            'trial_duration' => 0,
        ]);

        $sub = $this->subscribe($this->customer(), $plan);

        $this->assertSame(CustomerSubscription::STATUS_ACTIVE, $sub->status);
        $this->assertNull($sub->trial_ends_at);
    }

    public function test_trial_subscription_converts_to_paid_on_first_tick(): void
    {
        $plan = $this->plan([
            'trial_enabled' => true,
            'trial_duration' => 7,
            'trial_frequency' => 'day',
        ]);

        $sub = $this->subscribe($this->customer(), $plan);
        $this->assertSame(CustomerSubscription::STATUS_TRIALING, $sub->status);
        ChargeableRenewal::attach($sub);

        $this->makeDue($sub->fresh());
        app(SubscriptionBilling::class)->tick(10);

        $fresh = $sub->fresh();
        $this->assertSame(CustomerSubscription::STATUS_ACTIVE, $fresh->status, 'trial must convert to active once paid');
        $this->assertNull($fresh->trial_ends_at);
        $this->assertSame(1, (int) $fresh->cycles_completed);
    }

    public function test_successful_renewal_creates_order_advances_cycle_and_clears_dunning(): void
    {
        $sub = $this->subscribe($this->customer());
        ChargeableRenewal::attach($sub);
        $this->makeDue($sub);

        $processed = app(SubscriptionBilling::class)->tick(10);

        $fresh = $sub->fresh();
        $this->assertSame(1, $processed);
        $this->assertSame(CustomerSubscription::STATUS_ACTIVE, $fresh->status);
        $this->assertSame(1, (int) $fresh->cycles_completed);
        $this->assertSame(0, (int) $fresh->dunning_attempts);
        $this->assertNull($fresh->last_renewal_error);
        $this->assertNotNull($fresh->last_renewal_order_id);
        $this->assertTrue($fresh->next_renewal_at->isFuture(), 'next renewal must move forward');

        $renewal = Order::query()->find($fresh->last_renewal_order_id);
        $this->assertNotNull($renewal, 'a renewal order must exist');
        $this->assertSame('paid', $renewal->payment_status);
        $this->assertSame(1, $renewal->items()->count(), 'the renewal order must carry the subscription line');
    }

    public function test_failed_renewal_goes_past_due_and_records_the_reason(): void
    {
        $sub = $this->subscribe($this->customer());
        $this->makeDue($sub);

        app(SubscriptionBilling::class)->tick(10);

        $fresh = $sub->fresh();
        $this->assertSame(CustomerSubscription::STATUS_PAST_DUE, $fresh->status);
        $this->assertSame(1, (int) $fresh->dunning_attempts);
        $this->assertSame(1, (int) $fresh->renewal_attempts);
        $this->assertNotNull($fresh->last_dunning_at);
        $this->assertNotNull($fresh->last_renewal_error, 'the failure reason must be recorded for support');
        // The customer still owes this period, so the renewal date does not advance.
        $this->assertSame(0, (int) $fresh->cycles_completed);
    }

    public function test_dunning_gives_up_and_cancels_instead_of_looping_forever(): void
    {
        $sub = $this->subscribe($this->customer());
        $this->makeDue($sub);

        $billing = app(SubscriptionBilling::class);

        // Drive the retries to exhaustion. Each pass re-arms the row as a due, active
        // subscription — which is exactly what a real dunning retry does.
        for ($i = 0; $i < CustomerSubscription::MAX_DUNNING_ATTEMPTS; $i++) {
            $row = $sub->fresh();
            if ($row->status === CustomerSubscription::STATUS_CANCELLED) {
                break;
            }
            $row->forceFill([
                'status' => CustomerSubscription::STATUS_ACTIVE,
                'next_renewal_at' => now()->subMinute(),
            ])->save();
            $billing->tick(10);
        }

        $fresh = $sub->fresh();
        $this->assertSame(
            CustomerSubscription::STATUS_CANCELLED,
            $fresh->status,
            'dunning must terminate, not leave the subscription past_due forever',
        );
        $this->assertNotNull($fresh->ended_at);
        $this->assertSame(0, $this->countDue($sub), 'a cancelled subscription must no longer be due');
    }

    /** How many times this subscription would be picked up by the tick. */
    private function countDue(CustomerSubscription $sub): int
    {
        return CustomerSubscription::query()
            ->whereKey($sub->id)
            ->whereIn('status', CustomerSubscription::RENEWABLE_STATUSES)
            ->whereNotNull('next_renewal_at')
            ->where('next_renewal_at', '<=', now())
            ->count();
    }

    public function test_renewal_is_idempotent_when_ticked_twice(): void
    {
        $sub = $this->subscribe($this->customer());
        ChargeableRenewal::attach($sub);
        $this->makeDue($sub);

        $billing = app(SubscriptionBilling::class);
        $billing->tick(10);
        $cyclesAfterFirst = (int) $sub->fresh()->cycles_completed;

        // A second run must find nothing due (next_renewal_at moved into the future).
        $second = $billing->tick(10);

        $this->assertSame(0, $second, 'a second tick must not re-process an already-renewed subscription');
        $this->assertSame($cyclesAfterFirst, (int) $sub->fresh()->cycles_completed);
    }

    public function test_concurrent_renewal_of_the_same_row_is_guarded(): void
    {
        $sub = $this->subscribe($this->customer());
        ChargeableRenewal::attach($sub);
        $this->makeDue($sub);

        $billing = app(SubscriptionBilling::class);

        // Simulates two workers racing on the same due subscription.
        $first = $billing->renewOne((int) $sub->id);
        $second = $billing->renewOne((int) $sub->id);

        $this->assertTrue($first);
        $this->assertFalse($second, 'the second worker must find the row no longer due');
        $this->assertSame(1, (int) $sub->fresh()->cycles_completed, 'the customer must be charged exactly once');
    }

    public function test_cancel_at_period_end_stops_after_the_final_paid_period(): void
    {
        $sub = $this->subscribe($this->customer());
        ChargeableRenewal::attach($sub);
        $sub->forceFill(['cancel_at_period_end' => true])->save();
        $this->makeDue($sub->fresh());

        app(SubscriptionBilling::class)->tick(10);

        $fresh = $sub->fresh();
        $this->assertSame(CustomerSubscription::STATUS_CANCELLED, $fresh->status);
        $this->assertNull($fresh->next_renewal_at, 'no further renewals may be scheduled');
        $this->assertSame(1, (int) $fresh->cycles_completed, 'the final period must still be charged');
    }

    public function test_renewal_order_carries_the_subscription_line_and_plan(): void
    {
        $sub = $this->subscribe($this->customer(), null, 2);
        ChargeableRenewal::attach($sub);
        $this->makeDue($sub);

        app(SubscriptionBilling::class)->tick(10);

        $renewal = Order::query()->find($sub->fresh()->last_renewal_order_id);
        $this->assertNotNull($renewal);

        $item = $renewal->items()->first();
        $this->assertSame($sub->subscription_plan_id, $item->subscription_plan_id, 'the renewal must stay on the same plan');
        $this->assertSame($sub->product_id, $item->product_id);
        $this->assertSame('Subscription renewal', $renewal->customer_note);
    }
}
