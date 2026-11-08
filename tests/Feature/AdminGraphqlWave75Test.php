<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\EuWithdrawalSchema;
use App\Domain\Order\Models\EuWithdrawalRequest;
use App\Domain\Order\Models\Order;
use App\Domain\Store\StoreContext;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave75Test extends TestCase
{
    use RefreshDatabase;

    public function test_eu_withdrawal_and_bookings_rest_aliases(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $storeId = app(StoreContext::class)->requireId();
        $customer = Customer::factory()->create(['store_id' => $storeId]);
        $product = Product::query()
            ->where('is_enabled', true)
            ->whereNotIn('type', ['virtual', 'gift_card', 'service', 'downloadable'])
            ->firstOrFail();
        if ($product->type !== 'simple') {
            $product->forceFill(['type' => 'simple'])->save();
        }

        $order = Order::createTrusted([
            'number' => 'W75-EU-1',
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'delivered',
            'currency_code' => 'EUR',
            'payment_status' => 'paid',
            'billing_address' => ['country_code' => 'DE'],
            'shipping_address' => ['country_code' => 'DE'],
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'sku' => $product->sku,
            'name' => 'EU item',
            'qty' => 1,
            'unit_price' => 40,
            'total' => 40,
        ]);

        $bookingOrder = Order::createTrusted([
            'number' => 'W75-BOOK-1',
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'processing',
            'currency_code' => 'USD',
            'payment_status' => 'paid',
            'billing_address' => ['country_code' => 'US', 'first_name' => 'Wave', 'line1' => '1 Main'],
            'shipping_address' => ['country_code' => 'US', 'first_name' => 'Wave', 'line1' => '1 Main'],
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
        $bookingItem = $bookingOrder->items()->create([
            'product_id' => $product->id,
            'sku' => $product->sku,
            'name' => 'Bookable service',
            'qty' => 1,
            'unit_price' => 55,
            'total' => 55,
            'booking_date' => '2026-10-20',
            'booking_end_date' => '2026-10-20',
        ]);

        EuWithdrawalSchema::ensure();
        $withdrawal = EuWithdrawalRequest::query()->create([
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'reference' => EuWithdrawalSchema::newReference(),
            'status' => EuWithdrawalRequest::STATUS_PENDING,
            'reason' => 'Wave75 changed mind',
            'requested_at' => now()->subDay(),
        ]);
        $this->assertSame(EuWithdrawalRequest::STATUS_PENDING, $withdrawal->status);

        $list = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query {
                adminEuWithdrawals(status: "received", limit: 20) {
                    id status orderIncrementId customerEmail reason receivedAt
                }
            }',
        ]);
        $list->assertOk();
        $this->assertArrayNotHasKey('errors', $list->json() ?? [], json_encode($list->json()));
        $rows = $list->json('data.adminEuWithdrawals') ?? [];
        $this->assertNotEmpty($rows);
        $this->assertSame('received', (string) ($rows[0]['status'] ?? ''));
        $wid = (string) ($rows[0]['id'] ?? '');

        $detail = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($id: ID!) { adminEuWithdrawal(id: $id) { id status orderIncrementId } }',
            'variables' => ['id' => $wid],
        ]);
        $detail->assertOk();
        $this->assertSame('W75-EU-1', (string) $detail->json('data.adminEuWithdrawal.orderIncrementId'));

        $resend = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) { resendConfirmationAdminEuWithdrawal(id: $id) { id status } }',
            'variables' => ['id' => $wid],
        ]);
        $resend->assertOk();
        $this->assertArrayNotHasKey('errors', $resend->json() ?? [], json_encode($resend->json()));

        $refund = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                markRefundedAdminEuWithdrawal(id: $id, note: "Wave75 refunded") {
                    id status refundNote refundedAt
                }
            }',
            'variables' => ['id' => $wid],
        ]);
        $refund->assertOk();
        $this->assertArrayNotHasKey('errors', $refund->json() ?? [], json_encode($refund->json()));
        $this->assertSame('refunded', (string) $refund->json('data.markRefundedAdminEuWithdrawal.status'));

        $declinedOrder = Order::createTrusted([
            'number' => 'W75-EU-2',
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'delivered',
            'currency_code' => 'EUR',
            'payment_status' => 'paid',
            'billing_address' => ['country_code' => 'FR'],
            'shipping_address' => ['country_code' => 'FR'],
            'created_at' => now()->subDays(1),
            'updated_at' => now()->subDays(1),
        ]);
        $declinedOrder->items()->create([
            'product_id' => $product->id,
            'sku' => $product->sku,
            'name' => 'EU item 2',
            'qty' => 1,
            'unit_price' => 22,
            'total' => 22,
        ]);
        $declined = EuWithdrawalRequest::query()->create([
            'customer_id' => $customer->id,
            'order_id' => $declinedOrder->id,
            'reference' => EuWithdrawalSchema::newReference(),
            'status' => EuWithdrawalRequest::STATUS_PENDING,
            'reason' => 'Wave75 decline path',
            'requested_at' => now(),
        ]);
        $decline = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                declineAdminEuWithdrawal(id: $id, reason: "Exempt purchase") { id status declinedReason }
            }',
            'variables' => ['id' => (string) $declined->id],
        ]);
        $decline->assertOk();
        $this->assertArrayNotHasKey('errors', $decline->json() ?? [], json_encode($decline->json()));
        $this->assertSame('declined', (string) $decline->json('data.declineAdminEuWithdrawal.status'));

        $bookings = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query {
                adminBookings(limit: 20) { id bookingType from fromFormatted order orderItem }
            }',
        ]);
        $bookings->assertOk();
        $this->assertArrayNotHasKey('errors', $bookings->json() ?? [], json_encode($bookings->json()));
        $this->assertNotEmpty($bookings->json('data.adminBookings'));

        $one = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($id: ID!) { adminBooking(id: $id) { id productId bookingType from } }',
            'variables' => ['id' => (string) $bookingItem->id],
        ]);
        $one->assertOk();
        $this->assertArrayNotHasKey('errors', $one->json() ?? [], json_encode($one->json()));
        $this->assertSame((string) $product->id, (string) $one->json('data.adminBooking.productId'));
        $this->assertNotNull($one->json('data.adminBooking.from'));
    }
}
