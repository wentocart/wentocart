<?php

namespace Tests\Feature;

use App\Domain\Order\Models\Order;
use App\Domain\Plugin\OrderShowSlots;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrderShowSlotsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
    }

    public function test_order_detail_has_no_core_sample_after_payment_blocks(): void
    {
        $order = Order::createTrusted([
            'number' => 'SLOT-1',
            'email' => 'slot@example.com',
            'status' => 'processing',
            'currency_code' => 'USD',
            'currency_rate' => 1,
            'subtotal' => 10,
            'tax_total' => 0,
            'tax_details' => [],
            'shipping_total' => 0,
            'discount_total' => 0,
            'grand_total' => 10,
            'payment_status' => 'paid',
            'billing_address' => ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'line1' => '1 Main', 'city' => 'Austin', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'line1' => '1 Main', 'city' => 'Austin', 'country_code' => 'US'],
        ]);

        $this->get('/admin/orders/'.$order->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Show', false)
                ->where('moduleSlots', [])
            );

        $this->assertSame([], OrderShowSlots::afterPayment($order->fresh()));
    }
}
