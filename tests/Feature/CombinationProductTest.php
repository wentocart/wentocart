<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\AttributeValue;
use App\Domain\Catalog\Models\CatalogAttribute;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CombinationProductTest extends TestCase
{
    use RefreshDatabase;

    protected function seedAttributes(): array
    {
        $this->post('/admin/attributes', [
            'name' => 'Color',
            'code' => 'color',
            'type' => 'select',
            'is_enabled' => true,
            'position' => 1,
        ])->assertRedirect();

        $this->post('/admin/attributes', [
            'name' => 'Size',
            'code' => 'size',
            'type' => 'select',
            'is_enabled' => true,
            'position' => 2,
        ])->assertRedirect();

        $color = CatalogAttribute::query()->where('code', 'color')->firstOrFail();
        $size = CatalogAttribute::query()->where('code', 'size')->firstOrFail();

        foreach (['Black' => 'black', 'White' => 'white'] as $label => $code) {
            $this->post('/admin/attributes/values', [
                'attribute_id' => $color->id,
                'value' => $label,
                'code' => $code,
                'is_enabled' => true,
                'position' => 1,
            ])->assertRedirect();
        }

        foreach (['S' => 's', 'M' => 'm', 'L' => 'l'] as $label => $code) {
            $this->post('/admin/attributes/values', [
                'attribute_id' => $size->id,
                'value' => $label,
                'code' => $code,
                'is_enabled' => true,
                'position' => 1,
            ])->assertRedirect();
        }

        $colorValues = AttributeValue::query()->where('attribute_id', $color->id)->orderBy('id')->get();
        $sizeValues = AttributeValue::query()->where('attribute_id', $size->id)->orderBy('id')->get();

        return compact('color', 'size', 'colorValues', 'sizeValues');
    }

    protected function createCombinationProduct(array $attrs, array $variantOverrides = []): Product
    {
        $black = $attrs['colorValues'][0];
        $white = $attrs['colorValues'][1];
        $small = $attrs['sizeValues'][0];
        $medium = $attrs['sizeValues'][1];

        $variants = [
            [
                'attribute_value_ids' => [$black->id, $small->id],
                'sku' => 'TSHIRT-BS',
                'barcode' => '111',
                'price' => 19.99,
                'sale_price' => 17.99,
                'cost_price' => 10.00,
                'stock_qty' => 5,
                'stock_status' => 'in_stock',
                'is_enabled' => true,
                'is_default' => true,
            ],
            [
                'attribute_value_ids' => [$black->id, $medium->id],
                'sku' => 'TSHIRT-BM',
                'barcode' => '222',
                'price' => 21.99,
                'sale_price' => 19.99,
                'cost_price' => 11.00,
                'stock_qty' => 3,
                'stock_status' => 'in_stock',
                'is_enabled' => true,
                'is_default' => false,
            ],
            [
                'attribute_value_ids' => [$white->id, $small->id],
                'sku' => 'TSHIRT-WS',
                'price' => 19.99,
                'stock_qty' => 2,
                'is_enabled' => true,
                'is_default' => false,
            ],
            [
                'attribute_value_ids' => [$white->id, $medium->id],
                'sku' => 'TSHIRT-WM',
                'price' => 21.99,
                'stock_qty' => 1,
                'is_enabled' => true,
                'is_default' => false,
            ],
        ];

        foreach ($variantOverrides as $index => $override) {
            $variants[$index] = array_merge($variants[$index], $override);
        }

        $this->post('/admin/products', $this->withProductCategories([
            'type' => 'combination',
            'sku' => 'TSHIRT-COMBO',
            'name' => 'T-Shirt',
            'price' => 19.99,
            'stock_qty' => 0,
            'is_enabled' => true,
            'combination_axes' => [
                ['attribute_id' => $attrs['color']->id, 'value_ids' => [$black->id, $white->id]],
                ['attribute_id' => $attrs['size']->id, 'value_ids' => [$small->id, $medium->id]],
            ],
            'combination_variants' => $variants,
        ]))->assertProductSaveRedirect();

        return Product::query()->where('sku', 'TSHIRT-COMBO')->firstOrFail();
    }

    public function test_combination_product_generates_and_preserves_variants(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $attrs = $this->seedAttributes();
        $product = $this->createCombinationProduct($attrs);
        $black = $attrs['colorValues'][0];
        $white = $attrs['colorValues'][1];
        $small = $attrs['sizeValues'][0];
        $medium = $attrs['sizeValues'][1];

        $this->assertSame('combination', $product->type);
        $this->assertSame(4, $product->variants()->count());
        $this->assertSame(11, (int) $product->fresh()->stock_qty);

        $existing = ProductVariant::query()->where('product_id', $product->id)->where('sku', 'TSHIRT-BM')->firstOrFail();
        $this->assertSame('21.99', (string) $existing->price);
        $this->assertSame('222', $existing->barcode);
        $this->assertSame(3, (int) $existing->stock_qty);

        $large = $attrs['sizeValues'][2];
        $this->put('/admin/products/' . $product->id, $this->withProductCategories([
            'type' => 'combination',
            'sku' => 'TSHIRT-COMBO',
            'name' => 'T-Shirt',
            'price' => 19.99,
            'stock_qty' => 0,
            'is_enabled' => true,
            'combination_axes' => [
                ['attribute_id' => $attrs['color']->id, 'value_ids' => [$black->id, $white->id]],
                ['attribute_id' => $attrs['size']->id, 'value_ids' => [$small->id, $medium->id, $large->id]],
            ],
            'combination_variants' => [
                [
                    'id' => $existing->id,
                    'attribute_value_ids' => [$black->id, $medium->id],
                    'sku' => 'TSHIRT-BM',
                    'price' => 22.99,
                    'sale_price' => 20.99,
                    'cost_price' => 12.50,
                    'stock_qty' => 8,
                    'cover_path' => 'catalog/products/demo/black-m.jpg',
                    'is_enabled' => true,
                    'is_default' => true,
                ],
            ],
        ]))->assertRedirect('/admin/products/' . $product->id . '/edit');

        $existing->refresh();
        $this->assertSame('22.99', (string) $existing->price);
        $this->assertSame('20.99', (string) $existing->sale_price);
        $this->assertSame('12.50', (string) $existing->cost_price);
        $this->assertSame(8, (int) $existing->stock_qty);
        $this->assertTrue($existing->is_default);
        $this->assertSame('catalog/products/demo/black-m.jpg', $existing->cover_path);
        $this->assertSame(6, $product->fresh()->variants()->count());

        $this->post('/admin/attributes/'.$attrs['color']->id.'/toggle')->assertRedirect();
        $this->from('/admin/products/'.$product->id.'/edit')->put('/admin/products/' . $product->id, $this->withProductCategories([
            'type' => 'combination',
            'sku' => 'TSHIRT-COMBO',
            'name' => 'T-Shirt',
            'price' => 19.99,
            'stock_qty' => 0,
            'is_enabled' => true,
            'combination_axes' => [
                ['attribute_id' => $attrs['color']->id, 'value_ids' => [$black->id, $white->id]],
                ['attribute_id' => $attrs['size']->id, 'value_ids' => [$small->id, $medium->id, $large->id]],
            ],
            'combination_variants' => [],
        ]))->assertRedirect('/admin/products/' . $product->id . '/edit');

        $this->assertSame(6, $product->fresh()->variants()->count());
        $this->assertSame('22.99', (string) $existing->fresh()->price);
    }

    public function test_combination_variant_validates_duplicate_sku_and_default_rules(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $attrs = $this->seedAttributes();
        $product = $this->createCombinationProduct($attrs);
        $black = $attrs['colorValues'][0];
        $white = $attrs['colorValues'][1];
        $small = $attrs['sizeValues'][0];
        $medium = $attrs['sizeValues'][1];

        $this->from('/admin/products/'.$product->id.'/edit')->put('/admin/products/' . $product->id, $this->withProductCategories([
            'type' => 'combination',
            'sku' => 'TSHIRT-COMBO',
            'name' => 'T-Shirt',
            'price' => 19.99,
            'stock_qty' => 0,
            'is_enabled' => true,
            'combination_axes' => [
                ['attribute_id' => $attrs['color']->id, 'value_ids' => [$black->id, $white->id]],
                ['attribute_id' => $attrs['size']->id, 'value_ids' => [$small->id, $medium->id]],
            ],
            'combination_variants' => [
                [
                    'attribute_value_ids' => [$black->id, $small->id],
                    'sku' => 'TSHIRT-BM-DUP',
                    'price' => 19.99,
                    'is_enabled' => true,
                    'is_default' => true,
                ],
                [
                    'attribute_value_ids' => [$black->id, $medium->id],
                    'sku' => 'TSHIRT-BM-DUP',
                    'price' => 21.99,
                    'is_enabled' => true,
                    'is_default' => true,
                ],
            ],
        ]))->assertSessionHasErrors(['combination_variants.1.sku']);

        $variant = ProductVariant::query()->where('product_id', $product->id)->where('sku', 'TSHIRT-WS')->firstOrFail();
        $first = ProductVariant::query()->where('product_id', $product->id)->where('sku', 'TSHIRT-BS')->firstOrFail();
        $this->from('/admin/products/'.$product->id.'/edit')->put('/admin/products/'.$product->id, $this->withProductCategories([
            'type' => 'combination',
            'sku' => 'TSHIRT-COMBO',
            'name' => 'T-Shirt',
            'price' => 19.99,
            'stock_qty' => 0,
            'is_enabled' => true,
            'combination_axes' => [
                ['attribute_id' => $attrs['color']->id, 'value_ids' => [$black->id, $white->id]],
                ['attribute_id' => $attrs['size']->id, 'value_ids' => [$small->id, $medium->id]],
            ],
            'combination_variants' => [
                [
                    'id' => $first->id,
                    'attribute_value_ids' => [$black->id, $small->id],
                    'sku' => 'TSHIRT-BS',
                    'price' => 19.99,
                    'is_enabled' => true,
                    'is_default' => true,
                ],
                [
                    'id' => $variant->id,
                    'attribute_value_ids' => [$white->id, $small->id],
                    'sku' => 'TSHIRT-WS',
                    'price' => 19.99,
                    'is_enabled' => true,
                    'is_default' => true,
                ],
            ],
        ]))->assertSessionHasErrors(['combination_variants']);

        $this->from('/admin/products/'.$product->id.'/edit')->put('/admin/products/'.$product->id, $this->withProductCategories([
            'type' => 'combination',
            'sku' => 'TSHIRT-COMBO',
            'name' => 'T-Shirt',
            'price' => 19.99,
            'stock_qty' => 0,
            'is_enabled' => true,
            'combination_axes' => [
                ['attribute_id' => $attrs['color']->id, 'value_ids' => [$black->id, $white->id]],
                ['attribute_id' => $attrs['size']->id, 'value_ids' => [$small->id, $medium->id]],
            ],
            'combination_variants' => [
                [
                    'id' => $variant->id,
                    'attribute_value_ids' => [$white->id, $small->id],
                    'sku' => 'TSHIRT-WS',
                    'price' => 19.99,
                    'is_enabled' => false,
                    'is_default' => true,
                ],
            ],
        ]))->assertSessionHasErrors(['combination_variants.0.is_default']);
    }

    public function test_combination_variant_delete_is_blocked_when_used_by_orders(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $attrs = $this->seedAttributes();
        $product = $this->createCombinationProduct($attrs);
        $variant = ProductVariant::query()->where('product_id', $product->id)->where('sku', 'TSHIRT-BM')->firstOrFail();

        $order = Order::createTrusted([
            'number' => 'ORD-1001',
            'email' => 'buyer@example.com',
            'status' => 'pending',
            'currency_code' => 'USD',
            'subtotal' => 21.99,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'grand_total' => 21.99,
            'payment_status' => 'pending',
            'billing_address' => ['line1' => '1 Main'],
            'shipping_address' => ['line1' => '1 Main'],
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'sku' => $variant->sku,
            'name' => 'T-Shirt Black / M',
            'qty' => 1,
            'unit_price' => 21.99,
            'total' => 21.99,
        ]);

        $black = $attrs['colorValues'][0];
        $white = $attrs['colorValues'][1];
        $small = $attrs['sizeValues'][0];
        $medium = $attrs['sizeValues'][1];

        $this->from('/admin/products/'.$product->id.'/edit')->put('/admin/products/'.$product->id, $this->withProductCategories([
            'type' => 'combination',
            'sku' => 'TSHIRT-COMBO',
            'name' => 'T-Shirt',
            'price' => 19.99,
            'stock_qty' => 0,
            'is_enabled' => true,
            'combination_axes' => [
                ['attribute_id' => $attrs['color']->id, 'value_ids' => [$black->id, $white->id]],
                ['attribute_id' => $attrs['size']->id, 'value_ids' => [$small->id, $medium->id]],
            ],
            'combination_variants' => [],
            'deleted_combination_variant_ids' => [$variant->id],
        ]))->assertSessionHasErrors(['deleted_combination_variant_ids']);

        $this->assertNotNull(ProductVariant::query()->find($variant->id));
    }

    public function test_combination_variant_can_be_deleted_when_unused(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $attrs = $this->seedAttributes();
        $product = $this->createCombinationProduct($attrs);
        $variant = ProductVariant::query()->where('product_id', $product->id)->where('sku', 'TSHIRT-WM')->firstOrFail();
        $black = $attrs['colorValues'][0];
        $white = $attrs['colorValues'][1];
        $small = $attrs['sizeValues'][0];
        $medium = $attrs['sizeValues'][1];

        $remaining = ProductVariant::query()
            ->where('product_id', $product->id)
            ->where('sku', '!=', 'TSHIRT-WM')
            ->get()
            ->map(fn (ProductVariant $row) => [
                'id' => $row->id,
                'attribute_value_ids' => $row->attributeValues()->pluck('attribute_values.id')->all(),
                'sku' => $row->sku,
                'price' => $row->price,
                'stock_qty' => $row->stock_qty,
                'is_enabled' => $row->is_enabled,
                'is_default' => $row->is_default,
            ])
            ->values()
            ->all();

        $this->put('/admin/products/'.$product->id, $this->withProductCategories([
            'type' => 'combination',
            'sku' => 'TSHIRT-COMBO',
            'name' => 'T-Shirt',
            'price' => 19.99,
            'stock_qty' => 0,
            'is_enabled' => true,
            'combination_axes' => [
                ['attribute_id' => $attrs['color']->id, 'value_ids' => [$black->id, $white->id]],
                ['attribute_id' => $attrs['size']->id, 'value_ids' => [$small->id, $medium->id]],
            ],
            'combination_variants' => $remaining,
            'deleted_combination_variant_ids' => [$variant->id],
        ]))->assertRedirect('/admin/products/' . $product->id . '/edit');

        $this->assertNull(ProductVariant::query()->find($variant->id));
        $this->assertSame(3, $product->fresh()->variants()->count());
    }

    public function test_combination_variant_can_reference_product_media_image(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $attrs = $this->seedAttributes();
        $product = $this->createCombinationProduct($attrs);
        $black = $attrs['colorValues'][0];
        $white = $attrs['colorValues'][1];
        $small = $attrs['sizeValues'][0];
        $medium = $attrs['sizeValues'][1];

        $image = $product->images()->create([
            'path' => 'catalog/products/'.$product->id.'/combo-cover.jpg',
            'alt' => 'Combo cover',
            'position' => 1,
        ]);

        $variant = ProductVariant::query()
            ->where('product_id', $product->id)
            ->where('sku', 'TSHIRT-BS')
            ->firstOrFail();

        $this->put('/admin/products/' . $product->id, $this->withProductCategories([
            'type' => 'combination',
            'sku' => 'TSHIRT-COMBO',
            'name' => 'T-Shirt',
            'price' => 19.99,
            'stock_qty' => 0,
            'is_enabled' => true,
            'combination_axes' => [
                ['attribute_id' => $attrs['color']->id, 'value_ids' => [$black->id, $white->id]],
                ['attribute_id' => $attrs['size']->id, 'value_ids' => [$small->id, $medium->id]],
            ],
            'combination_variants' => [
                [
                    'id' => $variant->id,
                    'attribute_value_ids' => [$black->id, $small->id],
                    'sku' => 'TSHIRT-BS',
                    'price' => 19.99,
                    'stock_qty' => 5,
                    'cover_image_id' => $image->id,
                    'is_enabled' => true,
                    'is_default' => true,
                ],
            ],
        ]))->assertRedirect('/admin/products/' . $product->id . '/edit');

        $variant->refresh();
        $this->assertSame($image->id, (int) $variant->cover_image_id);
        $this->assertSame($image->path, $variant->cover_path);

        $presented = app(\App\Domain\Catalog\Services\CombinationService::class)->presentVariants($product->fresh());
        $row = collect($presented)->firstWhere('id', $variant->id);
        $this->assertSame($image->id, $row['cover_image_id']);
        $this->assertSame('/storage/'.$image->path, $row['cover_url']);

        $this->put('/admin/products/' . $product->id, $this->withProductCategories([
            'type' => 'combination',
            'sku' => 'TSHIRT-COMBO',
            'name' => 'T-Shirt',
            'price' => 19.99,
            'stock_qty' => 0,
            'is_enabled' => true,
            'combination_axes' => [
                ['attribute_id' => $attrs['color']->id, 'value_ids' => [$black->id, $white->id]],
                ['attribute_id' => $attrs['size']->id, 'value_ids' => [$small->id, $medium->id]],
            ],
            'combination_variants' => [
                [
                    'id' => $variant->id,
                    'attribute_value_ids' => [$black->id, $small->id],
                    'sku' => 'TSHIRT-BS',
                    'price' => 19.99,
                    'stock_qty' => 5,
                    'cover_image_id' => null,
                    'is_enabled' => true,
                    'is_default' => true,
                ],
            ],
        ]))->assertRedirect('/admin/products/' . $product->id . '/edit');

        $variant->refresh();
        $this->assertNull($variant->cover_image_id);
        $this->assertNull($variant->cover_path);

        $image->delete();
        $this->assertDatabaseMissing('product_variants', [
            'id' => $variant->id,
            'cover_image_id' => $image->id,
        ]);
    }

    public function test_storefront_product_exposes_combination_options(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $attrs = $this->seedAttributes();
        $product = $this->createCombinationProduct($attrs, [
            3 => ['stock_qty' => 0, 'stock_status' => 'out_of_stock'],
        ]);
        $slug = $product->translation('en')?->slug;

        $this->getJson('/api/v1/catalog/product/'.$slug)
            ->assertOk()
            ->assertJsonPath('product.type', 'combination')
            ->assertJsonCount(2, 'product.combination_axes')
            ->assertJsonCount(4, 'product.combination_variants')
            ->assertJsonPath('product.combination_axes.0.code', 'color')
            ->assertJsonPath('product.combination_variants.3.stock_status', 'out_of_stock');

        $simple = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $this->getJson('/api/v1/catalog/product/'.$simple->translation('en')?->slug)
            ->assertOk()
            ->assertJsonPath('product.combination_axes', [])
            ->assertJsonPath('product.combination_variants', []);
    }

    public function test_empty_combination_sku_is_generated_from_parent_and_values(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $attrs = $this->seedAttributes();
        $black = $attrs['colorValues'][0];
        $small = $attrs['sizeValues'][0];

        $this->post('/admin/products', $this->withProductCategories([
            'type' => 'combination',
            'sku' => 'TSHIRT-AUTO',
            'name' => 'Auto SKU Shirt',
            'price' => 19.99,
            'stock_qty' => 0,
            'is_enabled' => true,
            'combination_axes' => [
                ['attribute_id' => $attrs['color']->id, 'value_ids' => [$black->id]],
                ['attribute_id' => $attrs['size']->id, 'value_ids' => [$small->id]],
            ],
            'combination_variants' => [[
                'attribute_value_ids' => [$black->id, $small->id],
                'sku' => '',
                'price' => 19.99,
                'stock_qty' => 2,
                'is_enabled' => true,
                'is_default' => true,
            ]],
        ]))->assertProductSaveRedirect();

        $product = Product::query()->where('sku', 'TSHIRT-AUTO')->firstOrFail();
        $variant = $product->variants()->firstOrFail();
        $this->assertSame('TSHIRT-AUTO-BLACK-S', $variant->sku);
    }
}
