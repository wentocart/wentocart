<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Warehouse;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave280Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_admin_catalog_products_rest_tree(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));
        $category = Category::query()->where('is_enabled', true)->orderBy('id')->first();
        $this->assertNotNull($category);

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/products?per_page=5');
        $list->assertOk();
        $this->assertIsArray($list->json('data'));

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/products', [
            'sku' => 'W280-'.$suffix,
            'name' => 'Wave280 Product '.$suffix,
            'price' => 19.5,
            'stock_qty' => 7,
            'category_id' => $category->id,
            'status' => 1,
        ]);
        $create->assertCreated();
        $productId = (int) $create->json('data.id');
        $this->assertGreaterThan(0, $productId);
        $this->assertSame('W280-'.$suffix, (string) $create->json('data.sku'));

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/products/'.$productId);
        $show->assertOk();
        $this->assertSame($productId, (int) $show->json('data.id'));

        $update = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/products/'.$productId, [
            'name' => 'Wave280 Renamed '.$suffix,
            'price' => 21,
            'status' => 1,
        ]);
        $update->assertOk();
        $this->assertSame(21.0, (float) $update->json('data.price'));

        $warehouse = Warehouse::query()->where('is_enabled', true)->orderBy('id')->first();
        if ($warehouse) {
            $inv = $this->actingAs($admin, 'admin')->postJson(
                '/api/v1/console/products/'.$productId.'/inventories',
                ['inventories' => [(string) $warehouse->id => 42]]
            );
            $inv->assertOk();
            $this->assertNotEmpty($inv->json('data'));
        }

        $mass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/products/bulk-update-status', [
            'ids' => [$productId],
            'value' => 0,
        ]);
        $mass->assertOk();
        $this->assertFalse((bool) Product::query()->find($productId)?->is_enabled);

        $this->actingAs($admin, 'admin')->getJson('/api/v1/console/categories')->assertOk();
        $this->actingAs($admin, 'admin')->getJson('/api/v1/console/categories/'.$category->id)->assertOk();

        $this->actingAs($admin, 'admin')->getJson('/api/v1/console/cms')->assertOk();

        $destroy = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/products/bulk-delete', [
            'ids' => [$productId],
        ]);
        $destroy->assertOk();
        $this->assertNull(Product::query()->find($productId));
    }
}
