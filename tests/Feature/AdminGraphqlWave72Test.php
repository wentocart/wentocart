<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave72Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_reporting_sales_customers_products_panels(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $salesTypes = [
            'sales_total', 'average-sales', 'total-orders', 'purchase-funnel',
            'abandoned-carts', 'refunds', 'tax-collected', 'shipping-collected',
            'top-payment-methods', 'sales-by-coupon',
        ];
        foreach ($salesTypes as $type) {
            $res = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
                'query' => 'query ($type: String!) {
                    statsAdminReportingSales(type: $type, start: "2026-10-01", end: "2026-10-14") {
                        entity type dateRange { previous current } statistics
                    }
                }',
                'variables' => ['type' => $type],
            ]);
            $res->assertOk();
            $this->assertArrayNotHasKey('errors', $res->json() ?? [], json_encode($res->json()));
            $this->assertSame('sales', (string) $res->json('data.statsAdminReportingSales.entity'));
            $this->assertSame($type, (string) $res->json('data.statsAdminReportingSales.type'));
            $this->assertNotNull(json_decode((string) $res->json('data.statsAdminReportingSales.statistics'), true));
        }

        $customerTypes = [
            'total-customers', 'customers-traffic', 'customers-with-most-sales',
            'customers-with-most-orders', 'customers-with-most-reviews', 'top-customer-groups',
        ];
        foreach ($customerTypes as $type) {
            $res = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
                'query' => 'query ($type: String!) {
                    statsAdminReportingCustomers(type: $type, start: "2026-10-01", end: "2026-10-14") {
                        entity type statistics
                    }
                }',
                'variables' => ['type' => $type],
            ]);
            $res->assertOk();
            $this->assertArrayNotHasKey('errors', $res->json() ?? [], json_encode($res->json()));
            $this->assertSame('customers', (string) $res->json('data.statsAdminReportingCustomers.entity'));
            $this->assertSame($type, (string) $res->json('data.statsAdminReportingCustomers.type'));
        }

        $productTypes = [
            'total-sold-quantities', 'top_products-by-revenue',
            'top_products-by-quantity', 'products-with-most-reviews',
        ];
        foreach ($productTypes as $type) {
            $res = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
                'query' => 'query ($type: String!) {
                    statsAdminReportingProducts(type: $type, start: "2026-10-01", end: "2026-10-14") {
                        entity type statistics
                    }
                }',
                'variables' => ['type' => $type],
            ]);
            $res->assertOk();
            $this->assertArrayNotHasKey('errors', $res->json() ?? [], json_encode($res->json()));
            $this->assertSame('products', (string) $res->json('data.statsAdminReportingProducts.entity'));
            $this->assertSame($type, (string) $res->json('data.statsAdminReportingProducts.type'));
        }

        $bad = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query {
                statsAdminReportingSales(type: "not-a-real-panel") { entity }
            }',
        ]);
        $bad->assertOk();
        $this->assertNotEmpty($bad->json('errors'));
    }
}
