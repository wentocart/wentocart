<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Subscription\Models\CustomerSubscription;
use App\Domain\Subscription\Models\SubscriptionPlan;
use App\Domain\Subscription\Services\SubscriptionBilling;
use App\Models\Admin;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SubscriptionRenewalNoDriftTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_renew_one_advances_from_due_time_when_scheduler_is_late(): void
    {
        Mail::fake();
        Log::shouldReceive('error')->andReturnNull();
        Log::shouldReceive('warning')->andReturnNull();
        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('debug')->andReturnNull();
        config(['logging.default' => 'stderr', 'mail.default' => 'array']);

        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
        $this->post('/admin/subscription-plans', [
            'name' => 'Hour No Drift',
            'is_enabled' => true,
            'duration' => 0,
            'cycle' => 1,
            'frequency' => 'hour',
        ])->assertRedirect();
        $plan = SubscriptionPlan::query()->orderByDesc('id')->firstOrFail();

        $customer = Customer::factory()->create();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $address = [
            'first_name' => 'Demo',
            'last_name' => 'Customer',
            'address1' => '1 Test St',
            'city' => 'Testville',
            'postcode' => '10001',
            'country_code' => 'US',
        ];

        $started = Carbon::parse('2026-10-07 07:04:50');
        $due = Carbon::parse('2026-10-07 08:04:50');
        Carbon::setTestNow($started);

        $signup = Order::createTrusted([
            'number' => 'WENTO-NODRIFT-SIGNUP',
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'completed',
            'currency_code' => 'USD',
            'currency_rate' => 1,
            'subtotal' => 10,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'fee_total' => 0,
            'grand_total' => 10,
            'payment_status' => 'paid',
            'billing_address' => $address,
            'shipping_address' => $address,
            'created_at' => $started,
            'updated_at' => $started,
        ]);
        $item = $signup->items()->create([
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'sku' => $product->sku,
            'name' => 'Demo product',
            'qty' => 1,
            'unit_price' => 10,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'total' => 10,
        ]);

        $subscription = CustomerSubscription::query()->create([
            'customer_id' => $customer->id,
            'order_id' => $signup->id,
            'order_item_id' => $item->id,
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'status' => CustomerSubscription::STATUS_ACTIVE,
            'started_at' => $started,
            'next_renewal_at' => $due,
            'cycles_completed' => 0,
        ]);

        // Process 70 seconds after the scheduled due time.
        Carbon::setTestNow($due->copy()->addSeconds(70));
        $this->assertTrue(app(SubscriptionBilling::class)->renewOne($subscription->id));

        $subscription->refresh();
        $this->assertSame(1, (int) $subscription->cycles_completed);
        $this->assertSame(
            '2026-10-07 09:04:50',
            $subscription->next_renewal_at?->format('Y-m-d H:i:s'),
            'Next due must stay on the original cadence, not shift by the late processing delay.',
        );
    }
}
