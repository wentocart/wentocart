<?php

namespace Tests\Feature;

use App\Domain\Api\Graphql\GraphqlTiming;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave162Test extends TestCase
{
    use RefreshDatabase;

    public function test_graphql_echoes_operation_name_header(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $res = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query Wave162Cats { adminCatalogCategories(first: 1) { id } }',
            'operationName' => 'Wave162Cats',
        ]);
        $res->assertOk();
        $this->assertArrayNotHasKey('errors', $res->json() ?? [], json_encode($res->json()));
        $this->assertSame('Wave162Cats', (string) $res->headers->get(GraphqlTiming::HEADER_OP));
        $this->assertStringContainsString('X-Wento-GQL-Op', (string) $res->headers->get('Access-Control-Expose-Headers'));
    }
}
