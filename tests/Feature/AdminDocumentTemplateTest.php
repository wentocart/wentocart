<?php

namespace Tests\Feature;

use App\Domain\Order\Models\Order;
use App\Domain\Settings\DocumentTemplateCatalog;
use App\Domain\Settings\Models\DocumentTemplate;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminDocumentTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
        DocumentTemplateCatalog::seed();
    }

    public function test_document_designs_index_lists_system_templates(): void
    {
        $this->get('/admin/documents')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Documents', false)
                ->has('templates.data', 3));

        $this->get('/admin/documents?sort=type&dir=desc&type=invoice&id_min=1')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.sort', 'type')
                ->where('filters.type', 'invoice')
                ->has('templates.data', 1));
    }

    public function test_can_update_invoice_template_and_render_html(): void
    {
        $template = DocumentTemplate::query()->where('code', 'invoice')->firstOrFail();

        $this->put('/admin/documents/'.$template->id, [
            'name' => 'Invoice',
            'email_subject' => 'Your invoice {{order_number}}',
            'body' => '<html><body><h1>INV {{order_number}}</h1><p>{{shop_name}}</p></body></html>',
            'design_json' => null,
            'is_enabled' => true,
        ])->assertRedirect();

        $order = Order::query()->where('payment_status', 'paid')->first()
            ?? Order::query()->first();

        if (! $order) {
            $this->markTestSkipped('No order seeded.');
        }

        $order->update(['payment_status' => 'paid', 'status' => 'processing']);

        $html = app(\App\Domain\Order\InvoiceDocument::class)->html($order);
        $this->assertStringContainsString('INV '.$order->number, $html);

        $this->get('/admin/invoices/'.$order->id.'/pdf')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_can_create_custom_document_template(): void
    {
        $this->post('/admin/documents', [
            'name' => 'Packing list',
            'type' => 'custom',
            'email_subject' => 'Packing list {{order_number}}',
            'body' => '<p>Pack {{order_number}}</p>',
            'is_enabled' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('document_templates', [
            'name' => 'Packing list',
            'type' => 'custom',
            'is_custom' => 1,
        ]);
    }

    public function test_list_toggle_and_bulk_skip_built_in_deletes(): void
    {
        $invoice = DocumentTemplate::query()->where('code', 'invoice')->firstOrFail();
        $this->assertTrue((bool) $invoice->is_in_use);

        $this->post('/admin/documents/'.$invoice->id.'/toggle', ['is_enabled' => 0])
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertTrue((bool) $invoice->fresh()->is_enabled);

        $this->post('/admin/documents', [
            'name' => 'Packing list',
            'type' => 'custom',
            'email_subject' => 'Packing list {{order_number}}',
            'body' => '<p>Pack {{order_number}}</p>',
            'is_enabled' => true,
        ])->assertRedirect();

        $custom = DocumentTemplate::query()->where('name', 'Packing list')->firstOrFail();

        $this->post('/admin/documents/bulk-duplicate', ['ids' => [$invoice->id, $custom->id]])
            ->assertRedirect();
        $this->assertSame(2, DocumentTemplate::query()->where('name', 'like', '% copy')->count());

        $this->post('/admin/documents/bulk-delete', ['ids' => [$invoice->id, $custom->id]])
            ->assertRedirect();
        $this->assertNotNull(DocumentTemplate::query()->find($invoice->id));
        $this->assertNull(DocumentTemplate::query()->find($custom->id));
    }

    public function test_set_in_use_switches_which_invoice_pdf_is_attached(): void
    {
        $invoice = DocumentTemplate::query()->where('code', 'invoice')->firstOrFail();

        $this->post('/admin/documents', [
            'name' => 'Wholesale invoice',
            'type' => 'invoice',
            'email_subject' => 'Wholesale {{order_number}}',
            'body' => '<p>WHOLESALE {{order_number}}</p>',
            'is_enabled' => true,
        ])->assertRedirect();

        $custom = DocumentTemplate::query()->where('name', 'Wholesale invoice')->firstOrFail();
        $this->assertFalse((bool) $custom->is_in_use);
        $this->assertTrue((bool) $invoice->fresh()->is_in_use);

        $this->post('/admin/documents/'.$custom->id.'/use')
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertTrue((bool) $custom->fresh()->is_in_use);
        $this->assertTrue((bool) $custom->fresh()->is_enabled);
        $this->assertFalse((bool) $invoice->fresh()->is_in_use);

        $order = Order::query()->first();
        if (! $order) {
            $this->markTestSkipped('No order seeded.');
        }

        $html = app(\App\Domain\Order\InvoiceDocument::class)->html($order);
        $this->assertStringContainsString('WHOLESALE '.$order->number, $html);

        $this->post('/admin/documents/'.$custom->id.'/toggle', ['is_enabled' => 0])
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertTrue((bool) $custom->fresh()->is_enabled);
    }
}
