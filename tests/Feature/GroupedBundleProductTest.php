<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductComponent;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroupedBundleProductTest extends TestCase
{
    use RefreshDatabase;

    protected function actingAsAdmin(): self
    {
        $this->seed();

        return $this->actingAs(Admin::query()->first(), 'admin');
    }

    protected function createSimple(string $sku, float $price, int $stock = 10): Product
    {
        $this->post('/admin/products', $this->withProductCategories([
            'type' => 'simple',
            'name' => $sku,
            'slug' => strtolower($sku),
            'sku' => $sku,
            'price' => $price,
            'manage_stock' => true,
            'stock_qty' => $stock,
            'is_enabled' => true,
        ]))->assertProductSaveRedirect();

        return Product::query()->where('sku', $sku)->firstOrFail();
    }

    public function test_grouped_product_create_edit_and_derived_inventory(): void
    {
        $this->actingAsAdmin();

        $shirt = $this->createSimple('GRP-SHIRT', 500, 10);
        $jeans = $this->createSimple('GRP-JEANS', 1200, 5);

        $this->get('/admin/products/create?type=group')->assertOk();

        $this->post('/admin/products', $this->withProductCategories([
            'type' => 'group',
            'name' => 'Fashion Collection',
            'slug' => 'fashion-collection',
            'sku' => 'FASH-GRP',
            'price' => 0,
            'selection_mode' => 'all',
            'pricing_mode' => 'sum_discount',
            'pricing_discount_type' => 'fixed',
            'pricing_discount_value' => 300,
            'inventory_mode' => 'derived',
            'shipping_mode' => 'derived',
            'is_enabled' => true,
            'components' => [
                ['id' => $shirt->id, 'quantity' => 1, 'qty_editable' => true, 'default_selected' => true],
                ['id' => $jeans->id, 'quantity' => 1, 'qty_editable' => false, 'default_selected' => true],
            ],
        ]))->assertProductSaveRedirect();

        $group = Product::query()->where('sku', 'FASH-GRP')->first();
        $this->assertNotNull($group);
        $this->assertSame('group', $group->type);
        $this->assertFalse((bool) $group->manage_stock);
        $this->assertSame('derived', $group->inventory_mode);
        $this->assertSame('derived', $group->shipping_mode);
        $this->assertSame(2, $group->components()->count());
        $this->assertSame(1400.0, $group->sellingPrice());
        $this->assertEquals(5, $group->availableStock());

        $this->get('/admin/products/'.$group->id.'/edit')->assertOk();

        $this->put('/admin/products/' . $group->id, $this->withProductCategories([
            'type' => 'group',
            'name' => 'Fashion Collection',
            'slug' => 'fashion-collection',
            'sku' => 'FASH-GRP',
            'price' => 0,
            'selection_mode' => 'customer',
            'pricing_mode' => 'sum',
            'inventory_mode' => 'derived',
            'shipping_mode' => 'derived',
            'is_enabled' => true,
            'components' => [
                ['id' => $shirt->id, 'quantity' => 1, 'qty_editable' => true, 'default_selected' => true],
            ],
        ]))->assertRedirect('/admin/products/' . $group->id . '/edit');

        $group->refresh();
        $this->assertSame('customer', $group->selection_mode);
        $this->assertSame(1, $group->components()->count());
        $this->assertSame(500.0, $group->sellingPrice());
        $this->assertEquals(10, $group->availableStock());

        $shirt->refresh();
        $this->assertTrue($shirt->exists);
    }

    public function test_bundle_product_fixed_price_and_component_inventory(): void
    {
        $this->actingAsAdmin();

        $mouse = $this->createSimple('BDL-MOUSE', 1000, 10);
        $keyboard = $this->createSimple('BDL-KEY', 2000, 5);
        $headset = $this->createSimple('BDL-HEAD', 1500, 8);

        $this->get('/admin/products/create?type=bundle')->assertOk();

        $this->post('/admin/products', $this->withProductCategories([
            'type' => 'bundle',
            'name' => 'Gaming Starter Bundle',
            'slug' => 'gaming-starter-bundle',
            'sku' => 'GAME-BDL',
            'price' => 3999,
            'bundle_type' => 'fixed',
            'pricing_mode' => 'fixed',
            'inventory_mode' => 'derived',
            'shipping_mode' => 'derived',
            'is_enabled' => true,
            'components' => [
                ['id' => $mouse->id, 'quantity' => 1, 'is_required' => true, 'default_selected' => true],
                ['id' => $keyboard->id, 'quantity' => 1, 'is_required' => true, 'default_selected' => true],
                ['id' => $headset->id, 'quantity' => 1, 'is_required' => true, 'default_selected' => true],
            ],
        ]))->assertProductSaveRedirect();

        $bundle = Product::query()->where('sku', 'GAME-BDL')->first();
        $this->assertNotNull($bundle);
        $this->assertSame('bundle', $bundle->type);
        $this->assertSame('fixed', $bundle->bundle_type);
        $this->assertSame(3999.0, $bundle->sellingPrice());
        $this->assertEquals(5, $bundle->availableStock());

        $this->put('/admin/products/' . $bundle->id, $this->withProductCategories([
            'type' => 'bundle',
            'name' => 'Gaming Starter Bundle',
            'slug' => 'gaming-starter-bundle',
            'sku' => 'GAME-BDL',
            'price' => 3999,
            'bundle_type' => 'configurable',
            'pricing_mode' => 'sum_discount',
            'pricing_discount_type' => 'percent',
            'pricing_discount_value' => 10,
            'inventory_mode' => 'derived',
            'shipping_mode' => 'derived',
            'is_enabled' => true,
            'components' => [
                ['id' => $mouse->id, 'quantity' => 2, 'is_required' => true, 'default_selected' => true],
                ['id' => $keyboard->id, 'quantity' => 1, 'is_required' => true, 'default_selected' => true],
                ['id' => $headset->id, 'quantity' => 1, 'is_required' => false, 'default_selected' => false, 'option_group' => 'Audio'],
            ],
        ]))->assertRedirect('/admin/products/' . $bundle->id . '/edit');

        $bundle->refresh();
        $this->assertSame('configurable', $bundle->bundle_type);
        // Catalog price for configurable = required + default_selected only (optional headset excluded).
        $this->assertSame(3600.0, $bundle->sellingPrice());
        $this->assertEquals(5, $bundle->availableStock());

        ProductComponent::query()
            ->where('parent_product_id', $bundle->id)
            ->where('child_product_id', $headset->id)
            ->delete();

        $headset->refresh();
        $this->assertTrue($headset->exists);
        $this->assertSame(2, ProductComponent::query()->where('parent_product_id', $bundle->id)->count());
    }

    public function test_composite_validation_rejects_invalid_relationships(): void
    {
        $this->actingAsAdmin();

        $child = $this->createSimple('VAL-CHILD', 10, 3);

        $this->post('/admin/products', $this->withProductCategories([
            'type' => 'group',
            'name' => 'Invalid Group',
            'slug' => 'invalid-group',
            'sku' => 'VAL-GRP',
            'price' => 0,
            'is_enabled' => true,
            'components' => [
                ['id' => $child->id, 'quantity' => 1],
                ['id' => $child->id, 'quantity' => 1],
            ],
        ]))->assertSessionHasErrors();

        $this->post('/admin/products', $this->withProductCategories([
            'type' => 'bundle',
            'name' => 'Empty Bundle',
            'slug' => 'empty-bundle',
            'sku' => 'VAL-BDL',
            'price' => 10,
            'is_enabled' => true,
            'components' => [],
        ]))->assertSessionHasErrors('components');
    }

    public function test_simple_and_virtual_products_remain_unchanged(): void
    {
        $this->actingAsAdmin();

        $this->post('/admin/products', $this->withProductCategories([
            'type' => 'simple',
            'name' => 'Simple Widget',
            'slug' => 'simple-widget',
            'sku' => 'SIMPLE-1',
            'price' => 19.99,
            'weight' => 0.5,
            'manage_stock' => true,
            'stock_qty' => 12,
            'is_enabled' => true,
        ]))->assertProductSaveRedirect();

        $simple = Product::query()->where('sku', 'SIMPLE-1')->first();
        $this->assertTrue((bool) $simple->manage_stock);
        $this->assertEquals(0.5, (float) $simple->weight);

        $this->post('/admin/products', $this->withProductCategories([
            'type' => 'virtual',
            'name' => 'Virtual License',
            'slug' => 'virtual-license',
            'sku' => 'VIRT-1',
            'price' => 9.99,
            'is_enabled' => true,
        ]))->assertProductSaveRedirect();

        $virtual = Product::query()->where('sku', 'VIRT-1')->first();
        $this->assertFalse((bool) $virtual->manage_stock);

        $this->get('/admin/products/create?type=simple')->assertOk();
        $this->get('/admin/products/create?type=virtual')->assertOk();
    }
}
