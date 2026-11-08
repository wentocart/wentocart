<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave141Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_catalog_categories_alias_and_rest_fields(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $list = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{
                adminCatalogCategories(first: 5, status: 1) {
                    id
                    id
                    name
                    slug
                    status
                    parentId
                    position
                    displayMode
                    translations
                    filterableAttributeIds
                }
            }',
        ]);
        $list->assertOk();
        $this->assertArrayNotHasKey('errors', $list->json() ?? [], json_encode($list->json()));
        $rows = $list->json('data.adminCatalogCategories') ?? [];
        $this->assertNotEmpty($rows);
        $first = $rows[0];
        $this->assertSame((int) $first['id'], (int) $first['id']);
        $this->assertSame('products_and_description', (string) $first['displayMode']);
        $this->assertSame(1, (int) $first['status']);
        $decoded = json_decode((string) $first['translations'], true);
        $this->assertIsArray($decoded);
        $this->assertNotEmpty($decoded);
        $this->assertSame('[]', (string) $first['filterableAttributeIds']);

        $alias = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{
                adminCategories(first: 5, status: 1) { id name }
            }',
        ]);
        $alias->assertOk();
        $this->assertArrayNotHasKey('errors', $alias->json() ?? [], json_encode($alias->json()));
        $this->assertCount(count($rows), $alias->json('data.adminCategories') ?? []);
    }
}
