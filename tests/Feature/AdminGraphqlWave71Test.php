<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\ProductTemplate;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave71Test extends TestCase
{
    use RefreshDatabase;

    public function test_product_templates_and_reporting_overview_rest_aliases(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $groups = json_encode([
            [
                'id' => 1,
                'code' => 'general',
                'name' => 'General',
                'column' => 1,
                'position' => 1,
                'attributes' => [
                    ['id' => 1, 'code' => 'sku', 'type' => 'text', 'isRequired' => 1, 'column' => 1, 'position' => 1],
                    ['id' => 2, 'code' => 'name', 'type' => 'text', 'isRequired' => 1, 'column' => 1, 'position' => 2],
                ],
            ],
        ]);

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($groups: String!) {
                createAdminProductTemplate(
                    code: "wave71_apparel"
                    name: "Wave71 Apparel"
                    isDefault: true
                    templateGroupsJson: $groups
                ) { id code name is_default templateGroups }
            }',
            'variables' => ['groups' => $groups],
        ]);
        $create->assertOk();
        $this->assertArrayNotHasKey('errors', $create->json() ?? [], json_encode($create->json()));
        $familyId = (string) $create->json('data.createAdminProductTemplate.id');
        $this->assertSame('wave71_apparel', (string) $create->json('data.createAdminProductTemplate.code'));
        $this->assertTrue((bool) $create->json('data.createAdminProductTemplate.is_default'));
        $this->assertNotEmpty($create->json('data.createAdminProductTemplate.templateGroups'));

        $update = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                updateAdminProductTemplate(id: $id, name: "Wave71 Apparel Plus") { id name }
            }',
            'variables' => ['id' => $familyId],
        ]);
        $update->assertOk();
        $this->assertSame('Wave71 Apparel Plus', (string) $update->json('data.updateAdminProductTemplate.name'));

        $list = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query {
                adminProductTemplates(code: "wave71", name: "Apparel", sort: "code", order: "asc", limit: 10) {
                    id code name
                }
                adminProductTemplate(id: "'.$familyId.'") { id templateGroups }
            }',
        ]);
        $list->assertOk();
        $this->assertArrayNotHasKey('errors', $list->json() ?? [], json_encode($list->json()));
        $this->assertNotEmpty($list->json('data.adminProductTemplates'));
        $this->assertNotEmpty($list->json('data.adminProductTemplate.templateGroups'));

        $sales = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query {
                statsAdminReportingOverview(type: "total-sales", start: "2026-10-01", end: "2026-10-14") {
                    entity type dateRange { previous current } statistics
                }
            }',
        ]);
        $sales->assertOk();
        $this->assertArrayNotHasKey('errors', $sales->json() ?? [], json_encode($sales->json()));
        $this->assertSame('overview', (string) $sales->json('data.statsAdminReportingOverview.entity'));
        $this->assertSame('total-sales', (string) $sales->json('data.statsAdminReportingOverview.type'));
        $stats = json_decode((string) $sales->json('data.statsAdminReportingOverview.statistics'), true);
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('sales', $stats);
        $this->assertArrayHasKey('over_time', $stats);

        $orders = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query {
                statsAdminReportingOverview(type: "total-orders") { type statistics }
            }',
        ]);
        $orders->assertOk();
        $this->assertArrayNotHasKey('errors', $orders->json() ?? [], json_encode($orders->json()));
        $orderStats = json_decode((string) $orders->json('data.statsAdminReportingOverview.statistics'), true);
        $this->assertArrayHasKey('orders', $orderStats);

        $customers = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query {
                statsAdminReportingOverview(type: "total-customers") { type statistics }
            }',
        ]);
        $customers->assertOk();
        $this->assertArrayNotHasKey('errors', $customers->json() ?? [], json_encode($customers->json()));

        $top = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query {
                statsAdminReportingOverview(type: "top_products-by-revenue") { type statistics }
            }',
        ]);
        $top->assertOk();
        $this->assertArrayNotHasKey('errors', $top->json() ?? [], json_encode($top->json()));
        $this->assertIsArray(json_decode((string) $top->json('data.statsAdminReportingOverview.statistics'), true));

        // Default family cannot be deleted.
        $blocked = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) { deleteAdminProductTemplate(id: $id) { ok id } }',
            'variables' => ['id' => $familyId],
        ]);
        $blocked->assertOk();
        $this->assertNotEmpty($blocked->json('errors'));
        $this->assertNotNull(ProductTemplate::query()->find((int) $familyId));

        $extra = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation {
                createAdminProductTemplate(code: "wave71_extra", name: "Wave71 Extra", isDefault: false) {
                    id
                }
            }',
        ]);
        $extra->assertOk();
        $extraId = (string) $extra->json('data.createAdminProductTemplate.id');
        $del = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) { deleteAdminProductTemplate(id: $id) { ok id } }',
            'variables' => ['id' => $extraId],
        ]);
        $del->assertOk();
        $this->assertTrue((bool) $del->json('data.deleteAdminProductTemplate.ok'));
        $this->assertNull(ProductTemplate::query()->find((int) $extraId));
    }
}
