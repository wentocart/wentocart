<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Ops\Models\CommerceEvent;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AdminGraphqlWave74Test extends TestCase
{
    use RefreshDatabase;

    public function test_product_reporting_extras_and_integration_ip_allowlist(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $customer = Customer::factory()->create();
        DB::table('wishlists')->insert([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'created_at' => '2026-10-10 12:00:00',
            'updated_at' => '2026-10-10 12:00:00',
        ]);

        CommerceEvent::query()->create([
            'store_id' => null,
            'name' => 'catalog.product_view',
            'payload' => ['product_id' => $product->id, 'name' => 'Demo Product'],
            'created_at' => '2026-10-11 09:00:00',
        ]);
        CommerceEvent::query()->create([
            'store_id' => null,
            'name' => 'catalog.search',
            'payload' => ['term' => 'wave74 sneakers', 'results' => 3, 'locale' => 'en'],
            'created_at' => '2026-10-12 09:00:00',
        ]);
        CommerceEvent::query()->create([
            'store_id' => null,
            'name' => 'catalog.search',
            'payload' => ['term' => 'wave74 sneakers', 'results' => 3, 'locale' => 'en'],
            'created_at' => '2026-10-13 09:00:00',
        ]);

        $types = [
            'total-products-added-to-wishlist',
            'products-with-most-visits',
            'last-search-terms',
            'top-search-terms',
        ];
        foreach ($types as $type) {
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
            $decoded = json_decode((string) $res->json('data.statsAdminReportingProducts.statistics'), true);
            $this->assertNotNull($decoded);
            if ($type === 'total-products-added-to-wishlist') {
                $this->assertArrayHasKey('wishlist', $decoded);
                $this->assertGreaterThanOrEqual(1, (float) ($decoded['wishlist']['current'] ?? 0));
            }
            if ($type === 'products-with-most-visits') {
                $this->assertNotEmpty($decoded);
                $this->assertSame((int) $product->id, (int) ($decoded[0]['visitable_id'] ?? 0));
            }
            if ($type === 'top-search-terms') {
                $this->assertNotEmpty($decoded);
                $this->assertSame('wave74 sneakers', (string) ($decoded[0]['term'] ?? ''));
                $this->assertGreaterThanOrEqual(2, (int) ($decoded[0]['uses'] ?? 0));
            }
        }

        $viewWish = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query {
                viewStatsAdminReportingProducts(type: "total-products-added-to-wishlist", start: "2026-10-01", end: "2026-10-14") {
                    entity statistics
                }
            }',
        ]);
        $viewWish->assertOk();
        $this->assertArrayNotHasKey('errors', $viewWish->json() ?? [], json_encode($viewWish->json()));
        $table = json_decode((string) $viewWish->json('data.viewStatsAdminReportingProducts.statistics'), true);
        $this->assertArrayHasKey('columns', $table);
        $this->assertArrayHasKey('records', $table);

        $blockedView = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query {
                viewStatsAdminReportingProducts(type: "top-search-terms", start: "2026-10-01", end: "2026-10-14") {
                    entity
                }
            }',
        ]);
        $blockedView->assertOk();
        $this->assertNotEmpty($blockedView->json('errors'));

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation {
                createIntegrationToken(name: "Wave74 Locked", allowedIps: "[\\"203.0.113.9\\"]") {
                    id plain_token allowed_ips abilities
                }
            }',
        ]);
        $create->assertOk();
        $this->assertArrayNotHasKey('errors', $create->json() ?? [], json_encode($create->json()));
        $plain = (string) $create->json('data.createIntegrationToken.plain_token');
        $tokenId = (string) $create->json('data.createIntegrationToken.id');
        $this->assertContains('203.0.113.9', $create->json('data.createIntegrationToken.allowed_ips') ?? []);
        $this->assertNotSame('', $plain);

        \Illuminate\Support\Facades\Auth::guard('admin')->logout();

        $denied = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson('/api/v1/console/graphql', [
                'query' => '{ integrationTokens { id } }',
            ]);
        $denied->assertUnauthorized();

        $allowed = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson('/api/v1/console/graphql', [
                'query' => '{ integrationTokens { id allowed_ips } }',
            ]);
        $allowed->assertOk();
        $this->assertArrayNotHasKey('errors', $allowed->json() ?? [], json_encode($allowed->json()));

        $update = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                updateIntegrationTokenAllowlist(id: $id, allowedIps: "[]") {
                    id allowed_ips
                }
            }',
            'variables' => ['id' => $tokenId],
        ]);
        $update->assertOk();
        $this->assertArrayNotHasKey('errors', $update->json() ?? [], json_encode($update->json()));
        $this->assertSame([], $update->json('data.updateIntegrationTokenAllowlist.allowed_ips') ?? null);

        $open = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson('/api/v1/console/graphql', [
                'query' => '{ integrationTokens { id } }',
            ]);
        $open->assertOk();

        $this->assertNotNull(PersonalAccessToken::query()->find((int) $tokenId));
        $revoke = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) { revokeIntegrationToken(id: $id) { ok } }',
            'variables' => ['id' => $tokenId],
        ]);
        $revoke->assertOk();
        $this->assertTrue((bool) $revoke->json('data.revokeIntegrationToken.ok'));
    }
}
