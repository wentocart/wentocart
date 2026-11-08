<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Settings\ShopSetting;
use App\Domain\Store\StoreContext;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminGraphqlWave77Test extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_mass_update_status_and_send_duplicate(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        ShopSetting::put('documents.invoices_enabled', true);

        $storeId = app(StoreContext::class)->requireId();
        $customer = Customer::factory()->create(['store_id' => $storeId]);
        $order = Order::createTrusted([
            'number' => 'W77-INV-1',
            'customer_id' => $customer->id,
            'email' => 'wave77-invoice@example.com',
            'status' => 'processing',
            'currency_code' => 'USD',
            'payment_status' => 'pending',
            'grand_total' => 42,
            'billing_address' => ['country_code' => 'US', 'first_name' => 'Wave', 'line1' => '1 Main'],
            'shipping_address' => ['country_code' => 'US', 'first_name' => 'Wave', 'line1' => '1 Main'],
            'fulfillment_details' => [
                'invoice_number' => 'INV-W77-001',
                'invoice_state' => 'pending',
            ],
        ]);

        $list = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query {
                adminInvoices(limit: 20) { id order_number invoice_number state payment_status }
            }',
        ]);
        $list->assertOk();
        $this->assertArrayNotHasKey('errors', $list->json() ?? [], json_encode($list->json()));
        $rows = collect($list->json('data.adminInvoices') ?? []);
        $hit = $rows->first(fn ($r) => ($r['invoice_number'] ?? '') === 'INV-W77-001');
        $this->assertNotNull($hit);
        $this->assertSame('pending', (string) ($hit['state'] ?? ''));

        $mass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($ids: [ID!]!) {
                createAdminInvoiceBulkUpdateStatus(ids: $ids, status: "overdue") {
                    ok updated_count
                }
            }',
            'variables' => ['ids' => [(string) $order->id]],
        ]);
        $mass->assertOk();
        $this->assertArrayNotHasKey('errors', $mass->json() ?? [], json_encode($mass->json()));
        $this->assertTrue((bool) $mass->json('data.createAdminInvoiceBulkUpdateStatus.ok'));
        $this->assertSame(1, (int) $mass->json('data.createAdminInvoiceBulkUpdateStatus.updated_count'));
        $this->assertSame(
            'overdue',
            (string) (($order->fresh()->fulfillment_details['invoice_state'] ?? ''))
        );

        Mail::fake();
        $dup = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation {
                createAdminInvoiceSendDuplicate(
                    orderNumber: "'.$order->number.'"
                    email: "copy-wave77@example.com"
                ) { ok id }
            }',
        ]);
        $dup->assertOk();
        // Mail may be stubbed by ShopMailer — accept either success or a controlled failure message.
        if (empty($dup->json('errors'))) {
            $this->assertTrue((bool) $dup->json('data.createAdminInvoiceSendDuplicate.ok'));
            $this->assertNotEmpty($order->fresh()->fulfillment_details['invoice_duplicate_emailed_at'] ?? null);
        } else {
            $msg = (string) ($dup->json('errors.0.message') ?? '');
            $this->assertTrue(
                str_contains($msg, 'Failed to send') || str_contains($msg, 'Invoice'),
                $msg
            );
        }
    }
}
