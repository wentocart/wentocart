<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Category;
use App\Domain\Cms\Models\CmsPage;
use App\Domain\Customer\Models\Customer;
use App\Domain\Localization\Models\Language;
use App\Domain\Order\Models\Order;
use App\Domain\Store\StoreContext;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AdminGraphqlWave78Test extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_cms_csv_exports_and_category_mass_actions(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $storeId = app(StoreContext::class)->requireId();
        $customer = Customer::factory()->create(['store_id' => $storeId]);
        $order = Order::createTrusted([
            'number' => 'W78-INV-1',
            'customer_id' => $customer->id,
            'email' => 'wave78-invoice@example.com',
            'status' => 'processing',
            'currency_code' => 'USD',
            'payment_status' => 'pending',
            'grand_total' => 55,
            'billing_address' => ['country_code' => 'US', 'first_name' => 'Wave', 'line1' => '1 Main'],
            'shipping_address' => ['country_code' => 'US', 'first_name' => 'Wave', 'line1' => '1 Main'],
            'fulfillment_details' => [
                'invoice_number' => 'INV-W78-001',
                'invoice_state' => 'pending',
            ],
        ]);

        $invCsv = $this->actingAs($admin, 'admin')->get('/api/v1/console/invoices/export?state=pending');
        $invCsv->assertOk();
        $this->assertStringContainsString('text/csv', (string) $invCsv->headers->get('content-type'));
        $invBody = $invCsv->streamedContent();
        $this->assertStringContainsString('INV-W78-001', $invBody);
        $this->assertStringContainsString('W78-INV-1', $invBody);

        $locale = Language::defaultCode();
        $page = CmsPage::query()->create([
            'status' => 'published',
            'is_enabled' => true,
            'is_visible' => true,
        ]);
        $page->translations()->create([
            'language_code' => $locale,
            'title' => 'Wave78 About',
            'slug' => 'wave78-about',
            'body' => '<p>Wave78</p>',
        ]);

        $cmsCsv = $this->actingAs($admin, 'admin')->get('/api/v1/console/cms/pages/export');
        $cmsCsv->assertOk();
        $this->assertStringContainsString('text/csv', (string) $cmsCsv->headers->get('content-type'));
        $this->assertStringContainsString('wave78-about', $cmsCsv->streamedContent());

        $catA = Category::query()->create(['is_enabled' => true, 'position' => 90]);
        $catA->translations()->create([
            'language_code' => $locale,
            'name' => 'Wave78 Mass A',
            'slug' => 'wave78-mass-a',
        ]);
        $catB = Category::query()->create(['is_enabled' => true, 'position' => 91]);
        $catB->translations()->create([
            'language_code' => $locale,
            'name' => 'Wave78 Mass B',
            'slug' => 'wave78-mass-b',
        ]);

        $massStatus = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($ids: [ID!]!) {
                bulkUpdateAdminCategoryStatus(ids: $ids, status: 0) { ok updated_count }
            }',
            'variables' => ['ids' => [(string) $catA->id, (string) $catB->id]],
        ]);
        $massStatus->assertOk();
        $this->assertArrayNotHasKey('errors', $massStatus->json() ?? [], json_encode($massStatus->json()));
        $this->assertSame(2, (int) $massStatus->json('data.bulkUpdateAdminCategoryStatus.updated_count'));
        $this->assertFalse((bool) $catA->fresh()->is_enabled);
        $this->assertFalse((bool) $catB->fresh()->is_enabled);

        $massDel = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($ids: [ID!]!) {
                bulkDeleteAdminCategory(ids: $ids) { ok updated_count }
            }',
            'variables' => ['ids' => [(string) $catA->id, (string) $catB->id]],
        ]);
        $massDel->assertOk();
        $this->assertArrayNotHasKey('errors', $massDel->json() ?? [], json_encode($massDel->json()));
        $this->assertGreaterThanOrEqual(1, (int) $massDel->json('data.bulkDeleteAdminCategory.updated_count'));
        $this->assertNull(Category::query()->find($catA->id));

        Auth::guard('admin')->logout();
        $this->app['auth']->forgetGuards();
        $this->get('/api/v1/console/invoices/export')->assertUnauthorized();
        $this->assertNotNull($order->id);
    }
}
