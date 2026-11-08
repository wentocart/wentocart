<?php

namespace Tests\Feature;

use App\Domain\Api\Graphql\GraphqlSecurityRules;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave144Test extends TestCase
{
    use RefreshDatabase;

    public function test_graphql_rejects_oversized_query_document(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $this->assertSame(32768, GraphqlSecurityRules::MAX_QUERY_BYTES);

        $huge = '{ adminCatalogCategories { id ' . str_repeat(' name', 20000) . ' } }';
        $this->assertGreaterThan(GraphqlSecurityRules::MAX_QUERY_BYTES, strlen($huge));

        $res = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => $huge,
        ]);
        $res->assertStatus(400);
        $message = (string) $res->json('errors.0.message');
        $this->assertStringContainsString('maximum size', strtolower($message));

        $ok = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ adminCatalogCategories(first: 1) { id } }',
        ]);
        $ok->assertOk();
        $this->assertArrayNotHasKey('errors', $ok->json() ?? [], json_encode($ok->json()));
    }
}
