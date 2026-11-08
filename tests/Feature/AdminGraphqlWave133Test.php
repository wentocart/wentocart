<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Feature;
use App\Domain\Catalog\Models\FeatureValue;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave133Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_category_trees_query(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $parent = Category::query()->create([
            'parent_id' => null,
            'is_enabled' => true,
            'position' => 10,
        ]);
        $parent->translations()->create([
            'language_code' => 'en',
            'name' => 'Wave133 Parent',
            'slug' => 'wave133-parent-'.uniqid(),
        ]);
        $child = Category::query()->create([
            'parent_id' => $parent->id,
            'is_enabled' => true,
            'position' => 1,
        ]);
        $child->translations()->create([
            'language_code' => 'en',
            'name' => 'Wave133 Child',
            'slug' => 'wave133-child-'.uniqid(),
        ]);

        $tree = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{
                adminCategoryTrees {
                    id name children { id name parentId }
                }
            }',
        ]);
        $tree->assertOk();
        $this->assertArrayNotHasKey('errors', $tree->json() ?? [], json_encode($tree->json()));
        $roots = collect($tree->json('data.adminCategoryTrees') ?? []);
        $found = $roots->firstWhere('id', (string) $parent->id);
        $this->assertNotNull($found, json_encode($tree->json()));
        $childIds = collect($found['children'] ?? [])->pluck('id')->all();
        $this->assertContains((string) $child->id, $childIds);
    }

    public function test_admin_rest_attribute_aliases(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $code = 'w133_'.substr(uniqid(), -6);

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($code: String!) {
                createAdminAttribute(input: {
                    adminName: "Wave133 Attr"
                    code: $code
                    type: "select"
                    isFilterable: true
                }) { id code adminName name type isFilterable }
            }',
            'variables' => ['code' => $code],
        ]);
        $create->assertOk();
        $this->assertArrayNotHasKey('errors', $create->json() ?? [], json_encode($create->json()));
        $attrId = (string) $create->json('data.createAdminAttribute.id');
        $storedCode = (string) $create->json('data.createAdminAttribute.code');
        $this->assertNotSame('', $storedCode);
        $this->assertStringContainsString('w133', $storedCode);
        $this->assertSame('Wave133 Attr', (string) $create->json('data.createAdminAttribute.adminName'));

        $detail = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($id: ID!) { adminAttribute(id: $id) { id adminName code } }',
            'variables' => ['id' => $attrId],
        ]);
        $detail->assertOk();
        $this->assertArrayNotHasKey('errors', $detail->json() ?? [], json_encode($detail->json()));
        $this->assertSame('Wave133 Attr', (string) $detail->json('data.adminAttribute.adminName'));

        $upd = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                updateAdminAttribute(id: $id, adminName: "Wave133 Renamed", filterable: false) {
                    id adminName isFilterable
                }
            }',
            'variables' => ['id' => $attrId],
        ]);
        $upd->assertOk();
        $this->assertArrayNotHasKey('errors', $upd->json() ?? [], json_encode($upd->json()));
        $this->assertSame('Wave133 Renamed', (string) $upd->json('data.updateAdminAttribute.adminName'));
        $this->assertFalse((bool) $upd->json('data.updateAdminAttribute.isFilterable'));

        $opt = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($aid: ID!) {
                createAdminAttributeOption(attributeId: $aid, label: "Red", code: "red") {
                    id label attribute_id
                }
            }',
            'variables' => ['aid' => $attrId],
        ]);
        $opt->assertOk();
        $this->assertArrayNotHasKey('errors', $opt->json() ?? [], json_encode($opt->json()));
        $optId = (string) $opt->json('data.createAdminAttributeOption.id');

        $optUpd = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                updateAdminAttributeOption(id: $id, label: "Crimson") { id label }
            }',
            'variables' => ['id' => $optId],
        ]);
        $optUpd->assertOk();
        $this->assertArrayNotHasKey('errors', $optUpd->json() ?? [], json_encode($optUpd->json()));
        $this->assertSame('Crimson', (string) $optUpd->json('data.updateAdminAttributeOption.label'));

        $optDel = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) { deleteAdminAttributeOption(id: $id) { ok id } }',
            'variables' => ['id' => $optId],
        ]);
        $optDel->assertOk();
        $this->assertArrayNotHasKey('errors', $optDel->json() ?? [], json_encode($optDel->json()));
        $this->assertTrue((bool) $optDel->json('data.deleteAdminAttributeOption.ok'));
        $this->assertNull(FeatureValue::query()->find((int) $optId));

        $mass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                bulkDeleteAdminAttribute(ids: [$id]) { deleted updated_count }
            }',
            'variables' => ['id' => $attrId],
        ]);
        $mass->assertOk();
        $this->assertArrayNotHasKey('errors', $mass->json() ?? [], json_encode($mass->json()));
        $deleted = $mass->json('data.bulkDeleteAdminAttribute.deleted') ?? [];
        $this->assertContains($attrId, array_map('strval', $deleted));
        $this->assertNull(Feature::query()->find((int) $attrId));
    }
}
