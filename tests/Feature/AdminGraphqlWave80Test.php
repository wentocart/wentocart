<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Store\StoreContext;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AdminGraphqlWave80Test extends TestCase
{
    use RefreshDatabase;

    public function test_orders_customers_bookings_csv_and_booking_filters(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $storeId = app(StoreContext::class)->requireId();
        $customer = Customer::factory()->create([
            'store_id' => $storeId,
            'email' => 'wave80-buyer@example.com',
            'name' => 'Wave Eighty',
            'first_name' => 'Wave',
            'last_name' => 'Eighty',
        ]);
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();

        $order = Order::createTrusted([
            'number' => 'W80-ORD-1',
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'processing',
            'currency_code' => 'USD',
            'payment_status' => 'paid',
            'grand_total' => 120,
            'billing_address' => ['country_code' => 'US', 'first_name' => 'Wave', 'line1' => '1 Main'],
            'shipping_address' => ['country_code' => 'US', 'first_name' => 'Wave', 'line1' => '1 Main'],
        ]);
        $bookingItem = $order->items()->create([
            'product_id' => $product->id,
            'sku' => $product->sku,
            'name' => 'Wave80 booking',
            'qty' => 1,
            'unit_price' => 120,
            'total' => 120,
            'booking_date' => '2026-10-22',
            'booking_end_date' => '2026-10-22',
        ]);

        $ordersCsv = $this->actingAs($admin, 'admin')->get('/api/v1/console/orders/export?status=processing');
        $ordersCsv->assertOk();
        $this->assertStringContainsString('text/csv', (string) $ordersCsv->headers->get('content-type'));
        $this->assertStringContainsString('W80-ORD-1', $ordersCsv->streamedContent());
        $this->assertStringContainsString('wave80-buyer@example.com', $ordersCsv->streamedContent());

        $customersCsv = $this->actingAs($admin, 'admin')->get('/api/v1/console/customers/export?email=wave80-buyer');
        $customersCsv->assertOk();
        $this->assertStringContainsString('wave80-buyer@example.com', $customersCsv->streamedContent());
        $this->assertStringContainsString('Wave Eighty', $customersCsv->streamedContent());

        $bookingsCsv = $this->actingAs($admin, 'admin')->get('/api/v1/console/bookings/export?from_from=2026-10-01&from_to=2026-10-31');
        $bookingsCsv->assertOk();
        $body = $bookingsCsv->streamedContent();
        $this->assertStringContainsString('W80-ORD-1', $body);
        $this->assertStringContainsString('2026-10-22', $body);

        $gql = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query {
                adminBookings(from_from: "2026-10-20", from_to: "2026-10-25", limit: 20) {
                    id orderId productId bookingType fromFormatted
                }
            }',
        ]);
        $gql->assertOk();
        $this->assertArrayNotHasKey('errors', $gql->json() ?? [], json_encode($gql->json()));
        $rows = $gql->json('data.adminBookings') ?? [];
        $this->assertNotEmpty($rows);
        $ids = collect($rows)->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->assertContains((string) $bookingItem->id, $ids);

        $miss = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query {
                adminBookings(from_from: "2027-01-01", from_to: "2027-01-31", limit: 20) {
                    id
                }
            }',
        ]);
        $miss->assertOk();
        $this->assertSame([], $miss->json('data.adminBookings') ?? null);

        Auth::guard('admin')->logout();
        $this->app['auth']->forgetGuards();
        $this->get('/api/v1/console/orders/export')->assertUnauthorized();
        $this->get('/api/v1/console/customers/export')->assertUnauthorized();
        $this->get('/api/v1/console/bookings/export')->assertUnauthorized();
    }

    public function test_shop_my_bookings_and_cart_add_booking_date(): void
    {
        $this->seed();
        $storeId = app(StoreContext::class)->requireId();
        $customer = Customer::factory()->create(['store_id' => $storeId]);
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();

        $order = Order::createTrusted([
            'number' => 'W80-SHOP-1',
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'processing',
            'currency_code' => 'USD',
            'payment_status' => 'paid',
            'grand_total' => 50,
            'billing_address' => ['country_code' => 'US', 'first_name' => 'Shop', 'line1' => '1 Main'],
            'shipping_address' => ['country_code' => 'US', 'first_name' => 'Shop', 'line1' => '1 Main'],
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'sku' => $product->sku,
            'name' => 'Shop booking line',
            'qty' => 1,
            'unit_price' => 50,
            'total' => 50,
            'booking_date' => '2026-10-25',
            'booking_end_date' => '2026-10-25',
        ]);

        Auth::guard('admin')->logout();
        $this->app['auth']->forgetGuards();

        $list = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'query {
                myBookings(limit: 10) {
                    id order_number booking_date name sku
                }
            }',
        ]);
        $list->assertOk();
        $this->assertArrayNotHasKey('errors', $list->json() ?? [], json_encode($list->json()));
        $bookings = $list->json('data.myBookings') ?? [];
        $this->assertNotEmpty($bookings);
        $this->assertSame('W80-SHOP-1', $bookings[0]['order_number'] ?? null);
        $this->assertSame('2026-10-25', $bookings[0]['booking_date'] ?? null);

        // Simple product cartAdd without booking should still work.
        $add = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'mutation($pid: ID!) {
                cartAdd(productId: $pid, qty: 1) {
                    item_count
                    itemsList { id product_id booking_date }
                }
            }',
            'variables' => ['pid' => (string) $product->id],
        ]);
        $add->assertOk();
        $this->assertArrayNotHasKey('errors', $add->json() ?? [], json_encode($add->json()));
        $this->assertGreaterThanOrEqual(1, (int) ($add->json('data.cartAdd.item_count') ?? 0));
    }
}
