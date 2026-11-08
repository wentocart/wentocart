<?php

namespace Tests\Feature;

use App\Domain\Order\Models\Order;
use App\Mail\ShopHtmlMail;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminCreditSlipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
    }

    public function test_credit_slips_index_lists_only_refunded_orders(): void
    {
        $refunded = $this->makeOrder('refunded', 'CR-REF-1');
        $this->makeOrder('paid', 'CR-PAID-1');

        $this->get('/admin/credit-slips')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('CreditSlips/Index', false)
                ->has('creditSlips.data', 1)
                ->where('creditSlips.data.0.number', $refunded->number)
            );
    }

    public function test_refunded_order_credit_slip_can_be_viewed_and_downloaded(): void
    {
        $order = $this->makeOrder('refunded', 'CR-VIEW-1');

        $this->get('/admin/orders/'.$order->id.'/credit-slip')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('CreditSlips/Show', false)
                ->where('creditSlip.number', 'CR-VIEW-1')
            );

        $this->get('/admin/orders/'.$order->id.'/credit-slip/download')
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename="credit-slip-CR-VIEW-1.html"')
            ->assertSee('CREDIT SLIP #CR-VIEW-1', false)
            ->assertSee('Wireless Headphones', false);
    }

    public function test_non_refunded_order_has_no_credit_slip(): void
    {
        $order = $this->makeOrder('paid', 'CR-NONE-1');

        $this->get('/admin/orders/'.$order->id.'/credit-slip')->assertNotFound();
        $this->get('/admin/orders/'.$order->id.'/credit-slip/download')->assertNotFound();
        $this->post('/admin/orders/'.$order->id.'/credit-slip/email')->assertNotFound();
    }

    public function test_credit_slip_can_be_emailed_to_the_customer(): void
    {
        Mail::fake();
        $order = $this->makeOrder('refunded', 'CR-MAIL-1');

        $this->from('/admin/orders/'.$order->id.'/credit-slip')
            ->post('/admin/orders/'.$order->id.'/credit-slip/email')
            ->assertRedirect();

        Mail::assertSent(ShopHtmlMail::class, fn (ShopHtmlMail $mail) => $mail->hasTo('buyer.credit@example.com')
            && str_contains($mail->emailSubject, 'CR-MAIL-1'));
    }

    protected function makeOrder(string $paymentStatus, string $number): Order
    {
        $status = $paymentStatus === 'refunded' ? 'refunded' : 'pending';

        $order = Order::createTrusted([
            'number' => $number,
            'email' => 'buyer.credit@example.com',
            'status' => $status,
            'currency_code' => 'USD',
            'currency_rate' => 1,
            'subtotal' => 10,
            'tax_total' => 0,
            'tax_details' => [],
            'shipping_total' => 0,
            'discount_total' => 0,
            'grand_total' => 10,
            'payment_status' => $paymentStatus,
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

        if ($paymentStatus === 'refunded') {
            $order->statusHistories()->create([
                'status' => 'refunded',
                'comment' => 'Payment refunded by admin',
            ]);
        }

        return $order;
    }
}
