<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Warehouse;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Store\StoreContext;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AdminGraphqlWave82Test extends TestCase
{
    use RefreshDatabase;

    public function test_warehouses_csv_bulk_delete_and_order_bulk_cancel(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $csv = $this->actingAs($admin, 'admin')->get('/api/v1/console/warehouses/export');
        $csv->assertOk();
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('content-type'));
        $body = $csv->streamedContent();
        $this->assertStringContainsString('code', $body);

        $alias = $this->actingAs($admin, 'admin')->get('/api/v1/console/warehouses/export');
        $alias->assertOk();
        $this->assertStringContainsString('code', $alias->streamedContent());

        $code = 'W82-'.strtoupper(substr(uniqid(), -4));
        $created = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($code: String!) {
                createAdminSettingsWarehouse(
                    name: "Wave82 Source"
                    code: $code
                    email: "w82@example.com"
                    address: "2 Test Way"
                    city: "Austin"
                    postcode: "78701"
                    country: "US"
                    state: "TX"
                    enabled: true
                ) { id code }
            }',
            'variables' => ['code' => $code],
        ]);
        $created->assertOk();
        $this->assertArrayNotHasKey('errors', $created->json() ?? [], json_encode($created->json()));
        $newId = (string) $created->json('data.createAdminSettingsWarehouse.id');

        $mass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                bulkDeleteAdminSettingsWarehouses(ids: [$id]) { ok updated_count }
            }',
            'variables' => ['id' => $newId],
        ]);
        $mass->assertOk();
        $this->assertArrayNotHasKey('errors', $mass->json() ?? [], json_encode($mass->json()));
        $this->assertTrue((bool) $mass->json('data.bulkDeleteAdminSettingsWarehouses.ok'));
        $this->assertSame(1, (int) $mass->json('data.bulkDeleteAdminSettingsWarehouses.updated_count'));
        $this->assertNull(Warehouse::query()->find((int) $newId));

        $storeId = app(StoreContext::class)->requireId();
        $customer = Customer::factory()->create(['store_id' => $storeId]);
        $o1 = Order::createTrusted([
            'number' => 'W82-ORD-A',
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'processing',
            'currency_code' => 'USD',
            'payment_status' => 'paid',
            'grand_total' => 40,
            'billing_address' => ['country_code' => 'US', 'first_name' => 'Wave', 'line1' => '1 Main'],
            'shipping_address' => ['country_code' => 'US', 'first_name' => 'Wave', 'line1' => '1 Main'],
        ]);
        $o2 = Order::createTrusted([
            'number' => 'W82-ORD-B',
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'processing',
            'currency_code' => 'USD',
            'payment_status' => 'paid',
            'grand_total' => 50,
            'billing_address' => ['country_code' => 'US', 'first_name' => 'Wave', 'line1' => '1 Main'],
            'shipping_address' => ['country_code' => 'US', 'first_name' => 'Wave', 'line1' => '1 Main'],
        ]);

        $cancel = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation {
                bulkCancelAdminOrders(numbers: ["W82-ORD-A", "W82-ORD-B"], comment: "Wave82 mass") {
                    ok updated_count
                }
            }',
        ]);
        $cancel->assertOk();
        $this->assertArrayNotHasKey('errors', $cancel->json() ?? [], json_encode($cancel->json()));
        $this->assertSame(2, (int) $cancel->json('data.bulkCancelAdminOrders.updated_count'));
        $this->assertSame('cancelled', (string) $o1->fresh()->status);
        $this->assertSame('cancelled', (string) $o2->fresh()->status);

        Auth::guard('admin')->logout();
        $this->app['auth']->forgetGuards();
        $this->get('/api/v1/console/warehouses/export')->assertUnauthorized();
    }
}
