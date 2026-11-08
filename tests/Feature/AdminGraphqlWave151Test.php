<?php

namespace Tests\Feature;

use App\Domain\Api\Graphql\GraphqlTiming;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave151Test extends TestCase
{
    use RefreshDatabase;

    public function test_shop_and_admin_graphql_emit_server_timing(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $shop = $this->postJson('/api/v1/graphql', [
            'query' => '{ products(first: 1) { edges { node { id } } } }',
        ]);
        $shop->assertOk();
        $this->assertNotNull($shop->headers->get(GraphqlTiming::HEADER_MS));
        $this->assertGreaterThanOrEqual(0, (int) $shop->headers->get(GraphqlTiming::HEADER_MS));
        $this->assertStringContainsString('gql;dur=', (string) $shop->headers->get('Server-Timing'));

        $adminRes = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ adminCatalogCategories(first: 1) { id } }',
        ]);
        $adminRes->assertOk();
        $this->assertArrayNotHasKey('errors', $adminRes->json() ?? [], json_encode($adminRes->json()));
        $this->assertNotNull($adminRes->headers->get(GraphqlTiming::HEADER_MS));
        $this->assertStringContainsString('gql;dur=', (string) $adminRes->headers->get('Server-Timing'));
    }
}
