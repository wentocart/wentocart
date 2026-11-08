<?php

namespace Tests\Feature;

use App\Domain\Api\Graphql\GraphqlSecurityRules;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave145Test extends TestCase
{
    use RefreshDatabase;

    public function test_graphql_rejects_excessive_field_aliases(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $this->assertSame(25, GraphqlSecurityRules::MAX_ALIASES);

        $aliases = [];
        for ($i = 0; $i < 26; $i++) {
            $aliases[] = "a{$i}: id";
        }
        $query = '{ adminCatalogCategories(first: 1) { '.implode(' ', $aliases).' } }';

        $res = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => $query,
        ]);
        $res->assertOk();
        $message = (string) $res->json('errors.0.message');
        $this->assertStringContainsString('aliases', strtolower($message));
    }

    public function test_graphql_rejects_json_array_batching(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $res = $this->actingAs($admin, 'admin')->call(
            'POST',
            '/api/v1/console/graphql',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            json_encode([
                ['query' => '{ adminCatalogCategories(first: 1) { id } }'],
                ['query' => '{ adminCatalogCategories(first: 1) { id } }'],
            ], JSON_THROW_ON_ERROR),
        );

        $res->assertStatus(400);
        $message = (string) $res->json('errors.0.message');
        $this->assertStringContainsString('batching', strtolower($message));
    }

    public function test_graphql_allows_moderate_aliases(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $res = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ a0: adminCatalogCategories(first: 1) { id } a1: adminCatalogCategories(first: 1) { id } }',
        ]);
        $res->assertOk();
        $this->assertArrayNotHasKey('errors', $res->json() ?? [], json_encode($res->json()));
    }
}
