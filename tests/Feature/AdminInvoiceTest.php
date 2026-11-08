<?php

namespace Tests\Feature;

use App\Domain\Order\Models\Order;
use App\Domain\Settings\DocumentCommerceSettings;
use App\Mail\ShopHtmlMail;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
    }

    public function test_invoices_index_lists_only_paid_orders(): void
    {
        $paid = $this->makeOrder('paid', 'INV-PAID-1', 'processing');
        $this->makeOrder('paid', 'INV-PEND-1', 'pending');

        $this->get('/admin/invoices')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Invoices/Index', false)
                ->has('invoices.data', 1)
                ->where('invoices.data.0.number', $paid->number)
            );
    }

    public function test_paid_order_invoice_can_be_viewed_and_downloaded(): void
    {
        $order = $this->makeOrder('paid', 'INV-VIEW-1', 'processing');
        $invoiceNo = DocumentCommerceSettings::formatInvoiceNumber($order);

        $this->get('/admin/orders/'.$order->id.'/invoice')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Invoices/Show', false)
                ->where('invoice.number', $invoiceNo)
                ->where('invoice.payment_status', 'paid')
            );

        $this->get('/admin/orders/'.$order->id.'/invoice/download')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition', 'attachment; filename="invoice-INV-VIEW-1.pdf"');
    }

    public function test_unpaid_order_has_no_invoice(): void
    {
        $order = $this->makeOrder('pending', 'INV-UNPAID-1');

        $this->get('/admin/orders/'.$order->id.'/invoice')->assertNotFound();
        $this->get('/admin/orders/'.$order->id.'/invoice/download')->assertNotFound();
        $this->post('/admin/orders/'.$order->id.'/invoice/email')->assertNotFound();
    }

    public function test_invoice_can_be_emailed_to_the_customer(): void
    {
        Mail::fake();
        $order = $this->makeOrder('paid', 'INV-MAIL-1', 'processing');

        $this->from('/admin/orders/'.$order->id.'/invoice')
            ->post('/admin/orders/'.$order->id.'/invoice/email')
            ->assertRedirect();

        Mail::assertSent(ShopHtmlMail::class, fn (ShopHtmlMail $mail) => $mail->hasTo('buyer.invoice@example.com')
            && str_contains($mail->emailSubject, 'INV-MAIL-1'));
    }

    protected function makeOrder(string $paymentStatus, string $number, string $status = 'pending'): Order
    {
        $order = Order::createTrusted([
            'number' => $number,
            'email' => 'buyer.invoice@example.com',
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

        return $order;
    }
}
