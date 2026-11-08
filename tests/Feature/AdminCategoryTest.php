<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCategoryTest extends TestCase
{
    use RefreshDatabase;

    protected function createCategory(string $name, ?int $parentId = null, array $overrides = []): Category
    {
        $payload = array_merge([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name),
            'parent_id' => $parentId,
            'description' => 'Description for '.$name,
            'position' => 0,
            'is_enabled' => true,
        ], $overrides);

        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/categories', $payload)
            ->assertRedirect('/admin/categories');

        return Category::query()->whereHas('translations', fn ($q) => $q->where('slug', $payload['slug']))->firstOrFail();
    }

    public function test_category_crud_and_toggle(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->get('/admin/categories')->assertOk();
        $this->get('/admin/categories?name=Men&status=active&products_min=0')->assertOk();

        $category = $this->createCategory('Mens Clothing', null, ['position' => 1]);

        $this->assertSame('Mens Clothing', $category->translation()?->name);
        $this->assertTrue($category->is_enabled);

        $this->get('/admin/categories/'.$category->id.'/edit')->assertOk();

        $this->put('/admin/categories/'.$category->id, [
            'name' => 'Men Clothing',
            'slug' => 'men-clothing',
            'description' => 'Updated',
            'position' => 2,
            'is_enabled' => true,
        ])->assertRedirect('/admin/categories');

        $category->refresh();
        $this->assertSame('Men Clothing', $category->translation()?->name);
        $this->assertSame(2, (int) $category->position);

        $this->put('/admin/categories/'.$category->id, [
            'name' => 'Men Clothing',
            'slug' => 'men-clothing',
            'description' => 'Updated again',
            'is_enabled' => true,
        ])->assertRedirect('/admin/categories');

        $category->refresh();
        $this->assertSame(2, (int) $category->position);

        $this->post('/admin/categories/'.$category->id.'/toggle')->assertRedirect();
        $this->assertFalse($category->fresh()->is_enabled);

        $this->delete('/admin/categories/'.$category->id)->assertRedirect('/admin/categories');
        $this->assertNull(Category::query()->find($category->id));
    }

    public function test_category_hierarchy_and_circular_parent_protection(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $root = $this->createCategory('Clothing');
        $child = $this->createCategory('Men', $root->id);
        $grandchild = $this->createCategory('Shirts', $child->id);

        $this->from('/admin/categories/'.$root->id.'/edit')->put('/admin/categories/'.$root->id, [
            'name' => 'Clothing',
            'slug' => 'clothing',
            'parent_id' => $root->id,
            'position' => 0,
            'is_enabled' => true,
        ])->assertRedirect('/admin/categories/'.$root->id.'/edit')
            ->assertSessionHasErrors('parent_id');

        $this->from('/admin/categories/'.$root->id.'/edit')->put('/admin/categories/'.$root->id, [
            'name' => 'Clothing',
            'slug' => 'clothing',
            'parent_id' => $grandchild->id,
            'position' => 0,
            'is_enabled' => true,
        ])->assertSessionHasErrors('parent_id');

        $this->assertSame($root->id, $child->fresh()->parent_id);
        $this->assertSame($child->id, $grandchild->fresh()->parent_id);
    }

    public function test_duplicate_slug_is_rejected(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->createCategory('Accessories', null, ['slug' => 'accessories']);

        $this->from('/admin/categories/create')->post('/admin/categories', [
            'name' => 'Other Accessories',
            'slug' => 'accessories',
            'position' => 0,
            'is_enabled' => true,
        ])->assertSessionHasErrors('slug');
    }

    public function test_category_service_blocks_delete_with_children(): void
    {
        $this->seed();

        $parent = Category::query()->create(['is_enabled' => true, 'position' => 0]);
        $parent->translations()->create(['language_code' => 'en', 'name' => 'Parent', 'slug' => 'parent-svc']);
        Category::query()->create(['parent_id' => $parent->id, 'is_enabled' => true, 'position' => 0])
            ->translations()->create(['language_code' => 'en', 'name' => 'Child', 'slug' => 'child-svc']);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(\App\Domain\Catalog\Services\CategoryService::class)->delete($parent->fresh());
    }

    public function test_category_delete_is_blocked_when_products_or_children_exist(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $parent = $this->createCategory('Blocked Parent');
        $child = $this->createCategory('Blocked Child', $parent->id);
        $this->assertSame($parent->id, $child->fresh()->parent_id);
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();

        $this->put('/admin/products/'.$product->id, [
            'name' => 'Demo Cotton Tee',
            'price' => 29.99,
            'sku' => 'DEMO-001',
            'category_ids' => [$parent->id],
            'default_category_id' => $parent->id,
        ])->assertRedirect();

        $this->delete('/admin/categories/'.$parent->id)->assertRedirect();
        $this->assertNotNull(Category::withoutGlobalScopes()->find($parent->id));

        $this->delete('/admin/categories/'.$child->id)->assertRedirect('/admin/categories');
        $this->assertNull(Category::withoutGlobalScopes()->find($child->id));

        $this->delete('/admin/categories/'.$parent->id)->assertRedirect();
        $this->assertNotNull(Category::withoutGlobalScopes()->find($parent->id));

        $fallbackCategoryId = (int) Category::query()->whereKeyNot($parent->id)->value('id');
        $this->assertGreaterThan(0, $fallbackCategoryId);

        $this->put('/admin/products/'.$product->id, [
            'name' => 'Demo Cotton Tee',
            'price' => 29.99,
            'sku' => 'DEMO-001',
            'category_ids' => [$fallbackCategoryId],
            'default_category_id' => $fallbackCategoryId,
        ])->assertRedirect();

        $this->delete('/admin/categories/'.$parent->id)->assertRedirect('/admin/categories');
        $this->assertNull(Category::withoutGlobalScopes()->find($parent->id));
    }

    public function test_disabled_category_keeps_product_assignment(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $category = $this->createCategory('Temporary');
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();

        $this->put('/admin/products/'.$product->id, $this->withProductCategories([
            'name' => 'Demo Cotton Tee',
            'price' => 29.99,
            'sku' => 'DEMO-001',
            'category_ids' => [$category->id],
            'default_category_id' => $category->id,
        ]))->assertRedirect();

        $this->post('/admin/categories/'.$category->id.'/toggle')->assertRedirect();
        $product->refresh();
        $this->assertTrue($product->categories->contains('id', $category->id));
    }

    public function test_category_customer_group_access_is_saved(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $groups = \App\Domain\Customer\Models\CustomerGroup::catalog(true)->take(2)->pluck('id')->all();
        $this->assertNotEmpty($groups);

        $category = $this->createCategory('Group Restricted', null, [
            'customer_group_ids' => $groups,
        ]);

        $this->assertSame($groups, $category->fresh()->customer_group_ids);

        $this->put('/admin/categories/'.$category->id, [
            'name' => 'Group Restricted',
            'slug' => 'group-restricted',
            'position' => 0,
            'is_enabled' => true,
            'customer_group_ids' => [],
        ])->assertRedirect('/admin/categories');

        $this->assertSame([], $category->fresh()->customer_group_ids);
    }

    public function test_category_list_supports_search_and_filters(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->createCategory('Alpha Category', null, ['slug' => 'alpha-category']);
        $this->createCategory('Beta Category', null, ['slug' => 'beta-category', 'is_enabled' => false]);

        $this->get('/admin/categories?q=alpha')->assertOk()->assertSee('Alpha Category');
        $this->get('/admin/categories?status=inactive')->assertOk()->assertSee('Beta Category');
    }

    public function test_category_index_returns_nested_tree(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $root = $this->createCategory('Tree Root');
        $child = $this->createCategory('Tree Child', $root->id);

        $response = $this->get('/admin/categories')->assertOk();
        $tree = $response->original->getData()['page']['props']['tree'] ?? null;
        $this->assertIsArray($tree);

        $rootNode = collect($tree)->firstWhere('id', $root->id);
        $this->assertNotNull($rootNode);
        $this->assertSame('Tree Root', $rootNode['name']);
        $this->assertSame($child->id, $rootNode['children'][0]['id'] ?? null);
        $this->assertSame('Tree Child', $rootNode['children'][0]['name'] ?? null);
    }

    public function test_category_bulk_status_and_delete(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $one = $this->createCategory('Bulk One');
        $two = $this->createCategory('Bulk Two');

        $this->post('/admin/categories/bulk-status', [
            'ids' => [$one->id, $two->id],
            'enabled' => 0,
        ])->assertRedirect();

        $this->assertFalse($one->fresh()->is_enabled);
        $this->assertFalse($two->fresh()->is_enabled);

        $this->post('/admin/categories/bulk-status', [
            'ids' => [$one->id, $two->id],
            'enabled' => 1,
        ])->assertRedirect();

        $this->assertTrue($one->fresh()->is_enabled);
        $this->assertTrue($two->fresh()->is_enabled);

        $this->post('/admin/categories/bulk-delete', [
            'ids' => [$one->id, $two->id],
        ])->assertRedirect();

        $this->assertNull(Category::query()->find($one->id));
        $this->assertNull(Category::query()->find($two->id));
    }

    public function test_category_bulk_duplicate_assigns_next_position(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $source = $this->createCategory('Dup Source');
        $expected = \App\Domain\Catalog\Support\PositionReorder::next(Category::class, 'parent_id', $source->parent_id);

        $this->post('/admin/categories/bulk-duplicate', [
            'ids' => [$source->id],
        ])->assertRedirect();

        $copy = Category::query()
            ->whereHas('translations', fn ($q) => $q->where('name', 'Dup Source (copy)'))
            ->first();
        $this->assertNotNull($copy);
        $this->assertFalse($copy->is_enabled);
        $this->assertSame($expected, (int) $copy->position);
        $this->assertSame($source->parent_id, $copy->parent_id);
    }

    public function test_category_import_sample_and_csv_import(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $response = $this->get('/admin/categories/import/sample?delimiter=;&multivalue_separator=,');
        $response->assertOk();
        $this->assertStringContainsString('categories-sample.csv', (string) $response->headers->get('content-disposition'));

        $csv = $response->streamedContent();
        $lines = preg_split('/\r\n|\r|\n/', trim($csv));
        $header = str_getcsv($lines[0], ';');
        $this->assertSame(\App\Domain\Catalog\Support\CategoryCsvSchema::columns(), $header);
        $this->assertGreaterThanOrEqual(8, count($lines) - 1);

        $service = app(\App\Domain\Catalog\Services\CategoryService::class);
        $count = $service->importCsv($csv, 'en', ';', ',');
        $this->assertSame(count(\App\Domain\Catalog\Support\CategoryCsvSchema::sampleCategories()), $count);

        $electronics = Category::query()
            ->whereHas('translations', fn ($q) => $q->where('slug', 'electronics'))
            ->first();
        $this->assertNotNull($electronics);
        $this->assertNull($electronics->parent_id);

        $laptops = Category::query()
            ->whereHas('translations', fn ($q) => $q->where('slug', 'laptops'))
            ->first();
        $this->assertNotNull($laptops);
        $computers = Category::query()
            ->whereHas('translations', fn ($q) => $q->where('slug', 'computers'))
            ->first();
        $this->assertNotNull($computers);
        $this->assertSame((int) $computers->id, (int) $laptops->parent_id);

        $fashion = Category::query()
            ->whereHas('translations', fn ($q) => $q->where('slug', 'fashion'))
            ->first();
        $this->assertNotNull($fashion);
        $this->assertFalse($fashion->is_enabled);

        $this->get('/admin/categories/export')->assertOk();
    }
}
