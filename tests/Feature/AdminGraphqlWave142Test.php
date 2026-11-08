<?php

namespace Tests\Feature;

use App\Domain\Api\Graphql\GraphqlSecurityRules;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave142Test extends TestCase
{
    use RefreshDatabase;

    public function test_graphql_rejects_over_depth_and_allows_normal_queries(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $this->assertSame(300, GraphqlSecurityRules::MAX_COMPLEXITY);
        $this->assertSame(15, GraphqlSecurityRules::MAX_DEPTH);
        $this->assertLessThan(400, GraphqlSecurityRules::MAX_COMPLEXITY);
        $this->assertLessThan(20, GraphqlSecurityRules::MAX_DEPTH);

        $ok = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ adminCatalogCategories(first: 2) { id name status displayMode } }',
        ]);
        $ok->assertOk();
        $this->assertArrayNotHasKey('errors', $ok->json() ?? [], json_encode($ok->json()));
        $this->assertIsArray($ok->json('data.adminCatalogCategories'));

        // Build a selection deeper than MAX_DEPTH (15).
        $nest = 'id';
        for ($i = 0; $i < 20; $i++) {
            $nest = "children { {$nest} }";
        }
        $deep = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => "{ adminCategoryTrees { {$nest} } }",
        ]);
        $deep->assertOk();
        $errors = $deep->json('errors') ?? [];
        $this->assertNotEmpty($errors);
        $message = strtolower(implode(' ', array_map(fn ($e) => (string) ($e['message'] ?? ''), $errors)));
        $this->assertTrue(
            str_contains($message, 'depth') || str_contains($message, 'max'),
            $message
        );
    }
}
