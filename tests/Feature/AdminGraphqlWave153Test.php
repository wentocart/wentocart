<?php

namespace Tests\Feature;

use App\Domain\Api\Graphql\GraphqlSecurityRules;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave153Test extends TestCase
{
    use RefreshDatabase;

    public function test_graphql_rejects_multi_operation_documents(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $this->assertSame(1, GraphqlSecurityRules::MAX_OPERATIONS);

        $res = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => <<<'GQL'
                query A { adminCatalogCategories(first: 1) { id } }
                query B { adminCatalogCategories(first: 1) { id } }
            GQL,
            'operationName' => 'A',
        ]);
        $res->assertOk();
        $message = (string) $res->json('errors.0.message');
        $this->assertStringContainsString('operations', strtolower($message));
    }

    public function test_single_named_operation_still_works(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $res = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query Cats { adminCatalogCategories(first: 1) { id } }',
            'operationName' => 'Cats',
        ]);
        $res->assertOk();
        $this->assertArrayNotHasKey('errors', $res->json() ?? [], json_encode($res->json()));
    }
}
