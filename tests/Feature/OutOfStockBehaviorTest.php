<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\CatalogPresenter;
use App\Domain\Checkout\Services\CartService;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Settings\ProductSettings;
use App\Domain\Settings\ShopSetting;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OutOfStockBehaviorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /**
     * @return array<string, mixed>
     */
    protected function settingsPayload(array $overrides = []): array
    {
        return array_merge(
            ProductSettings::defaultsPayload(),
            ProductSettings::catalogPayload(),
            ProductSettings::contentPayload(),
            [
                'row_highlight_enabled' => ProductSettings::highlightEnabled(),
                'row_highlight_color' => ProductSettings::color(),
                'row_highlight_ms' => ProductSettings::durationMs(),
            ],
            $overrides,
        );
    }

    /** @param  array<string, mixed>  $overrides */
    protected function productPayload(array $overrides = []): array
    {
        return $this->withProductCategories(array_merge([
            'type' => 'simple',
            'name' => 'OOS Policy Product',
            'sku' => 'OOS-POLICY-1',
            'price' => 20,
            'is_enabled' => true,
            'manage_stock' => true,
            'stock_qty' => 0,
            'reserved_qty' => 0,
            'stock_status' => 'out_of_stock',
        ], $overrides));
    }

    public function test_inventory_defaults_save_and_return_each_oos_option(): void
    {
        $admin = Admin::query()->firstOrFail();

        foreach (['allow', 'deny', 'hide'] as $option) {
            $this->actingAs($admin, 'admin')
                ->put('/admin/product-settings', $this->settingsPayload([
                    'out_of_stock_behavior' => $option,
                ]))
                ->assertRedirect()
                ->assertSessionDoesntHaveErrors();

            $this->assertSame($option, ProductSettings::outOfStockBehavior());
            $this->assertSame($option === 'allow', ProductSettings::defaultAllowBackorder());
            $this->assertSame($option, ProductSettings::defaultBackorderStatus());
            $this->assertSame($option === 'hide', ProductSettings::hideOutOfStock());
        }

        $this->actingAs($admin, 'admin')
            ->get('/admin/product-settings?tab=defaults')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Product', false)
                ->where('defaults.out_of_stock_behavior', 'hide'));
    }

    public function test_legacy_oos_values_normalize_on_read(): void
    {
        ShopSetting::put('products.out_of_stock_behavior', 'keep_visible');
        ShopSetting::put('products.default_allow_backorder', false);
        $this->assertSame('deny', ProductSettings::outOfStockBehavior());

        ShopSetting::put('products.default_allow_backorder', true);
        $this->assertSame('allow', ProductSettings::outOfStockBehavior());

        ShopSetting::put('products.out_of_stock_behavior', 'disable_purchase');
        $this->assertSame('deny', ProductSettings::outOfStockBehavior());

        ShopSetting::put('products.out_of_stock_behavior', 'hide');
        $this->assertSame('hide', ProductSettings::outOfStockBehavior());
    }

    public function test_product_inventory_radios_expose_three_options_and_saved_value(): void
    {
        $admin = Admin::query()->firstOrFail();
        ShopSetting::put('products.out_of_stock_behavior', 'deny');

        $this->actingAs($admin, 'admin')
            ->post('/admin/products', $this->productPayload([
                'sku' => 'OOS-RADIO-1',
                'backorder_status' => 'hide',
                'stock_qty' => 3,
                'stock_status' => 'in_stock',
            ]))
            ->assertProductSaveRedirect();

        $product = Product::query()->where('sku', 'OOS-RADIO-1')->firstOrFail();
        $this->assertSame('hide', $product->backorder_status);
        $this->assertFalse((bool) $product->allow_backorder);

        $this->actingAs($admin, 'admin')
            ->get('/admin/products/'.$product->id.'/edit')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Catalog/Products/Form', false)
                ->where('product.backorder_status', 'hide')
                ->where('productDefaults.out_of_stock_behavior', 'deny'));
    }

    public function test_new_products_inherit_inventory_default(): void
    {
        $admin = Admin::query()->firstOrFail();
        ShopSetting::put('products.out_of_stock_behavior', 'allow');
        ShopSetting::put('products.default_allow_backorder', true);
        ShopSetting::put('products.default_backorder_status', 'allow');

        $this->actingAs($admin, 'admin')
            ->post('/admin/products', $this->productPayload([
                'sku' => 'OOS-INHERIT-1',
                'stock_qty' => 5,
                'stock_status' => 'in_stock',
                // omit backorder_status → server default
            ]))
            ->assertProductSaveRedirect();

        $product = Product::query()->where('sku', 'OOS-INHERIT-1')->firstOrFail();
        $this->assertSame('allow', $product->backorder_status);
        $this->assertTrue((bool) $product->allow_backorder);
    }

    public function test_existing_product_override_is_preserved(): void
    {
        $admin = Admin::query()->firstOrFail();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $product->update([
            'manage_stock' => true,
            'stock_qty' => 0,
            'reserved_qty' => 0,
            'backorder_status' => 'deny',
            'allow_backorder' => false,
        ]);

        ShopSetting::put('products.out_of_stock_behavior', 'allow');

        $this->assertSame('deny', ProductSettings::productStockBehavior($product->fresh()));
        $this->assertTrue(ProductSettings::blocksPurchaseWhenOutOfStock($product->fresh()));
    }

    public function test_allow_permits_out_of_stock_purchase(): void
    {
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $product->update([
            'manage_stock' => true,
            'stock_qty' => 0,
            'reserved_qty' => 0,
            'backorder_status' => 'allow',
            'allow_backorder' => true,
            'is_enabled' => true,
            'available_for_order' => true,
            'price' => 25,
        ]);

        $this->assertFalse(ProductSettings::productIsOutOfStock($product->fresh()));
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->assertGreaterThan(0, (int) $this->getJson('/api/v1/cart')->json('cart.items_count')
            ?: count($this->getJson('/api/v1/cart')->json('cart.items') ?? []));
    }

    public function test_deny_blocks_purchase_but_keeps_product_visible(): void
    {
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $product->update([
            'manage_stock' => true,
            'stock_qty' => 0,
            'reserved_qty' => 0,
            'backorder_status' => 'deny',
            'allow_backorder' => false,
            'is_enabled' => true,
            'available_for_order' => true,
        ]);
        ShopSetting::put('products.out_of_stock_behavior', 'deny');
        ShopSetting::put('products.out_of_stock_notification', true);

        $this->assertTrue(ProductSettings::productIsOutOfStock($product->fresh()));
        $this->assertFalse(ProductSettings::hiddenFromStorefront($product->fresh()));
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertStatus(422);

        $presented = app(CatalogPresenter::class)->product($product->fresh(), 'en', true);
        $this->assertSame('deny', $presented['backorder_status'] ?? null);
        // Buy box stays open so ATC can render disabled and notify can show.
        $this->assertTrue($presented['available_for_order'] ?? false);
        $this->assertFalse($presented['can_add_to_cart'] ?? true);
        $this->assertTrue($presented['stock_alert']['enabled'] ?? false);
        $this->assertTrue($presented['stock_alert']['out_of_stock'] ?? false);
    }

    public function test_hide_excludes_oos_products_from_listing_filter(): void
    {
        $admin = Admin::query()->firstOrFail();
        $visible = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $visible->update([
            'manage_stock' => true,
            'stock_qty' => 0,
            'reserved_qty' => 0,
            'backorder_status' => 'deny',
            'allow_backorder' => false,
            'is_enabled' => true,
        ]);

        $this->actingAs($admin, 'admin')
            ->post('/admin/products', $this->productPayload([
                'sku' => 'OOS-HIDE-LIST',
                'name' => 'Hidden OOS Product',
                'backorder_status' => 'hide',
                'stock_qty' => 0,
                'stock_status' => 'out_of_stock',
            ]))
            ->assertProductSaveRedirect();
        $hidden = Product::query()->where('sku', 'OOS-HIDE-LIST')->firstOrFail();

        ShopSetting::put('products.out_of_stock_behavior', 'deny');

        $ids = Product::query()
            ->whereIn('id', [$visible->id, $hidden->id])
            ->tap(fn ($q) => ProductSettings::applyListingStockFilter($q))
            ->pluck('id')
            ->all();

        $this->assertContains($visible->id, $ids);
        $this->assertNotContains($hidden->id, $ids);
        $this->assertTrue(ProductSettings::hiddenFromStorefront($hidden->fresh()));
        $this->postJson('/api/v1/cart', ['product_id' => $hidden->id, 'qty' => 1])->assertStatus(422);
    }

    public function test_checkout_cannot_bypass_deny_policy(): void
    {
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $product->update([
            'manage_stock' => true,
            'stock_qty' => 0,
            'reserved_qty' => 0,
            'backorder_status' => 'deny',
            'allow_backorder' => false,
            'is_enabled' => true,
            'available_for_order' => true,
            'price' => 15,
        ]);
        $payment = PaymentMethod::query()->firstOrFail();
        $shipping = ShippingMethod::query()->firstOrFail();
        $shipping->update(['is_enabled' => true, 'price' => 0]);

        // Force a cart line past UI, then checkout must still reject.
        $cart = app(CartService::class)->current();
        $cart->items()->create([
            'product_id' => $product->id,
            'qty' => 1,
            'unit_price' => 15,
        ]);

        $this->postJson('/api/v1/checkout', [
            'email' => 'oos-deny@example.com',
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ])->assertStatus(422);
    }

    public function test_inventory_disabled_products_ignore_oos_policy(): void
    {
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $product->update([
            'manage_stock' => false,
            'stock_qty' => 0,
            'backorder_status' => 'hide',
            'allow_backorder' => false,
            'is_enabled' => true,
            'available_for_order' => true,
        ]);

        $this->assertFalse(ProductSettings::productIsOutOfStock($product->fresh()));
        $this->assertFalse(ProductSettings::hiddenFromStorefront($product->fresh()));
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
    }

    public function test_allow_notify_legacy_maps_to_allow(): void
    {
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $product->update([
            'manage_stock' => true,
            'stock_qty' => 0,
            'backorder_status' => 'allow_notify',
            'allow_backorder' => true,
        ]);

        $this->assertSame('allow', ProductSettings::productStockBehavior($product->fresh()));
        $this->assertFalse(ProductSettings::productIsOutOfStock($product->fresh()));
    }

    public function test_restock_clears_hidden_state(): void
    {
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $product->update([
            'manage_stock' => true,
            'stock_qty' => 0,
            'reserved_qty' => 0,
            'backorder_status' => 'hide',
            'allow_backorder' => false,
        ]);
        $this->assertTrue(ProductSettings::hiddenFromStorefront($product->fresh()));

        $product->update(['stock_qty' => 4, 'stock_status' => 'in_stock']);
        $this->assertFalse(ProductSettings::hiddenFromStorefront($product->fresh()));
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
    }
}
