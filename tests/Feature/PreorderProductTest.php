<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\AttributeValue;
use App\Domain\Catalog\Models\CatalogAttribute;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPreorderSetting;
use App\Domain\Catalog\Services\PreorderService;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreorderProductTest extends TestCase
{
    use RefreshDatabase;

    protected function preorderPayload(array $overrides = []): array
    {
        return array_merge([
            'start_at' => '2026-10-01T09:00',
            'end_at' => '2026-10-20T18:00',
            'release_at' => '2026-10-15T00:00',
            'fulfillment_at' => '2026-10-16T00:00',
            'expected_delivery_at' => '2026-10-20T00:00',
            'payment_type' => 'full',
            'min_quantity' => 1,
            'max_quantity' => 5,
            'total_limit' => 500,
            'message' => 'Available for pre-order.',
            'show_badge' => true,
            'show_release_date' => true,
            'show_delivery_date' => true,
            'fulfillment_rule' => 'auto',
            'preorder_price' => 9000,
            'preorder_price_valid_until' => '2026-10-14T23:59',
        ], $overrides);
    }

    protected function seedCombinationAttributes(): array
    {
        // AttributeController ignores client `code`; slug is derived from name.
        $this->post('/admin/attributes', [
            'name' => 'Color',
            'type' => 'select',
            'is_enabled' => true,
            'position' => 1,
        ])->assertRedirect('/admin/attributes');

        $this->post('/admin/attributes', [
            'name' => 'Size',
            'type' => 'select',
            'is_enabled' => true,
            'position' => 2,
        ])->assertRedirect('/admin/attributes');

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

        foreach (['S' => 's', 'M' => 'm'] as $label => $code) {
            $this->post('/admin/attributes/values', [
                'attribute_id' => $size->id,
                'value' => $label,
                'code' => $code,
                'is_enabled' => true,
                'position' => 1,
            ])->assertRedirect();
        }

        return [
            'color' => $color,
            'size' => $size,
            'colorValues' => AttributeValue::query()->where('attribute_id', $color->id)->orderBy('id')->get(),
            'sizeValues' => AttributeValue::query()->where('attribute_id', $size->id)->orderBy('id')->get(),
        ];
    }

    public function test_standard_product_with_preorder_persists_configuration(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->post('/admin/products', $this->withProductCategories([
            'type' => 'simple',
            'name' => 'iPhone XYZ',
            'slug' => 'iphone-xyz',
            'sku' => 'IPHONE-XYZ',
            'price' => 10000,
            'manage_stock' => true,
            'stock_qty' => 0,
            'weight' => 0.2,
            'is_enabled' => true,
            'preorder_enabled' => true,
            'preorder' => $this->preorderPayload(),
        ]))->assertProductSaveRedirect();

        $product = Product::query()->where('sku', 'IPHONE-XYZ')->firstOrFail();
        $this->assertSame('simple', $product->type);
        $this->assertTrue($product->preorder_enabled);
        $this->assertTrue((bool) $product->manage_stock);
        $this->assertSame(0, (int) $product->stock_qty);

        $config = app(PreorderService::class)->presentConfig($product);
        $this->assertNotNull($config);
        $this->assertSame('Available for pre-order.', $config['message']);
        $this->assertEquals(9000.0, $config['preorder_price']);
        $this->assertSame(500, $config['total_limit']);
        $this->assertSame(500, $config['orders_remaining']);
        $this->assertFalse($config['show_countdown_pdp']);
        $this->assertFalse($config['show_countdown_listing']);
        $this->assertFalse($config['show_image_label']);

        $this->get('/admin/products/'.$product->id.'/edit')->assertOk();

        $this->put('/admin/products/' . $product->id, $this->withProductCategories([
            'type' => 'simple',
            'name' => 'iPhone XYZ',
            'slug' => 'iphone-xyz',
            'sku' => 'IPHONE-XYZ',
            'price' => 10000,
            'manage_stock' => true,
            'stock_qty' => 0,
            'weight' => 0.2,
            'is_enabled' => true,
            'preorder_enabled' => false,
            'preorder' => null,
        ]))->assertRedirect('/admin/products/' . $product->id . '/edit');

        $product->refresh();
        $this->assertFalse($product->preorder_enabled);
        $this->assertNull(ProductPreorderSetting::query()->where('product_id', $product->id)->first());
    }

    public function test_combination_product_with_preorder_keeps_variants_and_inventory(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $attrs = $this->seedCombinationAttributes();

        $this->post('/admin/products', $this->withProductCategories([
            'type' => 'combination',
            'sku' => 'TSHIRT-PRE',
            'name' => 'T-Shirt Pre-order',
            'price' => 19.99,
            'stock_qty' => 0,
            'is_enabled' => true,
            'preorder_enabled' => true,
            'preorder' => $this->preorderPayload(['total_limit' => 1000]),
            'combination_axes' => [
                ['attribute_id' => $attrs['color']->id, 'value_ids' => [$attrs['colorValues'][0]->id, $attrs['colorValues'][1]->id]],
                ['attribute_id' => $attrs['size']->id, 'value_ids' => [$attrs['sizeValues'][0]->id, $attrs['sizeValues'][1]->id]],
            ],
            'combination_variants' => [
                [
                    'attribute_value_ids' => [$attrs['colorValues'][0]->id, $attrs['sizeValues'][0]->id],
                    'sku' => 'TSHIRT-BS',
                    'price' => 19.99,
                    'stock_qty' => 0,
                    'is_enabled' => true,
                    'is_default' => true,
                ],
                [
                    'attribute_value_ids' => [$attrs['colorValues'][0]->id, $attrs['sizeValues'][1]->id],
                    'sku' => 'TSHIRT-BM',
                    'price' => 21.99,
                    'stock_qty' => 0,
                    'is_enabled' => true,
                    'is_default' => false,
                ],
            ],
        ]))->assertProductSaveRedirect();

        $product = Product::query()->where('sku', 'TSHIRT-PRE')->firstOrFail();
        $this->assertSame('combination', $product->type);
        $this->assertTrue($product->preorder_enabled);
        $this->assertCount(4, $product->variants);
        $this->assertNotNull(app(PreorderService::class)->presentConfig($product));

        $this->get('/admin/products/'.$product->id.'/edit')->assertOk();
    }

    public function test_preorder_validation_rejects_invalid_dates_and_limits(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $response = $this->from('/admin/products/create?type=simple')->post('/admin/products', $this->withProductCategories([
            'type' => 'simple',
            'name' => 'Invalid Pre-order',
            'slug' => 'invalid-preorder',
            'sku' => 'PRE-BAD',
            'price' => 1000,
            'is_enabled' => true,
            'preorder_enabled' => true,
            'preorder' => $this->preorderPayload([
                'start_at' => '2026-10-20T09:00',
                'end_at' => '2026-10-10T18:00',
            ]),
        ]));
        $response->assertRedirect('/admin/products/create?type=simple');
        $response->assertSessionHasErrors('preorder.end_at');

        $response = $this->from('/admin/products/create?type=simple')->post('/admin/products', $this->withProductCategories([
            'type' => 'simple',
            'name' => 'Invalid Limits',
            'slug' => 'invalid-limits',
            'sku' => 'PRE-LIMITS',
            'price' => 1000,
            'is_enabled' => true,
            'preorder_enabled' => true,
            'preorder' => $this->preorderPayload([
                'min_quantity' => 10,
                'max_quantity' => 5,
            ]),
        ]));
        $response->assertSessionHasErrors('preorder.max_quantity');

        $response = $this->from('/admin/products/create?type=simple')->post('/admin/products', $this->withProductCategories([
            'type' => 'simple',
            'name' => 'Invalid Deposit',
            'slug' => 'invalid-deposit',
            'sku' => 'PRE-DEP',
            'price' => 1000,
            'is_enabled' => true,
            'preorder_enabled' => true,
            'preorder' => $this->preorderPayload([
                'payment_type' => 'deposit',
                'deposit_type' => 'percent',
                'deposit_value' => 150,
            ]),
        ]));
        $response->assertSessionHasErrors('preorder.deposit_value');
    }

    public function test_preorder_validation_requires_min_quantity_and_deposit_fields(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        // min_quantity must be > 0 (empty/zero is rejected by PreorderService::validateConfig).
        $response = $this->from('/admin/products/create?type=simple')->post('/admin/products', $this->withProductCategories([
            'type' => 'simple',
            'name' => 'Missing Min Qty',
            'slug' => 'missing-min-qty',
            'sku' => 'PRE-MIN',
            'price' => 1000,
            'is_enabled' => true,
            'preorder_enabled' => true,
            'preorder' => $this->preorderPayload([
                'min_quantity' => 0,
            ]),
        ]));
        $response->assertRedirect('/admin/products/create?type=simple');
        $response->assertSessionHasErrors('preorder.min_quantity');

        // Deposit payment requires deposit_value.
        $response = $this->from('/admin/products/create?type=simple')->post('/admin/products', $this->withProductCategories([
            'type' => 'simple',
            'name' => 'Missing Deposit Value',
            'slug' => 'missing-deposit-value',
            'sku' => 'PRE-DEP-VAL',
            'price' => 1000,
            'is_enabled' => true,
            'preorder_enabled' => true,
            'preorder' => $this->preorderPayload([
                'payment_type' => 'deposit',
                'deposit_type' => 'percent',
                'deposit_value' => null,
            ]),
        ]));
        $response->assertRedirect('/admin/products/create?type=simple');
        $response->assertSessionHasErrors('preorder.deposit_value');

        // Deposit payment requires a valid deposit_type.
        $response = $this->from('/admin/products/create?type=simple')->post('/admin/products', $this->withProductCategories([
            'type' => 'simple',
            'name' => 'Missing Deposit Type',
            'slug' => 'missing-deposit-type',
            'sku' => 'PRE-DEP-TYPE',
            'price' => 1000,
            'is_enabled' => true,
            'preorder_enabled' => true,
            'preorder' => $this->preorderPayload([
                'payment_type' => 'deposit',
                'deposit_type' => 'invalid',
                'deposit_value' => 20,
            ]),
        ]));
        $response->assertRedirect('/admin/products/create?type=simple');
        $response->assertSessionHasErrors('preorder.deposit_type');
    }

    public function test_preorder_is_rejected_for_ineligible_product_types(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $response = $this->from('/admin/products/create?type=gift_card')->post('/admin/products', $this->withProductCategories([
            'type' => 'gift_card',
            'name' => 'Gift Card Pre-order',
            'slug' => 'gift-card-pre',
            'sku' => 'GC-PRE',
            'price' => 100,
            'is_enabled' => true,
            'gift_card' => ['amount_type' => 'fixed', 'validity_type' => 'never'],
            'gift_card_amounts' => [['value' => 100, 'selling_price' => 100, 'is_enabled' => true, 'position' => 0]],
            'preorder_enabled' => true,
            'preorder' => $this->preorderPayload(),
        ]));
        $response->assertSessionHasErrors('preorder_enabled');
    }

    public function test_legacy_preorder_type_is_converted_to_standard_with_capability(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->post('/admin/products', $this->withProductCategories([
            'type' => 'preorder',
            'name' => 'Legacy Pre-order',
            'slug' => 'legacy-preorder',
            'sku' => 'LEG-PRE',
            'price' => 5000,
            'is_enabled' => true,
            'preorder_enabled' => true,
            'preorder' => $this->preorderPayload(),
        ]))->assertProductSaveRedirect();

        $product = Product::query()->where('sku', 'LEG-PRE')->firstOrFail();
        $this->assertSame('simple', $product->type);
        $this->assertTrue($product->preorder_enabled);
    }

    public function test_create_with_legacy_preorder_query_enables_capability_by_default(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->get('/admin/products/create?type=preorder')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('productType', 'simple')
                ->where('preorderEnabledDefault', true));
    }

    public function test_gift_card_product_regression_without_preorder(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->post('/admin/products', $this->withProductCategories([
            'type' => 'gift_card',
            'name' => 'Gift Card',
            'slug' => 'gift-card-reg',
            'sku' => 'GC-REG2',
            'price' => 100,
            'is_enabled' => true,
            'gift_card' => ['amount_type' => 'fixed', 'validity_type' => 'never'],
            'gift_card_amounts' => [['value' => 100, 'selling_price' => 100, 'is_enabled' => true, 'position' => 0]],
        ]))->assertProductSaveRedirect();

        $product = Product::query()->where('sku', 'GC-REG2')->firstOrFail();
        $this->assertSame('gift_card', $product->type);
        $this->assertFalse($product->preorder_enabled);
    }

    public function test_countdown_toggles_persist_and_default_off(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->post('/admin/products', $this->withProductCategories([
            'type' => 'simple',
            'name' => 'Countdown Phone',
            'slug' => 'countdown-phone',
            'sku' => 'COUNT-PHONE',
            'price' => 199,
            'is_enabled' => true,
            'preorder_enabled' => true,
            'preorder' => $this->preorderPayload(),
        ]))->assertProductSaveRedirect();

        $product = Product::query()->where('sku', 'COUNT-PHONE')->firstOrFail();
        $row = ProductPreorderSetting::query()->where('product_id', $product->id)->firstOrFail();
        $this->assertFalse((bool) $row->show_countdown_pdp);
        $this->assertFalse((bool) $row->show_countdown_listing);
        $this->assertFalse((bool) $row->show_image_label);

        $this->put('/admin/products/' . $product->id, $this->withProductCategories([
            'type' => 'simple',
            'name' => 'Countdown Phone',
            'slug' => 'countdown-phone',
            'sku' => 'COUNT-PHONE',
            'price' => 199,
            'is_enabled' => true,
            'preorder_enabled' => true,
            'preorder' => $this->preorderPayload([
                'show_countdown_pdp' => true,
                'show_countdown_listing' => true,
                'show_image_label' => true,
            ]),
        ]))->assertRedirect('/admin/products/' . $product->id . '/edit');

        $product->refresh();
        $config = app(PreorderService::class)->presentConfig($product);
        $this->assertTrue($config['show_countdown_pdp']);
        $this->assertTrue($config['show_countdown_listing']);
        $this->assertTrue($config['show_image_label']);
        $row = ProductPreorderSetting::query()->where('product_id', $product->id)->firstOrFail();
        $this->assertTrue((bool) $row->show_countdown_pdp);
        $this->assertTrue((bool) $row->show_countdown_listing);
        $this->assertTrue((bool) $row->show_image_label);
    }
}
