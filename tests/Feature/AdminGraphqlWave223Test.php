<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Category;
use App\Domain\Settings\ShopSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave223Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_shop_categories_and_trees_rest(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);

        $list = $this->getJson('/api/v1/store/categories?per_page=20');
        $list->assertOk();
        $this->assertIsArray($list->json());
        $this->assertNotEmpty($list->json());
        $this->assertNotEmpty((string) $list->headers->get('X-Total-Count'));
        $first = $list->json('0');
        $this->assertArrayHasKey('translation', $first);
        $this->assertSame(1, (int) $first['status']);
        $this->assertArrayHasKey('name', $first['translation']);

        $id = (int) $first['id'];
        $this->getJson('/api/v1/store/categories/'.$id)->assertOk()
            ->assertJsonPath('id', $id);

        $root = Category::query()->where('is_enabled', true)->whereNull('parent_id')->orderBy('id')->first()
            ?? Category::query()->where('is_enabled', true)->orderBy('id')->first();
        $this->assertNotNull($root);

        $emptyTree = $this->getJson('/api/v1/store/category-trees');
        $emptyTree->assertOk()->assertExactJson([]);

        $tree = $this->getJson('/api/v1/store/category-trees?parentId='.$root->id.'&depth=3');
        $tree->assertOk();
        $this->assertIsArray($tree->json());

        $this->getJson('/api/v1/store/categories/999999')->assertNotFound();
    }
}
