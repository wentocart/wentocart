<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Subscription\Models\CustomerSubscription;
use App\Domain\Subscription\Models\SubscriptionPlan;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCustomerSubscriptionShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_customer_subscription_show_page(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->post('/admin/subscription-plans', [
            'name' => 'Show Plan',
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

        $signup = Order::createTrusted([
            'number' => 'WENTO-SHOW-1',
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
        ]);
        $item = $signup->items()->create([
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'sku' => $product->sku,
            'name' => 'Demo',
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
            'started_at' => now()->subHour(),
            'next_renewal_at' => now()->addHour(),
            'cycles_completed' => 0,
        ]);

        $this->get('/admin/customer-subscriptions/'.$subscription->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Catalog/Subscriptions/CustomerShow', false)
                ->where('subscription.id', $subscription->id)
                ->where('subscription.order.url', '/admin/orders/'.$signup->id)
                ->has('renewals')
                ->where('renewals.0.kind', 'original')
                ->where('renewals.0.number', 'WENTO-SHOW-1')
                ->where('renewals.0.id', $signup->id));

        // Invoice "View" may link by public number; binding must resolve either key.
        $this->get('/admin/orders/'.$signup->number)->assertOk();
        $this->get('/admin/orders/'.$signup->id)->assertOk();
    }
}
