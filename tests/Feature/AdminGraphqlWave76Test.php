<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AdminGraphqlWave76Test extends TestCase
{
    use RefreshDatabase;

    public function test_reporting_csv_export_rest_rest_compat(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $sales = $this->actingAs($admin, 'admin')->get(
            '/api/v1/console/reporting/export?entity=sales&type=sales_total&start=2026-10-01&end=2026-10-14'
        );
        $sales->assertOk();
        $this->assertStringContainsString('text/csv', (string) $sales->headers->get('content-type'));
        $body = $sales->streamedContent();
        $this->assertNotSame('', $body);
        $this->assertStringContainsString('Date', $body);

        $products = $this->actingAs($admin, 'admin')->get(
            '/api/v1/console/reporting/export?entity=products&type=top_products-by-revenue&start=2026-10-01&end=2026-10-14'
        );
        $products->assertOk();
        $this->assertStringContainsString('text/csv', (string) $products->headers->get('content-type'));

        $customers = $this->actingAs($admin, 'admin')->get(
            '/api/v1/console/reporting/export?entity=customers&type=total-customers&start=2026-10-01&end=2026-10-14'
        );
        $customers->assertOk();

        $bad = $this->actingAs($admin, 'admin')->get(
            '/api/v1/console/reporting/export?entity=sales&type=purchase-funnel&start=2026-10-01&end=2026-10-14'
        );
        $bad->assertStatus(400);

        Auth::guard('admin')->logout();
        $this->app['auth']->forgetGuards();
        $guest = $this->withHeaders([])->get('/api/v1/console/reporting/export?entity=sales&type=sales_total');
        $guest->assertUnauthorized();
    }
}
