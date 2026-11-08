<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
    }

    public function test_can_create_order_with_partial_payment(): void
    {
        $customer = Customer::query()->first() ?? Customer::factory()->create([
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
        ]);
        $payment = PaymentMethod::query()->firstOrFail();
        $shipping = ShippingMethod::query()->firstOrFail();

        $response = $this->post('/admin/orders', [
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'currency_code' => 'USD',
            'items' => [
                [
                    'name' => 'Custom Item',
                    'sku' => 'CUSTOM-1',
                    'qty' => 1,
                    'unit_price' => 100,
                    'tax_rate' => 0,
                    'discount' => 0,
                ],
            ],
            'shipping_method_id' => $shipping->id,
            'payment_method_id' => $payment->id,
            'payment_status' => 'partial',
            'paid_amount' => 40,
            'shipping_address' => [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'line1' => '123 Main St',
                'city' => 'Austin',
                'state' => 'TX',
                'postal_code' => '78701',
                'country_code' => 'US',
            ],
            'billing_address' => [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'line1' => '123 Main St',
                'city' => 'Austin',
                'state' => 'TX',
                'postal_code' => '78701',
                'country_code' => 'US',
            ],
        ]);

        $response->assertRedirect();
        $order = Order::query()->latest('id')->firstOrFail();
        $this->assertSame('partial', $order->payment_status);
        $this->assertEquals(40.0, (float) $order->amount_paid);
        $this->assertEquals((float) $order->grand_total - 40.0, (float) $order->amount_due);
    }

    public function test_order_index_sorting_and_filtering(): void
    {
        $customerA = Customer::factory()->create(['name' => 'Alice Adams', 'email' => 'alice@example.com']);
        $customerZ = Customer::factory()->create(['name' => 'Zachary Zimmerman', 'email' => 'zachary@example.com']);
        $address = ['first_name' => 'Test', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'];

        $order1 = Order::createTrusted([
            'number' => 'ORD-001',
            'customer_id' => $customerA->id,
            'email' => $customerA->email,
            'status' => 'pending',
            'currency_code' => 'USD',
            'currency_rate' => 1,
            'subtotal' => 50,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'grand_total' => 50,
            'payment_status' => 'pending',
            'billing_address' => $address,
            'shipping_address' => $address,
            'created_at' => now()->subDays(2),
        ]);

        $order2 = Order::createTrusted([
            'number' => 'ORD-002',
            'customer_id' => $customerZ->id,
            'email' => $customerZ->email,
            'status' => 'shipped',
            'currency_code' => 'USD',
            'currency_rate' => 1,
            'subtotal' => 100,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'grand_total' => 100,
            'payment_status' => 'paid',
            'billing_address' => $address,
            'shipping_address' => $address,
            'created_at' => now(),
        ]);

        // Test sorting by customer name asc
        $this->get('/admin/orders?sort=customer&dir=asc')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Index', false)
                ->where('filters.sort', 'customer')
                ->where('filters.dir', 'asc')
            );

        // Test date filter
        $this->get('/admin/orders?date='.now()->toDateString())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Index', false)
                ->has('orders.data')
            );

        // Test export with IDs
        $response = $this->get('/admin/orders/export?ids='.$order1->id);
        $response->assertOk();
        $this->assertStringContainsString('ORD-001', $response->streamedContent());
        $this->assertStringNotContainsString('ORD-002', $response->streamedContent());
    }
}
