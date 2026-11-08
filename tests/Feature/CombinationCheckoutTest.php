<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\AttributeValue;
use App\Domain\Catalog\Models\CatalogAttribute;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Checkout\Services\CartService;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Settings\ShopSetting;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cart → checkout → order line fidelity and variant-level inventory for combinations.
 */
class CombinationCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function seedCombo(): array
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
        ShopSetting::put('inventory.enabled', true);
        ShopSetting::put('inventory.allow_negative', false);

        $this->post('/admin/attributes', [
            'name' => 'Color', 'code' => 'color', 'type' => 'select', 'is_enabled' => true, 'position' => 1,
        ])->assertRedirect();
        $this->post('/admin/attributes', [
            'name' => 'Size', 'code' => 'size', 'type' => 'select', 'is_enabled' => true, 'position' => 2,
        ])->assertRedirect();

        $color = CatalogAttribute::query()->where('code', 'color')->firstOrFail();
        $size = CatalogAttribute::query()->where('code', 'size')->firstOrFail();

        foreach (['Black' => 'black', 'White' => 'white'] as $label => $code) {
            $this->post('/admin/attributes/values', [
                'attribute_id' => $color->id, 'value' => $label, 'code' => $code, 'is_enabled' => true, 'position' => 1,
            ])->assertRedirect();
        }
        foreach (['S' => 's', 'M' => 'm'] as $label => $code) {
            $this->post('/admin/attributes/values', [
                'attribute_id' => $size->id, 'value' => $label, 'code' => $code, 'is_enabled' => true, 'position' => 1,
            ])->assertRedirect();
        }

        $black = AttributeValue::query()->where('attribute_id', $color->id)->where('code', 'black')->firstOrFail();
        $white = AttributeValue::query()->where('attribute_id', $color->id)->where('code', 'white')->firstOrFail();
        $small = AttributeValue::query()->where('attribute_id', $size->id)->where('code', 's')->firstOrFail();
        $medium = AttributeValue::query()->where('attribute_id', $size->id)->where('code', 'm')->firstOrFail();

        $this->post('/admin/products', $this->withProductCategories([
            'type' => 'combination',
            'sku' => 'COMBO-TEE',
            'name' => 'Combo Tee',
            'price' => 20,
            'stock_qty' => 0,
            'manage_stock' => true,
            'is_enabled' => true,
            'available_for_order' => true,
            'combination_axes' => [
                ['attribute_id' => $color->id, 'value_ids' => [$black->id, $white->id]],
                ['attribute_id' => $size->id, 'value_ids' => [$small->id, $medium->id]],
            ],
            'combination_variants' => [
                [
                    'attribute_value_ids' => [$black->id, $small->id],
                    'sku' => 'COMBO-TEE-BS',
                    'price' => 19.99,
                    'sale_price' => 14.99,
                    'sale_price_type' => 'fixed',
                    'stock_qty' => 2,
                    'manage_stock' => true,
                    'stock_status' => 'in_stock',
                    'is_enabled' => true,
                    'is_default' => true,
                ],
                [
                    'attribute_value_ids' => [$black->id, $medium->id],
                    'sku' => 'COMBO-TEE-BM',
                    'price' => 21.99,
                    'stock_qty' => 0,
                    'manage_stock' => true,
                    'stock_status' => 'out_of_stock',
                    'is_enabled' => true,
                    'is_default' => false,
                ],
                [
                    'attribute_value_ids' => [$white->id, $small->id],
                    'sku' => 'COMBO-TEE-WS',
                    'price' => 19.99,
                    'stock_qty' => 5,
                    'manage_stock' => true,
                    'stock_status' => 'in_stock',
                    'is_enabled' => true,
                    'is_default' => false,
                ],
                [
                    'attribute_value_ids' => [$white->id, $medium->id],
                    'sku' => 'COMBO-TEE-WM',
                    'price' => 21.99,
                    'stock_qty' => 3,
                    'manage_stock' => true,
                    'stock_status' => 'in_stock',
                    'is_enabled' => true,
                    'is_default' => false,
                ],
            ],
        ]))->assertProductSaveRedirect();

        $product = Product::query()->where('sku', 'COMBO-TEE')->firstOrFail();
        $bs = ProductVariant::query()->where('sku', 'COMBO-TEE-BS')->firstOrFail();
        $bm = ProductVariant::query()->where('sku', 'COMBO-TEE-BM')->firstOrFail();

        return compact('product', 'bs', 'bm');
    }

    public function test_cart_requires_combination_id_for_combination_products(): void
    {
        ['product' => $product] = $this->seedCombo();

        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['combination_id']);
    }

    public function test_out_of_stock_variant_cannot_be_added_to_cart(): void
    {
        ['product' => $product, 'bm' => $bm] = $this->seedCombo();

        $this->postJson('/api/v1/cart', [
            'product_id' => $product->id,
            'qty' => 1,
            'combination_id' => $bm->id,
        ])->assertStatus(422);
    }

    public function test_checkout_writes_variant_sku_label_and_deducts_variant_stock(): void
    {
        ['product' => $product, 'bs' => $bs] = $this->seedCombo();
        $payment = PaymentMethod::query()->firstOrFail();
        $shipping = ShippingMethod::query()->firstOrFail();

        $this->postJson('/api/v1/cart', [
            'product_id' => $product->id,
            'qty' => 1,
            'combination_id' => $bs->id,
        ])->assertOk();

        $this->postJson('/api/v1/checkout', [
            'email' => 'combo@example.test',
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ])->assertCreated();

        $line = OrderItem::query()->where('combination_id', $bs->id)->first();
        $this->assertNotNull($line);
        $this->assertSame('COMBO-TEE-BS', $line->sku);
        $this->assertStringContainsString('Black', (string) $line->name);
        $this->assertStringContainsString('S', (string) $line->name);
        $this->assertEqualsWithDelta(14.99, (float) $line->unit_price, 0.01);

        $this->assertSame(1.0, (float) $bs->fresh()->stock_qty);
        $this->assertSame(9.0, (float) $product->fresh()->stock_qty);
    }

    public function test_overselling_variant_stock_is_blocked_at_cart(): void
    {
        ['product' => $product, 'bs' => $bs] = $this->seedCombo();

        $this->postJson('/api/v1/cart', [
            'product_id' => $product->id,
            'qty' => 3,
            'combination_id' => $bs->id,
        ])->assertStatus(422);
    }

    public function test_storefront_payload_includes_selling_price(): void
    {
        ['product' => $product] = $this->seedCombo();
        $slug = $product->translation('en')?->slug;

        $this->getJson('/api/v1/catalog/product/'.$slug)
            ->assertOk()
            ->assertJsonPath('product.combination_variants.0.selling_price', 14.99)
            ->assertJsonPath('product.combination_variants.0.sale_price', '14.99');
    }

    public function test_variant_delete_blocked_by_combination_id_on_order(): void
    {
        ['product' => $product, 'bs' => $bs] = $this->seedCombo();
        $payment = PaymentMethod::query()->firstOrFail();
        $shipping = ShippingMethod::query()->firstOrFail();

        $this->postJson('/api/v1/cart', [
            'product_id' => $product->id,
            'qty' => 1,
            'combination_id' => $bs->id,
        ])->assertOk();
        $this->postJson('/api/v1/checkout', [
            'email' => 'combo-del@example.test',
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ])->assertCreated();

        // Clear SKU so only combination_id can protect the row.
        $bs->forceFill(['sku' => 'RENAMED-AFTER-ORDER'])->save();

        $remaining = ProductVariant::query()
            ->where('product_id', $product->id)
            ->where('id', '!=', $bs->id)
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

        $axes = app(\App\Domain\Catalog\Services\CombinationService::class)->presentConfig($product);

        $this->from('/admin/products/'.$product->id.'/edit')->put('/admin/products/'.$product->id, $this->withProductCategories([
            'type' => 'combination',
            'sku' => 'COMBO-TEE',
            'name' => 'Combo Tee',
            'price' => 20,
            'stock_qty' => 0,
            'manage_stock' => true,
            'is_enabled' => true,
            'combination_axes' => collect($axes)->map(fn ($axis) => [
                'attribute_id' => $axis['attribute_id'],
                'value_ids' => $axis['value_ids'],
            ])->all(),
            'combination_variants' => $remaining,
            'deleted_combination_variant_ids' => [$bs->id],
        ]))->assertSessionHasErrors(['deleted_combination_variant_ids']);

        $this->assertNotNull(ProductVariant::query()->find($bs->id));
    }

    public function test_simple_product_cart_still_works_without_combination_id(): void
    {
        $this->seed();
        $simple = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $this->postJson('/api/v1/cart', ['product_id' => $simple->id, 'qty' => 1])->assertOk();
    }
}
