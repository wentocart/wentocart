<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Settings\Models\DataTransferImport;
use App\Domain\Store\StoreContext;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AdminGraphqlWave86Test extends TestCase
{
    use RefreshDatabase;

    public function test_customer_mass_ops_and_geo_data_transfer_csv(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $countriesCsv = $this->actingAs($admin, 'admin')->get('/api/v1/console/countries/export');
        $countriesCsv->assertOk();
        $this->assertStringContainsString('text/csv', (string) $countriesCsv->headers->get('content-type'));

        $statesCsv = $this->actingAs($admin, 'admin')->get('/api/v1/console/states/export');
        $statesCsv->assertOk();
        $this->assertStringContainsString('text/csv', (string) $statesCsv->headers->get('content-type'));

        DataTransferImport::query()->create([
            'code' => 'w86-import',
            'action' => 'products',
            'state' => 'pending',
            'file_name' => 'w86.csv',
            'processed_rows_count' => 0,
            'invalid_rows_count' => 0,
            'errors_count' => 0,
        ]);
        $importsCsv = $this->actingAs($admin, 'admin')->get('/api/v1/console/data-transfer-imports/export');
        $importsCsv->assertOk();
        $this->assertStringContainsString('w86-import', $importsCsv->streamedContent());

        $storeId = app(StoreContext::class)->requireId();
        $ok = Customer::factory()->create([
            'store_id' => $storeId,
            'email' => 'wave86-ok@example.com',
            'is_enabled' => true,
        ]);
        $active = Customer::factory()->create([
            'store_id' => $storeId,
            'email' => 'wave86-active@example.com',
            'is_enabled' => true,
        ]);
        Order::createTrusted([
            'number' => 'W86-ACTIVE-1',
            'customer_id' => $active->id,
            'email' => $active->email,
            'status' => 'processing',
            'currency_code' => 'USD',
            'payment_status' => 'paid',
            'grand_total' => 20,
            'billing_address' => ['country_code' => 'US', 'first_name' => 'A', 'line1' => '1 Main'],
            'shipping_address' => ['country_code' => 'US', 'first_name' => 'A', 'line1' => '1 Main'],
        ]);

        $status = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                bulkUpdateAdminCustomerStatus(ids: [$id], value: 0) { ok updated_count }
            }',
            'variables' => ['id' => (string) $ok->id],
        ]);
        $status->assertOk();
        $this->assertArrayNotHasKey('errors', $status->json() ?? [], json_encode($status->json()));
        $this->assertSame(1, (int) $status->json('data.bulkUpdateAdminCustomerStatus.updated_count'));
        $this->assertFalse((bool) $ok->fresh()->is_enabled);

        $mass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($a: ID!, $b: ID!) {
                bulkDeleteAdminCustomer(ids: [$a, $b]) {
                    ok updated_count deleted skipped
                }
            }',
            'variables' => ['a' => (string) $ok->id, 'b' => (string) $active->id],
        ]);
        $mass->assertOk();
        $this->assertArrayNotHasKey('errors', $mass->json() ?? [], json_encode($mass->json()));
        $this->assertSame(1, (int) $mass->json('data.bulkDeleteAdminCustomer.updated_count'));
        $this->assertContains((string) $ok->id, $mass->json('data.bulkDeleteAdminCustomer.deleted') ?? []);
        $this->assertContains((string) $active->id, $mass->json('data.bulkDeleteAdminCustomer.skipped') ?? []);
        $this->assertNull(Customer::query()->find($ok->id));
        $this->assertNotNull(Customer::query()->find($active->id));

        Auth::guard('admin')->logout();
        $this->app['auth']->forgetGuards();
        $this->get('/api/v1/console/countries/export')->assertUnauthorized();
    }
}
