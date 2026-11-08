<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Subscription\Models\CustomerSubscription;
use App\Domain\Subscription\Models\SubscriptionPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountSubscriptionRenewalOrdersTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscription_detail_lists_all_renewal_orders_with_timestamps(): void
    {
        $this->seed();

        $customer = Customer::factory()->create();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $this->actingAs(\App\Models\Admin::query()->first(), 'admin');
        $this->post('/admin/subscription-plans', [
            'name' => 'Hourly Test',
            'is_enabled' => true,
            'duration' => 0,
            'cycle' => 1,
            'frequency' => 'hour',
        ])->assertRedirect();
        $plan = SubscriptionPlan::query()->orderByDesc('id')->firstOrFail();

        $address = [
            'first_name' => 'Demo',
            'last_name' => 'Customer',
            'address1' => '1 Test St',
            'city' => 'Testville',
            'postcode' => '10001',
            'country_code' => 'US',
        ];

        $signup = Order::createTrusted([
            'number' => 'WENTO-TEST-SIGNUP',
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
            'created_at' => now()->subHours(3),
            'updated_at' => now()->subHours(3),
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

        $firstRenewal = Order::createTrusted([
            'number' => 'WENTO-TEST-R1',
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'processing',
            'currency_code' => 'USD',
            'currency_rate' => 1,
            'subtotal' => 10,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'fee_total' => 0,
            'grand_total' => 10,
            'payment_status' => 'paid',
            'customer_note' => 'Subscription renewal',
            'billing_address' => $address,
            'shipping_address' => $address,
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ]);
        $firstRenewal->items()->create([
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

        $secondRenewal = Order::createTrusted([
            'number' => 'WENTO-TEST-R2',
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'processing',
            'currency_code' => 'USD',
            'currency_rate' => 1,
            'subtotal' => 10,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'fee_total' => 0,
            'grand_total' => 10,
            'payment_status' => 'paid',
            'customer_note' => 'Subscription renewal',
            'billing_address' => $address,
            'shipping_address' => $address,
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);
        $secondRenewal->items()->create([
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
            'started_at' => now()->subHours(3),
            'next_renewal_at' => now()->addHour(),
            'cycles_completed' => 2,
            'last_renewal_order_id' => $secondRenewal->id,
        ]);

        $response = $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/account/subscriptions/'.$subscription->id)
            ->assertOk()
            ->assertJsonPath('subscription.order_number', 'WENTO-TEST-SIGNUP')
            ->assertJsonCount(2, 'subscription.renewal_orders')
            ->assertJsonPath('subscription.renewal_orders.0.number', 'WENTO-TEST-R1')
            ->assertJsonPath('subscription.renewal_orders.1.number', 'WENTO-TEST-R2')
            ->assertJsonCount(3, 'subscription.invoice_orders')
            ->assertJsonPath('subscription.invoice_orders.0.number', 'WENTO-TEST-SIGNUP')
            ->assertJsonPath('subscription.invoice_orders.0.kind', 'original')
            ->assertJsonPath('subscription.invoice_orders.1.number', 'WENTO-TEST-R1')
            ->assertJsonPath('subscription.invoice_orders.1.kind', 'renewal')
            ->assertJsonPath('subscription.invoice_orders.2.number', 'WENTO-TEST-R2')
            ->assertJsonPath('subscription.invoice_orders.2.kind', 'renewal');

        $payload = $response->json('subscription');
        $this->assertNotEmpty($payload['order_created_at'] ?? null);
        $this->assertNotEmpty($payload['renewal_orders'][0]['created_at'] ?? null);
        $this->assertNotEmpty($payload['renewal_orders'][1]['created_at'] ?? null);
        $this->assertNotEmpty($payload['invoice_orders'][0]['created_at'] ?? null);
    }
}
