<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\EuWithdrawalRequest;
use App\Domain\Order\Models\Order;
use App\Domain\Store\StoreContext;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EuWithdrawalTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_request_eu_withdrawal_within_window(): void
    {
        $this->seed();
        $storeId = app(StoreContext::class)->requireId();
        $customer = Customer::factory()->create(['store_id' => $storeId]);
        $product = \App\Domain\Catalog\Models\Product::query()->where('is_enabled', true)->where('type', 'simple')->firstOrFail();
        $order = Order::createTrusted([
            'number' => 'EU-W-1',
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'delivered',
            'currency_code' => 'EUR',
            'payment_status' => 'paid',
            'billing_address' => ['country_code' => 'DE'],
            'shipping_address' => ['country_code' => 'DE'],
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(3),
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'sku' => $product->sku,
            'name' => 'EU item',
            'qty' => 1,
            'unit_price' => 20,
            'total' => 20,
        ]);

        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/account/orders/'.$order->number.'/withdrawal', ['reason' => 'Changed my mind'])
            ->assertCreated()
            ->assertJsonPath('withdrawal.status', 'pending');

        $this->assertDatabaseHas('eu_withdrawal_requests', [
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'status' => 'pending',
        ]);

        $row = EuWithdrawalRequest::query()->firstOrFail();
        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/eu-withdrawals/'.$row->id.'/approve', ['admin_note' => 'OK'])
            ->assertRedirect();
        $this->assertSame('approved', $row->fresh()->status);
    }

    public function test_withdrawal_is_blocked_after_fourteen_days(): void
    {
        $this->seed();
        $storeId = app(StoreContext::class)->requireId();
        $customer = Customer::factory()->create(['store_id' => $storeId]);
        $order = Order::createTrusted([
            'number' => 'EU-W-OLD',
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'delivered',
            'currency_code' => 'EUR',
            'payment_status' => 'paid',
            'billing_address' => ['country_code' => 'FR'],
            'shipping_address' => ['country_code' => 'FR'],
            'created_at' => now()->subDays(40),
            'updated_at' => now()->subDays(40),
        ]);

        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/account/orders/'.$order->number.'/withdrawal', ['reason' => 'Too late'])
            ->assertStatus(422);
    }
}
