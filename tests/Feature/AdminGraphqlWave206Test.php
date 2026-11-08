<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AdminGraphqlWave206Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_reporting_rest_rest_compat(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $qs = 'start=2026-10-01&end=2026-10-14';

        $overview = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/reporting/stats?type=sales_total&'.$qs);
        $overview->assertOk();
        $this->assertSame('overview', (string) $overview->json('entity'));
        $this->assertSame('sales_total', (string) $overview->json('type'));
        $this->assertIsArray($overview->json('statistics'));
        $this->assertArrayHasKey('current', $overview->json('dateRange'));

        $sales = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/metrics/sales?type=total-orders&'.$qs);
        $sales->assertOk();
        $this->assertSame('sales', (string) $sales->json('entity'));
        $this->assertSame('total-orders', (string) $sales->json('type'));
        $this->assertIsArray($sales->json('statistics'));

        $customers = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/metrics/customers?type=total-customers&'.$qs);
        $customers->assertOk();
        $this->assertSame('customers', (string) $customers->json('entity'));

        $products = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/metrics/products?type=top_products-by-revenue&'.$qs);
        $products->assertOk();
        $this->assertSame('products', (string) $products->json('entity'));

        $viewSales = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/metrics/sales/view?type=sales_total&'.$qs);
        $viewSales->assertOk();
        $this->assertIsArray($viewSales->json('columns'));
        $this->assertIsArray($viewSales->json('records'));

        $viewCustomers = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/metrics/customers/view?type=total-customers&'.$qs);
        $viewCustomers->assertOk();
        $this->assertIsArray($viewCustomers->json('columns'));

        $viewProducts = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/metrics/products/view?type=top_products-by-revenue&'.$qs);
        $viewProducts->assertOk();
        $this->assertIsArray($viewProducts->json('records'));

        $exportSales = $this->actingAs($admin, 'admin')->get('/api/v1/console/metrics/sales/export?format=csv&type=sales_total&'.$qs);
        $exportSales->assertOk();
        $this->assertStringContainsString('text/csv', (string) $exportSales->headers->get('content-type'));

        $exportCustomers = $this->actingAs($admin, 'admin')->get('/api/v1/console/metrics/customers/export?format=csv&type=total-customers&'.$qs);
        $exportCustomers->assertOk();

        $exportProducts = $this->actingAs($admin, 'admin')->get('/api/v1/console/metrics/products/export?format=csv&type=total-sold-quantities&'.$qs);
        $exportProducts->assertOk();

        Auth::guard('admin')->logout();
        $this->app['auth']->forgetGuards();
        $guest = $this->getJson('/api/v1/console/reporting/stats?'.$qs);
        $guest->assertUnauthorized();

        $gql = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ statsAdminReportingOverview(type: "sales_total", start: "2026-10-01", end: "2026-10-14") { entity type } }',
        ]);
        $gql->assertOk();
        $this->assertArrayNotHasKey('errors', $gql->json() ?? [], json_encode($gql->json()));
        $this->assertSame('overview', (string) $gql->json('data.statsAdminReportingOverview.entity'));
    }
}
