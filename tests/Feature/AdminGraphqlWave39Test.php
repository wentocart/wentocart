<?php

namespace Tests\Feature;

use App\Domain\Cms\Models\CmsPage;
use App\Domain\Order\Models\Order;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AdminGraphqlWave39Test extends TestCase
{
    use RefreshDatabase;

    public function test_integration_token_cms_page_and_refund_preview(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $tokenRes = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation {
                createIntegrationToken(name: "Wave39 Integration") {
                    id name plain_token abilities
                }
            }',
        ]);
        $tokenRes->assertOk();
        $this->assertArrayNotHasKey('errors', $tokenRes->json() ?? []);
        $plain = (string) $tokenRes->json('data.createIntegrationToken.plain_token');
        $tokenId = (string) $tokenRes->json('data.createIntegrationToken.id');
        $this->assertNotSame('', $plain);

        $viaBearer = $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson('/api/v1/console/graphql', [
                'query' => '{ integrationTokens { id name } }',
            ]);
        $viaBearer->assertOk();
        $this->assertArrayNotHasKey('errors', $viaBearer->json() ?? []);
        $this->assertNotEmpty($viaBearer->json('data.integrationTokens'));

        $cms = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation {
                createCmsPage(title: "Wave39 About", slug: "wave39-about", body: "<p>Hi</p>", status: "published") {
                    id title slug status is_enabled
                }
            }',
        ]);
        $cms->assertOk();
        $this->assertArrayNotHasKey('errors', $cms->json() ?? []);
        $cms->assertJsonPath('data.createCmsPage.slug', 'wave39-about');
        $cmsId = (string) $cms->json('data.createCmsPage.id');
        $this->assertNotNull(CmsPage::query()->find((int) $cmsId));

        $list = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ cmsPages(limit: 20) { id slug title } }',
        ]);
        $list->assertOk();
        $this->assertArrayNotHasKey('errors', $list->json() ?? []);
        $slugs = collect($list->json('data.cmsPages') ?? [])->pluck('slug')->all();
        $this->assertContains('wave39-about', $slugs);

        $delCms = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) { deleteCmsPage(id: $id) { ok id } }',
            'variables' => ['id' => $cmsId],
        ]);
        $delCms->assertOk();
        $this->assertTrue((bool) $delCms->json('data.deleteCmsPage.ok'));

        $order = Order::query()->first();
        if ($order) {
            $preview = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
                'query' => 'query ($n: String!) {
                    refundPreview(number: $n) {
                        order_number grand_total refunded_total refundable_total can_refund
                    }
                }',
                'variables' => ['n' => $order->number],
            ]);
            $preview->assertOk();
            $this->assertArrayNotHasKey('errors', $preview->json() ?? []);
            $preview->assertJsonPath('data.refundPreview.order_number', $order->number);
            $this->assertGreaterThanOrEqual(0, (float) $preview->json('data.refundPreview.refundable_total'));
        }

        $revoke = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) { revokeIntegrationToken(id: $id) { ok id } }',
            'variables' => ['id' => $tokenId],
        ]);
        $revoke->assertOk();
        $this->assertTrue((bool) $revoke->json('data.revokeIntegrationToken.ok'));
        $this->assertNull(PersonalAccessToken::query()->find((int) $tokenId));
    }
}
