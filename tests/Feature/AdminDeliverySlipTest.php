<?php

namespace Tests\Feature;

use App\Domain\Order\Models\Order;
use App\Mail\ShopHtmlMail;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminDeliverySlipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
    }

    public function test_delivery_slips_index_lists_shipped_and_completed_orders(): void
    {
        $shipped = $this->makeOrder('shipped', 'DL-SHIP-1');
        $this->makeOrder('pending', 'DL-PEND-1');

        $this->get('/admin/delivery-slips')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('DeliverySlips/Index', false)
                ->has('deliverySlips.data', 1)
                ->where('deliverySlips.data.0.number', $shipped->number)
            );
    }

    public function test_shipped_order_delivery_slip_can_be_viewed_and_downloaded(): void
    {
        $order = $this->makeOrder('shipped', 'DL-VIEW-1');

        $this->get('/admin/orders/'.$order->id.'/delivery-slip')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('DeliverySlips/Show', false)
                ->where('deliverySlip.number', 'DL-VIEW-1')
            );

        $this->get('/admin/orders/'.$order->id.'/delivery-slip/download')
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename="delivery-slip-DL-VIEW-1.html"')
            ->assertSee('DELIVERY SLIP #DL-VIEW-1', false)
            ->assertSee('Wireless Headphones', false);
    }

    public function test_non_shipped_order_has_no_delivery_slip(): void
    {
        $order = $this->makeOrder('pending', 'DL-NONE-1');

        $this->get('/admin/orders/'.$order->id.'/delivery-slip')->assertNotFound();
        $this->get('/admin/orders/'.$order->id.'/delivery-slip/download')->assertNotFound();
        $this->post('/admin/orders/'.$order->id.'/delivery-slip/email')->assertNotFound();
    }

    public function test_delivery_slip_can_be_emailed_to_the_customer(): void
    {
        Mail::fake();
        $order = $this->makeOrder('completed', 'DL-MAIL-1');

        $this->from('/admin/orders/'.$order->id.'/delivery-slip')
            ->post('/admin/orders/'.$order->id.'/delivery-slip/email')
            ->assertRedirect();

        Mail::assertSent(ShopHtmlMail::class, fn (ShopHtmlMail $mail) => $mail->hasTo('buyer.delivery@example.com')
            && str_contains($mail->emailSubject, 'DL-MAIL-1'));
    }

    protected function makeOrder(string $status, string $number): Order
    {
        $order = Order::createTrusted([
            'number' => $number,
            'email' => 'buyer.delivery@example.com',
            'status' => $status,
            'currency_code' => 'USD',
            'currency_rate' => 1,
            'subtotal' => 10,
            'tax_total' => 0,
            'tax_details' => [],
            'shipping_total' => 0,
            'discount_total' => 0,
            'grand_total' => 10,
            'payment_status' => 'paid',
            'billing_address' => ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ]);

        $order->items()->create([
            'sku' => 'WH-100',
            'name' => 'Wireless Headphones',
            'qty' => 1,
            'unit_price' => 10,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'total' => 10,
        ]);

        return $order;
    }
}
