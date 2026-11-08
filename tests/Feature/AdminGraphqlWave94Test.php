<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\SupportTicket;
use App\Domain\Marketing\Models\MarketingEvent;
use App\Domain\Order\Models\Order;
use App\Domain\Settings\Models\EmailTemplate;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AdminGraphqlWave94Test extends TestCase
{
    use RefreshDatabase;

    public function test_contact_us_reorder_and_marketing_mass_delete(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $contact = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation {
                createContactUs(
                    name: "Wave94 Guest"
                    email: "wave94-contact@example.com"
                    message: "Need help with shipping"
                    contact: "555-0194"
                ) { ok success message }
            }',
        ]);
        $this->assertSame(200, $contact->status(), 'contact: '.json_encode($contact->json()));
        $this->assertArrayNotHasKey('errors', $contact->json() ?? [], json_encode($contact->json()));
        $this->assertTrue((bool) $contact->json('data.createContactUs.ok'));
        $this->assertTrue(
            SupportTicket::query()->where('email', 'wave94-contact@example.com')->exists()
        );

        $customer = Customer::factory()->create();
        $product = Product::query()->where('is_enabled', true)->where('type', 'simple')->first()
            ?? Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);
        Product::query()->whereKey($product->id)->update([
            'manage_stock' => true,
            'stock_qty' => max(10, (int) $product->stock_qty),
            'is_enabled' => true,
        ]);

        $order = Order::createTrusted([
            'number' => 'W94-REORDER-1',
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'completed',
            'currency_code' => 'USD',
            'payment_status' => 'paid',
            'grand_total' => 20,
            'billing_address' => ['country_code' => 'US', 'first_name' => 'R', 'line1' => '1 Main'],
            'shipping_address' => ['country_code' => 'US', 'first_name' => 'R', 'line1' => '1 Main'],
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'sku' => $product->sku,
            'name' => 'Wave94 item',
            'qty' => 1,
            'unit_price' => 20,
            'total' => 20,
        ]);

        $reorder = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'mutation ($id: ID!) {
                createReorderOrder(orderId: $id) {
                    success message orderId itemsAddedCount
                }
            }',
            'variables' => ['id' => (string) $order->id],
        ]);
        $this->assertSame(200, $reorder->status(), 'reorder: '.json_encode($reorder->json()));
        $this->assertArrayNotHasKey('errors', $reorder->json() ?? [], json_encode($reorder->json()));
        $this->assertTrue((bool) $reorder->json('data.createReorderOrder.success'));
        $this->assertGreaterThanOrEqual(1, (int) $reorder->json('data.createReorderOrder.itemsAddedCount'));

        Auth::guard('customer')->logout();
        $this->postJson('/api/v1/auth/logout');

        $template = EmailTemplate::query()->create([
            'code' => 'w94-custom-tpl',
            'name' => 'Wave94 Template',
            'subject' => 'Hello',
            'body' => '<p>hi</p>',
            'is_enabled' => true,
            'is_custom' => true,
        ]);
        $event = MarketingEvent::query()->create([
            'name' => 'Wave94 Event',
            'description' => 'Test event',
            'date' => now()->toDateString(),
        ]);

        $tplMass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                bulkDeleteAdminMarketingTemplate(ids: [$id]) {
                    ok updated_count deleted skipped
                }
            }',
            'variables' => ['id' => (string) $template->id],
        ]);
        $this->assertSame(200, $tplMass->status(), 'tplMass: '.json_encode($tplMass->json()));
        $this->assertArrayNotHasKey('errors', $tplMass->json() ?? [], json_encode($tplMass->json()));
        $this->assertSame(1, (int) $tplMass->json('data.bulkDeleteAdminMarketingTemplate.updated_count'));
        $this->assertNull(EmailTemplate::query()->find($template->id));

        $evtMass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                bulkDeleteAdminMarketingEvent(ids: [$id]) {
                    ok updated_count deleted
                }
            }',
            'variables' => ['id' => (string) $event->id],
        ]);
        $this->assertSame(200, $evtMass->status(), 'evtMass: '.json_encode($evtMass->json()));
        $this->assertArrayNotHasKey('errors', $evtMass->json() ?? [], json_encode($evtMass->json()));
        $this->assertSame(1, (int) $evtMass->json('data.bulkDeleteAdminMarketingEvent.updated_count'));
        $this->assertNull(MarketingEvent::query()->find($event->id));
    }
}
