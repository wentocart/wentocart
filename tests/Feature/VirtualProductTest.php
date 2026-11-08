<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VirtualProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_virtual_products_skip_shipping_and_default_stock_off(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->get('/admin/products/create?type=virtual')->assertOk();

        $this->post('/admin/products', $this->withProductCategories([
            'type' => 'virtual',
            'name' => 'Windows License',
            'slug' => 'windows-license',
            'sku' => 'WIN-LIC',
            'price' => 49.99,
            'is_enabled' => true,
        ]))->assertProductSaveRedirect();

        $virtual = Product::query()->where('sku', 'WIN-LIC')->first();
        $this->assertNotNull($virtual);
        $this->assertSame('Windows License', $virtual->translation()?->name);
        $this->assertFalse((bool) $virtual->manage_stock);
        $this->assertNull($virtual->weight);
        $this->assertNull($virtual->length);

        $this->postJson('/admin/products', $this->withProductCategories([
            'type' => 'virtual',
            'name' => 'JSON License',
            'slug' => 'json-license',
            'sku' => 'JSON-LIC',
            'price' => 19.99,
            'manage_stock' => false,
            'is_enabled' => true,
        ]))->assertProductSaveRedirect();

        $this->assertFalse((bool) Product::query()->where('sku', 'JSON-LIC')->value('manage_stock'));

        $this->post('/admin/products', $this->withProductCategories([
            'type' => 'virtual',
            'name' => 'Seat License',
            'slug' => 'seat-license',
            'sku' => 'SEAT-LIC',
            'price' => 9.99,
            'manage_stock' => '0',
            'is_enabled' => true,
        ]))->assertProductSaveRedirect();

        $this->assertFalse((bool) Product::query()->where('sku', 'SEAT-LIC')->value('manage_stock'));

        $this->put('/admin/products/' . $virtual->id, $this->withProductCategories([
            'type' => 'virtual',
            'name' => 'Windows License',
            'slug' => 'windows-license',
            'sku' => 'WIN-LIC',
            'price' => 49.99,
            'manage_stock' => true,
            'stock_qty' => 25,
            'is_enabled' => true,
        ]))->assertRedirect('/admin/products/' . $virtual->id . '/edit');

        $virtual->refresh();
        $this->assertTrue((bool) $virtual->manage_stock);
        $this->assertSame(25, (int) $virtual->stock_qty);

        $this->get('/admin/products/'.$virtual->id.'/edit')->assertOk();

        $this->post('/admin/products', $this->withProductCategories([
            'type' => 'simple',
            'name' => 'USB Cable',
            'slug' => 'usb-cable',
            'sku' => 'USB-CABLE',
            'price' => 9.99,
            'weight' => 0.25,
            'manage_stock' => true,
            'stock_qty' => 12,
            'is_enabled' => true,
        ]))->assertProductSaveRedirect();

        $simple = Product::query()->where('sku', 'USB-CABLE')->first();
        $this->assertNotNull($simple);
        $this->assertTrue((bool) $simple->manage_stock);
        $this->assertEquals(0.25, (float) $simple->weight);

        $this->get('/admin/products/'.$simple->id.'/edit')->assertOk();
    }
}
