<?php

namespace Tests\Feature;

use App\Domain\Marketing\Models\MarketingSitemap;
use App\Domain\Order\Models\ReturnRule;
use App\Domain\Order\Models\ReturnStatus;
use App\Domain\Store\Models\Store;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave69Test extends TestCase
{
    use RefreshDatabase;

    public function test_rma_statuses_rules_and_sitemap_generate_rest_aliases(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);
        $store = Store::query()->first();
        $this->assertNotNull($store);

        $status = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation {
                createAdminRmaStatus(title: "Wave69 Pending Review", color: "#FDB022", status: 1, position: 10) {
                    id title color status default
                }
            }',
        ]);
        $status->assertOk();
        $this->assertArrayNotHasKey('errors', $status->json() ?? [], json_encode($status->json()));
        $statusId = (string) $status->json('data.createAdminRmaStatus.id');
        $this->assertSame('Wave69 Pending Review', (string) $status->json('data.createAdminRmaStatus.title'));
        $this->assertSame(1, (int) $status->json('data.createAdminRmaStatus.status'));
        $this->assertSame(0, (int) $status->json('data.createAdminRmaStatus.default'));

        $statusUpdate = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                updateAdminRmaStatus(id: $id, title: "Wave69 Approved", color: "#12B76A", status: 1) {
                    id title color
                }
            }',
            'variables' => ['id' => $statusId],
        ]);
        $statusUpdate->assertOk();
        $this->assertSame('Wave69 Approved', (string) $statusUpdate->json('data.updateAdminRmaStatus.title'));

        $massStatus = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($ids: [ID!]!) {
                createAdminRmaStatusBulkUpdateStatus(ids: $ids, status: 0) { ok updated_count }
            }',
            'variables' => ['ids' => [$statusId]],
        ]);
        $massStatus->assertOk();
        $this->assertTrue((bool) $massStatus->json('data.createAdminRmaStatusBulkUpdateStatus.ok'));
        $this->assertFalse((bool) ReturnStatus::query()->find((int) $statusId)?->is_enabled);

        $rule = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation {
                createAdminRmaRule(
                    name: "Wave69 Standard Window"
                    description: "Default eligibility"
                    returnPeriod: 45
                    status: 1
                    default: 1
                ) { id name returnPeriod status default }
            }',
        ]);
        $rule->assertOk();
        $this->assertArrayNotHasKey('errors', $rule->json() ?? [], json_encode($rule->json()));
        $ruleId = (string) $rule->json('data.createAdminRmaRule.id');
        $this->assertSame(45, (int) $rule->json('data.createAdminRmaRule.returnPeriod'));
        $this->assertSame(1, (int) $rule->json('data.createAdminRmaRule.default'));

        $ruleUpdate = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                updateAdminRmaRule(id: $id, returnPeriod: 60) { id returnPeriod }
            }',
            'variables' => ['id' => $ruleId],
        ]);
        $ruleUpdate->assertOk();
        $this->assertSame(60, (int) $ruleUpdate->json('data.updateAdminRmaRule.returnPeriod'));

        $list = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query {
                adminRmaStatuses(q: "Wave69", limit: 10) { id title }
                adminRmaStatus(id: "'.$statusId.'") { id color }
                adminRmaRules(q: "Wave69", status: 1, limit: 10) { id name returnPeriod }
                adminRmaRule(id: "'.$ruleId.'") { id description }
            }',
        ]);
        $list->assertOk();
        $this->assertArrayNotHasKey('errors', $list->json() ?? [], json_encode($list->json()));
        $this->assertNotEmpty($list->json('data.adminRmaStatuses'));
        $this->assertNotEmpty($list->json('data.adminRmaRules'));

        $sitemap = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($ch: [ID!]!) {
                createAdminMarketingSitemap(
                    fileName: "wave69-sitemap.xml"
                    path: "/sitemap/"
                    channels: $ch
                ) { id fileName path channels generatedAt }
            }',
            'variables' => ['ch' => [(string) $store->id]],
        ]);
        $sitemap->assertOk();
        $this->assertArrayNotHasKey('errors', $sitemap->json() ?? [], json_encode($sitemap->json()));
        $sitemapId = (string) $sitemap->json('data.createAdminMarketingSitemap.id');
        $this->assertSame('wave69-sitemap.xml', (string) $sitemap->json('data.createAdminMarketingSitemap.fileName'));
        $this->assertNull($sitemap->json('data.createAdminMarketingSitemap.generatedAt'));

        $generate = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                createAdminMarketingSitemapGenerate(id: $id) {
                    ok generatedAt indexFile generatedSitemaps sitemap { id generatedFiles urls }
                }
            }',
            'variables' => ['id' => $sitemapId],
        ]);
        $generate->assertOk();
        $this->assertArrayNotHasKey('errors', $generate->json() ?? [], json_encode($generate->json()));
        $this->assertTrue((bool) $generate->json('data.createAdminMarketingSitemapGenerate.ok'));
        $this->assertNotEmpty($generate->json('data.createAdminMarketingSitemapGenerate.generatedAt'));
        $this->assertNotEmpty($generate->json('data.createAdminMarketingSitemapGenerate.indexFile'));
        $this->assertNotNull(MarketingSitemap::query()->find((int) $sitemapId)?->generated_at);

        $detail = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query {
                adminMarketingSitemaps(limit: 10) { id fileName }
                adminMarketingSitemap(id: "'.$sitemapId.'") { id generatedFiles urls }
            }',
        ]);
        $detail->assertOk();
        $this->assertArrayNotHasKey('errors', $detail->json() ?? [], json_encode($detail->json()));
        $this->assertNotEmpty($detail->json('data.adminMarketingSitemaps'));
        $this->assertNotEmpty($detail->json('data.adminMarketingSitemap.generatedFiles'));

        $delStatus = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) { deleteAdminRmaStatus(id: $id) { id title message } }',
            'variables' => ['id' => $statusId],
        ]);
        $delStatus->assertOk();
        $this->assertSame($statusId, (string) $delStatus->json('data.deleteAdminRmaStatus.id'));
        $this->assertNull(ReturnStatus::query()->find((int) $statusId));

        // Create a non-default rule so the default can be removed safely.
        $extraRule = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation {
                createAdminRmaRule(name: "Wave69 Extra", returnPeriod: 14, status: 1, default: 0) { id }
            }',
        ]);
        $extraRule->assertOk();
        $extraRuleId = (string) $extraRule->json('data.createAdminRmaRule.id');

        $delRule = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) { deleteAdminRmaRule(id: $id) { id message } }',
            'variables' => ['id' => $extraRuleId],
        ]);
        $delRule->assertOk();
        $this->assertNull(ReturnRule::query()->find((int) $extraRuleId));

        $delSitemap = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) { deleteAdminMarketingSitemap(id: $id) { ok id } }',
            'variables' => ['id' => $sitemapId],
        ]);
        $delSitemap->assertOk();
        $this->assertTrue((bool) $delSitemap->json('data.deleteAdminMarketingSitemap.ok'));
        $this->assertNull(MarketingSitemap::query()->find((int) $sitemapId));
    }
}
