<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Settings\ProductSettings;
use App\Domain\Settings\ShopSetting;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCompareSettingsTest extends TestCase
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
    protected function baseProductSettingsPayload(array $overrides = []): array
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

    public function test_product_settings_description_limits_return_field_errors_without_saving(): void
    {
        $admin = Admin::query()->firstOrFail();
        $beforeShort = ProductSettings::contentPayload()['short_description_max'] ?? null;
        $beforeLong = ProductSettings::contentPayload()['description_max'] ?? null;

        $this->actingAs($admin, 'admin')
            ->from('/admin/product-settings')
            ->put('/admin/product-settings', $this->baseProductSettingsPayload([
                'short_description_max' => 0,
                'description_max' => 80,
            ]))
            ->assertRedirect('/admin/product-settings')
            ->assertSessionHasErrors([
                'short_description_max',
                'description_max',
            ]);

        $after = ProductSettings::contentPayload();
        $this->assertSame($beforeShort, $after['short_description_max'] ?? null);
        $this->assertSame($beforeLong, $after['description_max'] ?? null);
    }

    public function test_product_settings_saves_description_limits_without_leading_zeros(): void
    {
        $admin = Admin::query()->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->from('/admin/product-settings')
            ->put('/admin/product-settings', $this->baseProductSettingsPayload([
                'short_description_max' => '050',
                'description_max' => '0100',
            ]))
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $saved = ProductSettings::contentPayload();
        $this->assertSame(50, (int) $saved['short_description_max']);
        $this->assertSame(100, (int) $saved['description_max']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makeCompareProduct(string $sku, array $overrides = []): Product
    {
        $product = Product::query()->create(array_merge([
            'type' => 'simple',
            'sku' => $sku,
            'price' => 25,
            'stock_qty' => 5,
            'stock_status' => 'in_stock',
            'is_enabled' => true,
            'visibility' => 'both',
            'available_for_order' => true,
            'show_price' => true,
        ], $overrides));
        $product->translations()->create([
            'language_code' => 'en',
            'name' => 'Compare '.$sku,
            'slug' => strtolower($sku),
        ]);

        return $product->fresh();
    }

    public function test_compare_fields_default_to_configurable_columns_without_name_or_image(): void
    {
        $fields = ProductSettings::compareFields();
        $keys = array_column($fields, 'key');
        $this->assertSame(array_keys(ProductSettings::COMPARE_DISPLAY_FIELDS), $keys);
        $this->assertNotContains('name', $keys);
        $this->assertNotContains('image', $keys);
        $this->assertContains('type', $keys);
        $this->assertContains('subscription', $keys);
        $this->assertContains('preorder', $keys);
        $this->assertContains('weight', $keys);

        $enabled = collect($fields)->where('enabled', true)->pluck('key')->all();
        $this->assertSame(ProductSettings::DEFAULT_COMPARE_FIELDS_ENABLED, array_values($enabled));
        $this->assertArrayNotHasKey('feature_id', $fields[0]);
    }

    public function test_storefront_fields_always_include_fixed_name_and_image(): void
    {
        $fields = ProductSettings::defaultCompareFields();
        foreach ($fields as $i => $field) {
            $fields[$i]['enabled'] = false;
        }
        ShopSetting::put('products.compare_fields', $fields);

        $keys = collect(ProductSettings::compareFieldsForStorefront())->pluck('key')->all();
        $this->assertSame(['name', 'image'], $keys);
        $this->assertSame(['name', 'image'], array_keys(ProductSettings::COMPARE_FIXED_FIELDS));
    }

    public function test_admin_can_save_and_restore_compare_field_checkboxes(): void
    {
        $admin = Admin::query()->firstOrFail();
        $fields = ProductSettings::defaultCompareFields();
        foreach ($fields as $i => $field) {
            $fields[$i]['enabled'] = in_array($field['key'], ['price', 'brand', 'shipping', 'type', 'weight'], true);
        }

        $this->actingAs($admin, 'admin')
            ->put('/admin/product-settings', $this->baseProductSettingsPayload([
                'compare_enabled' => true,
                'compare_max' => 3,
                'compare_fields' => $fields,
            ]))
            ->assertRedirect();

        $this->assertSame(3, ProductSettings::compareMax());
        $saved = collect(ProductSettings::compareFields())->keyBy('key');
        $this->assertArrayNotHasKey('name', $saved->all());
        $this->assertArrayNotHasKey('image', $saved->all());
        $this->assertTrue($saved['price']['enabled']);
        $this->assertTrue($saved['brand']['enabled']);
        $this->assertTrue($saved['shipping']['enabled']);
        $this->assertTrue($saved['type']['enabled']);
        $this->assertTrue($saved['weight']['enabled']);
        $this->assertFalse($saved['description']['enabled']);
        $this->assertFalse($saved['subscription']['enabled']);
        $this->assertFalse($saved['preorder']['enabled']);

        $this->actingAs($admin, 'admin')
            ->get('/admin/product-settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Product', false)
                ->where('defaults.compare_max', 3)
                ->where('defaults.compare_fields.0.key', 'price')
                ->where('defaults.compare_fields.0.enabled', true)
                ->missing('compare_feature_options')
                ->has('defaults.compare_fields', count(ProductSettings::COMPARE_DISPLAY_FIELDS)));
    }

    public function test_compare_fields_validation_rejects_unknown_keys(): void
    {
        $admin = Admin::query()->firstOrFail();
        $fields = ProductSettings::defaultCompareFields();
        $fields[0]['key'] = 'not-a-real-field';

        $this->actingAs($admin, 'admin')
            ->from('/admin/product-settings')
            ->put('/admin/product-settings', $this->baseProductSettingsPayload([
                'compare_fields' => $fields,
            ]))
            ->assertRedirect('/admin/product-settings')
            ->assertSessionHasErrors(['compare_fields.0.key']);
    }

    public function test_compare_fields_validation_rejects_fixed_name_and_image_keys(): void
    {
        $admin = Admin::query()->firstOrFail();
        $fields = ProductSettings::defaultCompareFields();
        array_unshift($fields, ['key' => 'name', 'enabled' => true]);

        $this->actingAs($admin, 'admin')
            ->from('/admin/product-settings')
            ->put('/admin/product-settings', $this->baseProductSettingsPayload([
                'compare_fields' => $fields,
            ]))
            ->assertRedirect('/admin/product-settings')
            ->assertSessionHasErrors(['compare_fields.0.key']);
    }

    public function test_storefront_compare_returns_fixed_fields_plus_enabled_configurable_fields(): void
    {
        $admin = Admin::query()->firstOrFail();
        $fields = ProductSettings::defaultCompareFields();
        foreach ($fields as $i => $field) {
            $fields[$i]['enabled'] = in_array($field['key'], ['brand', 'availability', 'price', 'type'], true);
        }

        $this->actingAs($admin, 'admin')
            ->put('/admin/product-settings', $this->baseProductSettingsPayload([
                'compare_fields' => $fields,
            ]))
            ->assertRedirect();

        $productIds = [
            $this->makeCompareProduct('CMP-A')->id,
            $this->makeCompareProduct('CMP-B')->id,
        ];

        $response = $this->getJson('/api/v1/catalog/compare?'.http_build_query(['ids' => $productIds]))
            ->assertOk();

        $keys = collect($response->json('fields'))->pluck('key')->all();
        $this->assertSame(['name', 'image', 'price', 'brand', 'availability', 'type'], $keys);
        $this->assertNotContains('description', $keys);
        $this->assertNotContains('shipping', $keys);
    }

    public function test_storefront_compare_exposes_new_field_values_from_product_data(): void
    {
        $admin = Admin::query()->firstOrFail();
        $fields = ProductSettings::defaultCompareFields();
        foreach ($fields as $i => $field) {
            $fields[$i]['enabled'] = in_array($field['key'], ['type', 'subscription', 'preorder', 'weight'], true);
        }

        $this->actingAs($admin, 'admin')
            ->put('/admin/product-settings', $this->baseProductSettingsPayload([
                'compare_fields' => $fields,
            ]))
            ->assertRedirect();

        $withExtras = $this->makeCompareProduct('CMP-DATA', [
            'type' => 'virtual',
            'weight' => 1.25,
            'weight_class' => 'kg',
            'subscription_enabled' => true,
            'preorder_enabled' => false,
        ]);
        $plain = $this->makeCompareProduct('CMP-PLAIN', [
            'type' => 'simple',
            'weight' => null,
            'subscription_enabled' => false,
            'preorder_enabled' => false,
        ]);

        $response = $this->getJson('/api/v1/catalog/compare?'.http_build_query([
            'ids' => [$withExtras->id, $plain->id],
        ]))->assertOk();

        $keys = collect($response->json('fields'))->pluck('key')->all();
        $this->assertSame(['name', 'image', 'type', 'subscription', 'preorder', 'weight'], $keys);

        $byId = collect($response->json('products'))->keyBy('id');
        $this->assertSame('virtual', $byId[$withExtras->id]['type']);
        $this->assertTrue($byId[$withExtras->id]['subscription_enabled']);
        $this->assertEqualsWithDelta(1.25, (float) $byId[$withExtras->id]['weight'], 0.001);
        $this->assertSame('kg', $byId[$withExtras->id]['weight_class']);
        $this->assertFalse($byId[$plain->id]['subscription_enabled']);
        $this->assertNull($byId[$plain->id]['weight'] ?? null);
    }

    public function test_legacy_feature_dropdown_settings_reset_to_product_field_defaults(): void
    {
        ShopSetting::put('products.compare_fields', [
            ['enabled' => true, 'feature_id' => 7],
            ['enabled' => false, 'feature_id' => null],
        ]);

        $fields = ProductSettings::compareFields();
        $this->assertSame('price', $fields[0]['key']);
        $this->assertTrue($fields[0]['enabled']);
        $this->assertArrayNotHasKey('feature_id', $fields[0]);
        $this->assertNotContains('name', array_column($fields, 'key'));
    }

    public function test_legacy_name_and_image_settings_are_dropped_on_normalize(): void
    {
        ShopSetting::put('products.compare_fields', [
            ['key' => 'name', 'enabled' => false],
            ['key' => 'image', 'enabled' => false],
            ['key' => 'price', 'enabled' => true],
            ['key' => 'brand', 'enabled' => false],
        ]);

        $fields = ProductSettings::compareFields();
        $keys = array_column($fields, 'key');
        $this->assertNotContains('name', $keys);
        $this->assertNotContains('image', $keys);
        $this->assertTrue(collect($fields)->firstWhere('key', 'price')['enabled']);
        $this->assertFalse(collect($fields)->firstWhere('key', 'brand')['enabled']);

        $fo = collect(ProductSettings::compareFieldsForStorefront())->pluck('key')->all();
        $this->assertSame('name', $fo[0]);
        $this->assertSame('image', $fo[1]);
        $this->assertContains('price', $fo);
        $this->assertNotContains('brand', $fo);
    }

    public function test_disabling_compare_fields_does_not_change_compare_max(): void
    {
        ShopSetting::put('products.catalog.compare_max', 4);
        $fields = ProductSettings::defaultCompareFields();
        foreach ($fields as $i => $field) {
            $fields[$i]['enabled'] = false;
        }

        $admin = Admin::query()->firstOrFail();
        $this->actingAs($admin, 'admin')
            ->put('/admin/product-settings', $this->baseProductSettingsPayload([
                'compare_max' => 4,
                'compare_fields' => $fields,
            ]))
            ->assertRedirect();

        $this->assertSame(4, ProductSettings::compareMax());
        $this->assertSame(
            [
                ['key' => 'name', 'label' => 'Product Name'],
                ['key' => 'image', 'label' => 'Product Image'],
            ],
            ProductSettings::compareFieldsForStorefront()
        );
    }

    public function test_admin_compare_bulk_toggle_persists_all_configurable_fields(): void
    {
        $admin = Admin::query()->firstOrFail();
        $enabled = ProductSettings::defaultCompareFields();
        foreach ($enabled as $i => $field) {
            $enabled[$i]['enabled'] = true;
        }

        $this->actingAs($admin, 'admin')
            ->put('/admin/product-settings', $this->baseProductSettingsPayload([
                'compare_fields' => $enabled,
            ]))
            ->assertRedirect();

        foreach (ProductSettings::compareFields() as $field) {
            $this->assertTrue($field['enabled'], $field['key'].' should be enabled');
        }
        $this->assertNotContains('name', array_column(ProductSettings::compareFields(), 'key'));
        $this->assertNotContains('image', array_column(ProductSettings::compareFields(), 'key'));
        $foKeys = collect(ProductSettings::compareFieldsForStorefront())->pluck('key')->all();
        $this->assertSame('name', $foKeys[0]);
        $this->assertSame('image', $foKeys[1]);
        $this->assertContains('price', $foKeys);
        $this->assertContains('weight', $foKeys);

        $disabled = ProductSettings::defaultCompareFields();
        foreach ($disabled as $i => $field) {
            $disabled[$i]['enabled'] = false;
        }

        $this->actingAs($admin, 'admin')
            ->put('/admin/product-settings', $this->baseProductSettingsPayload([
                'compare_fields' => $disabled,
            ]))
            ->assertRedirect();

        foreach (ProductSettings::compareFields() as $field) {
            $this->assertFalse($field['enabled'], $field['key'].' should be disabled');
        }
        $this->assertSame(
            ['name', 'image'],
            collect(ProductSettings::compareFieldsForStorefront())->pluck('key')->all()
        );
    }

    public function test_shop_bootstrap_exposes_compare_enabled_for_header_visibility(): void
    {
        ShopSetting::put('products.compare_enabled', true);
        $this->getJson('/api/v1/store')
            ->assertOk()
            ->assertJsonPath('products.compare_enabled', true);

        ShopSetting::put('products.compare_enabled', false);
        $this->getJson('/api/v1/store')
            ->assertOk()
            ->assertJsonPath('products.compare_enabled', false);
    }

    public function test_header_compare_icon_is_gated_and_navigates_to_compare_page(): void
    {
        $commerce = (string) file_get_contents(base_path('storefront/src/lib/commerce.js'));
        $this->assertStringContainsString('export function compareEnabled', $commerce);
        $this->assertStringContainsString("shop?.products?.compare_enabled !== false", $commerce);

        $header = (string) file_get_contents(base_path('storefront/src/components/ClassicHeader.jsx'));
        $this->assertStringContainsString('show_compare', $header);
        $this->assertStringContainsString('showCompare && compareEnabled(shop)', $header);
        $this->assertStringContainsString('to="/compare"', $header);
        $this->assertStringContainsString("Compare Products", $header);
        $this->assertStringContainsString('cx-compare-btn', $header);
        $this->assertStringContainsString('name="compare"', $header);

        $comparePos = strpos($header, 'to="/compare"');
        $wishlistPos = strpos($header, 'to="/account/wishlist"');
        $this->assertNotFalse($comparePos);
        $this->assertNotFalse($wishlistPos);
        $this->assertLessThan($wishlistPos, $comparePos, 'Compare icon should sit beside wishlist (before it in the action cluster)');

        $main = (string) file_get_contents(base_path('storefront/src/main.jsx'));
        $this->assertStringContainsString('path="/compare"', $main);

        $prefetch = (string) file_get_contents(base_path('storefront/src/lib/prefetchRoute.js'));
        $this->assertStringContainsString("'/compare'", $prefetch);
    }

    public function test_product_card_reflects_compare_list_membership(): void
    {
        $card = (string) file_get_contents(base_path('storefront/src/components/ProductCard.jsx'));
        $this->assertStringContainsString('(store.compare || []).map(Number).includes(Number(product.id))', $card);
        $this->assertStringContainsString('aria-pressed={comparing}', $card);
        $this->assertStringContainsString('store.toggleCompare(product.id)', $card);
        $this->assertStringContainsString('cx-card group', $card);
        $this->assertStringContainsString('shop-list-card-actions', $card);
        $this->assertStringContainsString('shop-list-card-compare', $card);
        $this->assertStringNotContainsString('is-comparing\' : \'\'', $card);
        $this->assertMatchesRegularExpression('/className=\{comparing \? \'is-comparing\' : undefined\}/', $card);
        $this->assertStringContainsString('Remove from comparison', $card);
        $this->assertStringContainsString('Add to Compare', $card);

        $storage = (string) file_get_contents(base_path('storefront/src/lib/storage.js'));
        $this->assertStringContainsString('export function toggleCompare', $storage);
        $this->assertStringContainsString('export function compareIds', $storage);
        $this->assertStringContainsString("action: 'limit'", $storage);
        $this->assertStringContainsString('ids.length >= limit', $storage);
        $this->assertStringNotContainsString('.slice(0, limit)', $storage);

        $state = (string) file_get_contents(base_path('storefront/src/state/StoreState.jsx'));
        $this->assertStringContainsString('toggleCompare(id)', $state);
        $this->assertStringContainsString('setCompare(next)', $state);
        $this->assertStringContainsString('writeCompare(id, max)', $state);
        $this->assertStringContainsString("action === 'added'", $state);
        $this->assertStringContainsString("action === 'removed'", $state);
        $this->assertStringContainsString("action === 'limit'", $state);
        $this->assertStringContainsString('Added to compare', $state);
        $this->assertStringContainsString('Removed from compare', $state);
        $this->assertStringContainsString('You can compare up to :max products at a time', $state);
        $this->assertStringContainsString("'warning'", $state);
        $this->assertStringContainsString('.replace(\':max\', String(limit))', $state);

        $css = (string) file_get_contents(base_path('storefront/src/styles.css'));
        $this->assertStringContainsString('.cx-card-hover button.is-comparing', $css);
        $this->assertStringNotContainsString('.cx-card.is-comparing {', $css);
        $this->assertStringNotContainsString('.cx-card.is-comparing:hover', $css);
        $this->assertStringNotContainsString('.cx-card.is-comparing .cx-card-hover', $css);
    }

    public function test_compare_page_uses_structured_table_and_respects_enable_flag(): void
    {
        $page = (string) file_get_contents(base_path('storefront/src/pages/Compare.jsx'));
        $this->assertStringContainsString('shop-compare-table', $page);
        $this->assertStringContainsString('shop-compare-card', $page);
        $this->assertStringContainsString('shop-compare-scroll', $page);
        $this->assertStringContainsString('shop-compare-remove', $page);
        $this->assertStringContainsString('Remove from comparison', $page);
        $this->assertStringContainsString('ProductQuickActions', $page);
        $this->assertStringContainsString('shop-compare-actions', $page);
        $this->assertStringContainsString('--compare-cols', $page);
        $this->assertStringContainsString('compareEnabled(store.shop)', $page);
        $this->assertStringContainsString('store.toggleCompare', $page);
        $this->assertStringContainsString('/catalog/compare', $page);
        $this->assertStringContainsString('productPath(product)', $page);
        $this->assertStringContainsString("const showImage = true", $page);
        $this->assertStringContainsString("const showName = true", $page);
        $this->assertStringContainsString("case 'type':", $page);
        $this->assertStringContainsString("case 'subscription':", $page);
        $this->assertStringContainsString("case 'preorder':", $page);
        $this->assertStringContainsString("case 'weight':", $page);

        $adminUi = (string) file_get_contents(base_path('resources/js/admin/Pages/Settings/Product.jsx'));
        $this->assertStringNotContainsString("key: 'name'", $adminUi);
        $this->assertStringNotContainsString("key: 'image'", $adminUi);
        $this->assertStringContainsString("key: 'type'", $adminUi);
        $this->assertStringContainsString("key: 'subscription'", $adminUi);
        $this->assertStringContainsString("key: 'preorder'", $adminUi);
        $this->assertStringContainsString("key: 'weight'", $adminUi);
        $this->assertStringContainsString('SelectionCheckbox', $adminUi);
        $this->assertStringContainsString('compareAllSelected', $adminUi);
        $this->assertStringContainsString('compareSomeSelected', $adminUi);
        $this->assertStringContainsString('setAllCompareFields', $adminUi);
        $this->assertStringContainsString('Show all comparison fields', $adminUi);
        $this->assertStringNotContainsString("t('admin.common.select_all'", $adminUi);
        $this->assertStringNotContainsString("t('admin.common.deselect_all'", $adminUi);

        $actions = (string) file_get_contents(base_path('storefront/src/components/ProductQuickActions.jsx'));
        $this->assertStringContainsString('store.addToCart', $actions);
        $this->assertStringContainsString('store.toggleWish', $actions);
        $this->assertStringContainsString('Quick view', $actions);
        $this->assertStringContainsString('Add to Cart', $actions);
        $this->assertStringNotContainsString('is-view', $actions);

        $card = (string) file_get_contents(base_path('storefront/src/components/ProductCard.jsx'));
        $this->assertStringContainsString('ProductQuickActions', $card);
        $this->assertStringContainsString('store.compare', $card);
        $this->assertStringContainsString('aria-pressed={comparing}', $card);
        $this->assertStringContainsString('store.toggleCompare(product.id)', $card);
        $this->assertStringContainsString('Remove from comparison', $card);
        $this->assertStringContainsString('Add to Compare', $card);

        $css = (string) file_get_contents(base_path('storefront/src/styles.css'));
        $this->assertStringContainsString('.cx-card-hover button.is-comparing', $css);
        $this->assertStringContainsString('.cx-card-hover a,', $css);
        $this->assertStringNotContainsString('.cx-card-hover a.is-view', $css);
        $this->assertStringNotContainsString('.shop-compare-actions a.is-view', $css);
        $this->assertStringContainsString('.shop-compare-table', $css);
        $this->assertStringContainsString('width: 100%', $css);
        $this->assertStringContainsString('--compare-cols', $css);
        $this->assertStringContainsString('.shop-compare-scroll', $css);
        $this->assertStringContainsString('.shop-compare-remove', $css);
        $this->assertStringContainsString('position: absolute', $css);
        $this->assertStringContainsString('.shop-compare-actions', $css);
        $this->assertStringContainsString('text-align: center', $css);
        $this->assertStringContainsString('.shop-compare-row.is-alt', $css);
        $this->assertStringContainsString('overflow-x: auto', $css);
    }

    public function test_product_list_highlight_can_be_turned_off(): void
    {
        $admin = Admin::query()->firstOrFail();
        $this->assertTrue(ProductSettings::highlightEnabled());

        $this->actingAs($admin, 'admin')
            ->put('/admin/product-settings', $this->baseProductSettingsPayload([
                'row_highlight_enabled' => false,
            ]))
            ->assertRedirect();

        $this->assertFalse(ProductSettings::highlightEnabled());
        $this->getJson('/api/v1/store')
            ->assertOk()
            ->assertJsonPath('products.list_highlight.enabled', false)
            ->assertJsonPath('products.list_highlight.color', ProductSettings::color());
    }
}
