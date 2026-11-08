<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductServiceSetting;
use App\Domain\Order\Models\Order;
use App\Models\Admin;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AdminGraphqlWave81Test extends TestCase
{
    use RefreshDatabase;

    public function test_transactions_csv_and_admin_transaction_filters(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $order = Order::createTrusted([
            'number' => 'W81-TX-1',
            'email' => 'wave81-tx@example.com',
            'status' => 'processing',
            'currency_code' => 'USD',
            'payment_status' => 'paid',
            'grand_total' => 88,
            'billing_address' => ['country_code' => 'US', 'first_name' => 'Wave', 'line1' => '1 Main'],
            'shipping_address' => ['country_code' => 'US', 'first_name' => 'Wave', 'line1' => '1 Main'],
            'payment_details' => [
                'transactions' => [[
                    'id' => 'w81-tx-row',
                    'transaction_id' => 'GW-W81-999',
                    'amount' => 88,
                    'status' => 'paid',
                    'payment_method' => 'checkmo',
                    'created_at' => '2026-10-18T10:00:00+00:00',
                ]],
            ],
            'fulfillment_details' => [
                'invoice_number' => 'INV-W81-1',
            ],
        ]);

        $csv = $this->actingAs($admin, 'admin')->get('/api/v1/console/transactions/export?status=paid');
        $csv->assertOk();
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('content-type'));
        $body = $csv->streamedContent();
        $this->assertStringContainsString('GW-W81-999', $body);
        $this->assertStringContainsString('W81-TX-1', $body);

        $gql = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query {
                adminTransactions(transactionId: "GW-W81-999", created_at_from: "2026-10-01", limit: 10) {
                    transactionId order_number status amount
                }
            }',
        ]);
        $gql->assertOk();
        $this->assertArrayNotHasKey('errors', $gql->json() ?? [], json_encode($gql->json()));
        $rows = $gql->json('data.adminTransactions') ?? [];
        $this->assertNotEmpty($rows);
        $this->assertSame('GW-W81-999', $rows[0]['transactionId'] ?? null);
        $this->assertSame('W81-TX-1', $rows[0]['order_number'] ?? null);

        Auth::guard('admin')->logout();
        $this->app['auth']->forgetGuards();
        $this->get('/api/v1/console/transactions/export')->assertUnauthorized();
        $this->assertNotNull($order->id);
    }

    public function test_shop_booking_slots_for_appointment_service(): void
    {
        $this->seed();
        Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:00')); // Monday

        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $product->forceFill([
            'type' => 'service',
            'manage_stock' => false,
            'is_enabled' => true,
        ])->save();

        ProductServiceSetting::query()->updateOrCreate(
            ['product_id' => $product->id],
            [
                'service_type' => 'appointment',
                'duration' => 60,
                'duration_unit' => 'minutes',
                'pricing_model' => 'fixed',
                'delivery_method' => 'online',
                'availability_config' => [
                    'available_days' => ['mon', 'wed', 'fri'],
                    'start_time' => '09:00',
                    'end_time' => '12:00',
                    'min_advance_hours' => 0,
                    'max_advance_days' => 30,
                    'max_bookings' => 5,
                ],
            ]
        );

        $ok = $this->postJson('/api/v1/graphql', [
            'query' => 'query ($id: Int!, $date: String!) {
                bookingSlots(id: $id, date: $date) {
                    slotId from to timestamp qty
                }
            }',
            'variables' => [
                'id' => (int) $product->id,
                'date' => '2026-10-07', // Wednesday
            ],
        ]);
        $ok->assertOk();
        $this->assertArrayNotHasKey('errors', $ok->json() ?? [], json_encode($ok->json()));
        $slots = $ok->json('data.bookingSlots') ?? [];
        $this->assertNotEmpty($slots);
        $this->assertNotEmpty($slots[0]['slotId'] ?? null);
        $this->assertNotEmpty($slots[0]['from'] ?? null);

        $closed = $this->postJson('/api/v1/graphql', [
            'query' => 'query ($id: Int!, $date: String!) {
                bookingSlots(id: $id, date: $date) { slotId }
            }',
            'variables' => [
                'id' => (int) $product->id,
                'date' => '2026-10-06', // Tuesday — not available
            ],
        ]);
        $closed->assertOk();
        $this->assertSame([], $closed->json('data.bookingSlots') ?? null);

        Carbon::setTestNow();
    }
}
