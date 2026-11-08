<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Notify\Models\AdminNotification;
use App\Domain\Order\Models\Order;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        AdminNotification::query()->delete();
    }

    public function test_storefront_order_and_customer_land_in_the_inbox(): void
    {
        $customer = Customer::createTrusted([
            'email' => 'new.customer@example.test',
            'name' => 'New Customer',
            'first_name' => 'New',
            'last_name' => 'Customer',
            'password' => 'secret-secret',
            'is_enabled' => true,
        ]);

        $order = Order::createTrusted([
            'number' => 'BELL-1001',
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'pending',
            'currency_code' => 'USD',
            'currency_rate' => 1,
            'subtotal' => 40,
            'tax_total' => 0,
            'tax_details' => [],
            'shipping_total' => 0,
            'discount_total' => 0,
            'grand_total' => 40,
            'payment_status' => 'pending',
            'billing_address' => ['first_name' => 'New', 'last_name' => 'Customer', 'line1' => '1 Main', 'city' => 'Austin', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'New', 'last_name' => 'Customer', 'line1' => '1 Main', 'city' => 'Austin', 'country_code' => 'US'],
        ]);

        $this->assertDatabaseHas('admin_notifications', [
            'type' => 'customer.registered',
            'subject_id' => $customer->id,
            'tone' => 'success',
        ]);
        $this->assertDatabaseHas('admin_notifications', [
            'type' => 'order.placed',
            'subject_id' => $order->id,
            'tone' => 'info',
        ]);

        $admin = Admin::query()->firstOrFail();
        $other = Admin::factory()->create();

        $this->actingAs($admin, 'admin')
            ->getJson('/admin/notifications')
            ->assertOk()
            ->assertJsonPath('unread', 2)
            ->assertJsonFragment(['title' => 'New order BELL-1001']);

        $orderNote = AdminNotification::query()->where('type', 'order.placed')->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->postJson('/admin/notifications/'.$orderNote->id.'/read')
            ->assertOk()
            ->assertJsonPath('unread', 1);

        $this->actingAs($other, 'admin')
            ->getJson('/admin/notifications?unread=1')
            ->assertOk()
            ->assertJsonPath('unread', 2);

        $this->actingAs($admin, 'admin')
            ->postJson('/admin/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('unread', 0);

        $this->actingAs($admin, 'admin')
            ->getJson('/admin/notifications?unread=1')
            ->assertOk()
            ->assertJsonPath('items', []);
    }

    public function test_back_office_order_does_not_ring_the_bell(): void
    {
        $admin = Admin::query()->firstOrFail();
        $this->actingAs($admin, 'admin')->get('/admin');

        Order::createTrusted([
            'number' => 'BELL-OFFICE',
            'email' => 'office@example.test',
            'status' => 'pending',
            'currency_code' => 'USD',
            'currency_rate' => 1,
            'subtotal' => 10,
            'tax_total' => 0,
            'tax_details' => [],
            'shipping_total' => 0,
            'discount_total' => 0,
            'grand_total' => 10,
            'payment_status' => 'paid',
            'billing_address' => ['first_name' => 'Office', 'last_name' => 'Sale', 'line1' => '1 Main', 'city' => 'Austin', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Office', 'last_name' => 'Sale', 'line1' => '1 Main', 'city' => 'Austin', 'country_code' => 'US'],
        ]);

        $this->assertDatabaseMissing('admin_notifications', ['type' => 'order.placed']);
    }

    public function test_later_payment_is_its_own_notification(): void
    {
        $order = Order::createTrusted([
            'number' => 'BELL-PAY',
            'email' => 'pay@example.test',
            'status' => 'pending',
            'currency_code' => 'USD',
            'currency_rate' => 1,
            'subtotal' => 15,
            'tax_total' => 0,
            'tax_details' => [],
            'shipping_total' => 0,
            'discount_total' => 0,
            'grand_total' => 15,
            'payment_status' => 'pending',
            'billing_address' => ['first_name' => 'Pat', 'last_name' => 'Buyer', 'line1' => '1 Main', 'city' => 'Austin', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Pat', 'last_name' => 'Buyer', 'line1' => '1 Main', 'city' => 'Austin', 'country_code' => 'US'],
        ]);

        $order->updateTrusted(['payment_status' => 'paid']);

        $this->assertDatabaseHas('admin_notifications', [
            'type' => 'payment.received',
            'subject_id' => $order->id,
            'tone' => 'success',
        ]);
    }
}
