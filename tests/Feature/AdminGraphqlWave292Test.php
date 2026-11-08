<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\AttributeValue;
use App\Domain\Catalog\Models\CatalogAttribute;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Services\AttributeService;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave292Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_configurable_variant_axes_and_variants_rest(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));
        $attrCode = 'color'.$suffix;
        $attrs = app(AttributeService::class);

        $color = $attrs->saveAttribute([
            'code' => $attrCode,
            'type' => 'select',
            'is_enabled' => true,
            'position' => 1,
            'translations' => [
                'en' => ['name' => 'Color '.$suffix],
            ],
        ]);
        $red = $attrs->saveValue([
            'attribute_id' => $color->id,
            'code' => 'red'.$suffix,
            'is_enabled' => true,
            'position' => 1,
            'translations' => ['en' => ['value' => 'Red']],
        ]);
        $blue = $attrs->saveValue([
            'attribute_id' => $color->id,
            'code' => 'blue'.$suffix,
            'is_enabled' => true,
            'position' => 2,
            'translations' => ['en' => ['value' => 'Blue']],
        ]);

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/products', [
            'sku' => 'w292-cfg-'.$suffix,
            'name' => 'Wave292 Configurable '.$suffix,
            'price' => 25,
            'type' => 'configurable',
            'stock_qty' => 0,
            'status' => 1,
            'variant_axes' => [
                $attrCode => [$red->id, $blue->id],
            ],
            'variants' => [
                [
                    'attribute_value_ids' => [$red->id],
                    'sku' => 'w292-cfg-'.$suffix.'-red',
                    'price' => 25,
                    'stock_qty' => 3,
                    'manage_stock' => true,
                    'stock_status' => 'in_stock',
                    'is_enabled' => true,
                    'is_default' => true,
                ],
                [
                    'attribute_value_ids' => [$blue->id],
                    'sku' => 'w292-cfg-'.$suffix.'-blue',
                    'price' => 27,
                    'stock_qty' => 2,
                    'manage_stock' => true,
                    'stock_status' => 'in_stock',
                    'is_enabled' => true,
                    'is_default' => false,
                ],
            ],
        ]);
        $create->assertCreated();
        $productId = (int) $create->json('data.id');
        $product = Product::query()->findOrFail($productId);
        $this->assertSame('combination', $product->type);
        $this->assertSame(2, ProductVariant::query()->where('product_id', $productId)->count());

        $supers = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/products/'.$productId.'/variant-axes');
        $supers->assertOk();
        $this->assertNotEmpty($supers->json('data'));

        $variants = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/products/'.$productId.'/variants');
        $variants->assertOk();
        $this->assertCount(2, $variants->json('data') ?? []);

        $missing = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/products', [
            'sku' => 'w292-bad-'.$suffix,
            'name' => 'Wave292 Bad '.$suffix,
            'price' => 10,
            'type' => 'configurable',
            'status' => 1,
        ]);
        $missing->assertStatus(422);

        $this->assertInstanceOf(CatalogAttribute::class, $color);
        $this->assertInstanceOf(AttributeValue::class, $red);
    }
}
