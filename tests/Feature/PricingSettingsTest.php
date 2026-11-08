<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\CatalogPresenter;
use App\Domain\Localization\Money;
use App\Domain\Settings\PricingSettings;
use App\Domain\Settings\ShopSetting;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Domain\Store\ShopIdentity;
use App\Domain\Tax\Models\TaxClass;
use App\Domain\Tax\Models\TaxRate;
use App\Domain\Tax\Models\TaxRule;
use App\Domain\Tax\Models\TaxZone;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PricingSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_pricing_settings_persist_including_shipping_tax_off(): void
    {
        $admin = Admin::query()->firstOrFail();

        $this->actingAs($admin, 'admin')->put('/admin/pricing-settings', [
            'tax_inclusive_input' => 1,
            'storefront_display' => 'both',
            'decimal_precision' => 0,
            'rounding' => 'up',
            'zero_price' => 'contact',
            'sale_enabled' => 0,
            'compare_at_enabled' => 0,
            'shipping_tax' => 0,
        ])->assertRedirect();

        $this->assertTrue(PricingSettings::taxInclusiveInput());
        $this->assertSame('both', PricingSettings::storefrontDisplay());
        $this->assertSame(0, PricingSettings::decimalPrecision());
        $this->assertSame('up', PricingSettings::rounding());
        $this->assertSame('contact', PricingSettings::zeroPrice());
        $this->assertFalse(PricingSettings::salePricing());
        $this->assertFalse(PricingSettings::compareAtEnabled());
        $this->assertFalse(PricingSettings::shippingTax());

        $this->getJson('/api/v1/store')
            ->assertOk()
            ->assertJsonPath('commerce.pricing.shipping_tax', false)
            ->assertJsonPath('commerce.pricing.sale_enabled', false)
            ->assertJsonPath('commerce.pricing.compare_at_enabled', false)
            ->assertJsonPath('commerce.pricing.zero_price', 'contact')
            ->assertJsonPath('commerce.pricing.storefront_display', 'both');
    }

    public function test_sale_pricing_off_uses_regular_price_in_catalog_and_cart(): void
    {
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $regular = (float) $product->price;
        $product->update([
            'sale_price' => max(1, round($regular * 0.5, 2)),
            'sale_price_type' => 'fixed',
            'sale_starts_at' => null,
            'sale_ends_at' => null,
            'is_on_sale' => true,
        ]);

        ShopSetting::put('pricing.sale_enabled', true);
        $this->assertLessThan($regular, $product->fresh()->sellingPrice());

        ShopSetting::put('pricing.sale_enabled', false);
        $this->assertEqualsWithDelta($regular, $product->fresh()->sellingPrice(), 0.001);

        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $unit = (float) $this->getJson('/api/v1/cart')->json('cart.items.0.unit_price');
        $this->assertEqualsWithDelta($regular, $unit, 0.001);
    }

    public function test_compare_at_off_hides_compare_price_on_catalog_payload(): void
    {
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $product->update([
            'price' => 80,
            'compare_price' => 100,
            'sale_price' => 80,
            'sale_price_type' => 'fixed',
        ]);
        ShopSetting::put('pricing.sale_enabled', true);
        ShopSetting::put('pricing.compare_at_enabled', false);

        $row = app(CatalogPresenter::class)->product($product->fresh(), 'en', true);
        $this->assertNull($row['compare_price']);
        $this->assertNull($row['discount_percent']);
    }

    public function test_zero_price_contact_blocks_add_to_cart(): void
    {
        $product = Product::query()->create([
            'type' => 'simple',
            'sku' => 'FREE-CONTACT-1',
            'price' => 0,
            'stock_qty' => 10,
            'is_enabled' => true,
            'manage_stock' => false,
            'available_for_order' => true,
            'visibility' => 'both',
        ]);
        $product->translations()->create([
            'language_code' => 'en',
            'name' => 'Free Contact',
            'slug' => 'free-contact-1',
        ]);

        ShopSetting::put('pricing.zero_price', 'contact');

        $row = app(CatalogPresenter::class)->product($product->fresh(), 'en', true);
        $this->assertTrue($row['contact_for_price']);
        $this->assertFalse($row['available_for_order']);

        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])
            ->assertStatus(422);
    }

    public function test_zero_price_hide_conceals_price_but_allows_order_when_allowed(): void
    {
        $product = Product::query()->create([
            'type' => 'simple',
            'sku' => 'FREE-HIDE-1',
            'price' => 0,
            'stock_qty' => 10,
            'is_enabled' => true,
            'manage_stock' => false,
            'available_for_order' => true,
            'visibility' => 'both',
        ]);
        $product->translations()->create([
            'language_code' => 'en',
            'name' => 'Free Hide',
            'slug' => 'free-hide-1',
        ]);

        ShopSetting::put('pricing.zero_price', 'hide');

        $row = app(CatalogPresenter::class)->product($product->fresh(), 'en', true);
        $this->assertFalse($row['show_price']);
        $this->assertNull($row['price']);
        $this->assertFalse($row['contact_for_price']);
        $this->assertTrue($row['available_for_order']);

        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
    }

    public function test_storefront_display_splits_incl_and_excl_using_shop_tax(): void
    {
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $class = TaxClass::query()->create(['name' => 'Pricing display']);
        $zone = TaxZone::query()->create(['name' => 'WW', 'is_enabled' => true, 'position' => 50]);
        $rate = TaxRate::query()->create(['name' => 'VAT 20', 'rate' => 20, 'is_enabled' => true]);
        TaxRule::query()->create([
            'tax_class_id' => $class->id,
            'tax_zone_id' => $zone->id,
            'tax_rate_id' => $rate->id,
            'name' => 'VAT',
            'rate' => 20,
            'priority' => 1,
            'is_compound' => false,
            'is_enabled' => true,
        ]);
        $product->update(['tax_class_id' => $class->id, 'price' => 100, 'sale_price' => null]);

        ShopSetting::put('pricing.tax_inclusive_input', false);
        ShopSetting::put('pricing.storefront_display', 'with_tax');
        ShopSetting::put('pricing.sale_enabled', true);

        $identity = ShopIdentity::get();
        $identity['country_code'] = 'US';
        ShopSetting::put(ShopIdentity::SETTING, $identity);

        $withTax = app(CatalogPresenter::class)->product($product->fresh(), 'en', true);
        $this->assertEqualsWithDelta(120.0, (float) $withTax['price'], 0.05);
        $this->assertEqualsWithDelta(100.0, (float) $withTax['price_excl'], 0.05);
        $this->assertEqualsWithDelta(120.0, (float) $withTax['price_incl'], 0.05);

        ShopSetting::put('pricing.storefront_display', 'without_tax');
        $without = app(CatalogPresenter::class)->product($product->fresh(), 'en', true);
        $this->assertEqualsWithDelta(100.0, (float) $without['price'], 0.05);

        ShopSetting::put('pricing.storefront_display', 'both');
        $both = app(CatalogPresenter::class)->product($product->fresh(), 'en', true);
        // "both" uses tax-inclusive as the main price so PDP/cards match cart totals.
        $this->assertEqualsWithDelta(120.0, (float) $both['price'], 0.05);
        $this->assertEqualsWithDelta(100.0, (float) $both['price_catalog'], 0.05);
        $this->assertEqualsWithDelta(100.0, (float) $both['price_excl'], 0.05);
        $this->assertEqualsWithDelta(120.0, (float) $both['price_incl'], 0.05);
        $this->assertNotEmpty($both['price_excl_formatted']);
        $this->assertNotEmpty($both['price_incl_formatted']);
    }

    public function test_catalog_main_price_matches_cart_display_line_when_showing_tax_inclusive(): void
    {
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $class = TaxClass::query()->create(['name' => 'FO consistency']);
        $zone = TaxZone::query()->create(['name' => 'WW cons', 'is_enabled' => true, 'position' => 61]);
        $rate = TaxRate::query()->create(['name' => 'VAT 10 cons', 'rate' => 10, 'is_enabled' => true]);
        TaxRule::query()->create([
            'tax_class_id' => $class->id,
            'tax_zone_id' => $zone->id,
            'tax_rate_id' => $rate->id,
            'name' => 'VAT10',
            'rate' => 10,
            'priority' => 1,
            'is_compound' => false,
            'is_enabled' => true,
        ]);
        $product->update(['tax_class_id' => $class->id, 'price' => 44.99, 'sale_price' => null]);

        $identity = ShopIdentity::get();
        $identity['country_code'] = 'US';
        ShopSetting::put(ShopIdentity::SETTING, $identity);
        ShopSetting::put('pricing.tax_inclusive_input', false);
        ShopSetting::put('pricing.storefront_display', 'both');
        ShopSetting::put('tax.shipping', false);

        $presented = app(CatalogPresenter::class)->product($product->fresh(), 'en', true);
        $this->assertEqualsWithDelta(49.49, (float) $presented['price'], 0.05);

        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $cart = $this->getJson('/api/v1/cart?country_code=US')->assertOk()->json();

        $this->assertTrue($cart['totals']['prices_include_tax']);
        $this->assertEqualsWithDelta(
            (float) $presented['price'],
            (float) $cart['totals']['lines'][0]['display_unit_price'],
            0.05,
        );
        $this->assertEqualsWithDelta(
            (float) $presented['price'],
            (float) $cart['totals']['display_subtotal'],
            0.05,
        );
        $this->assertEqualsWithDelta(
            (float) $cart['totals']['display_subtotal'],
            (float) $cart['totals']['grand_total'],
            0.05,
        );
        // Underlying catalog unit stays tax-exclusive for checkout math.
        $this->assertEqualsWithDelta(44.99, (float) $cart['totals']['lines'][0]['unit_price'], 0.05);
    }

    public function test_tax_inclusive_input_extracts_tax_from_catalog_prices_in_cart(): void
    {
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $class = TaxClass::query()->create(['name' => 'Inclusive class']);
        $zone = TaxZone::query()->create(['name' => 'WW2', 'is_enabled' => true, 'position' => 51]);
        $rate = TaxRate::query()->create(['name' => 'VAT 10', 'rate' => 10, 'is_enabled' => true]);
        TaxRule::query()->create([
            'tax_class_id' => $class->id,
            'tax_zone_id' => $zone->id,
            'tax_rate_id' => $rate->id,
            'name' => 'VAT10',
            'rate' => 10,
            'priority' => 1,
            'is_compound' => false,
            'is_enabled' => true,
        ]);
        $product->update(['tax_class_id' => $class->id, 'price' => 110, 'sale_price' => null]);

        ShopSetting::put('pricing.tax_inclusive_input', true);
        ShopSetting::put('tax.shipping', false);

        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $tax = (float) $this->getJson('/api/v1/cart?country_code=US')->json('totals.tax');
        $this->assertEqualsWithDelta(10.0, $tax, 0.05);
    }

    public function test_shipping_tax_toggle_affects_cart_tax_total(): void
    {
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $shipping = ShippingMethod::query()->firstOrFail();

        $class = TaxClass::query()->create(['name' => 'Ship tax']);
        $zone = TaxZone::query()->create(['name' => 'WW3', 'is_enabled' => true, 'position' => 52]);
        $rate = TaxRate::query()->create(['name' => 'VAT 20 ship', 'rate' => 20, 'is_enabled' => true]);
        TaxRule::query()->create([
            'tax_class_id' => $class->id,
            'tax_zone_id' => $zone->id,
            'tax_rate_id' => $rate->id,
            'name' => 'VAT20',
            'rate' => 20,
            'priority' => 1,
            'is_compound' => false,
            'is_enabled' => true,
        ]);
        $product->update(['tax_class_id' => $class->id, 'price' => 50, 'sale_price' => null]);
        $shipping->update(['price' => 10, 'is_enabled' => true, 'tax_class_id' => $class->id]);

        ShopSetting::put('pricing.tax_inclusive_input', false);
        ShopSetting::put('tax.shipping', true);

        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $withShipTax = (float) $this->getJson('/api/v1/cart?country_code=US&shipping_method_id='.$shipping->id)->json('totals.tax');

        ShopSetting::put('tax.shipping', false);
        $withoutShipTax = (float) $this->getJson('/api/v1/cart?country_code=US&shipping_method_id='.$shipping->id)->json('totals.tax');

        $this->assertGreaterThan($withoutShipTax, $withShipTax);
        $this->assertEqualsWithDelta(2.0, $withShipTax - $withoutShipTax, 0.05);
    }

    public function test_product_form_exposes_tax_layers_and_pricing_settings_for_preview(): void
    {
        $admin = Admin::query()->firstOrFail();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $class = TaxClass::query()->create(['name' => 'Admin preview class']);
        $zone = TaxZone::query()->create(['name' => 'WW preview', 'is_enabled' => true, 'position' => 60]);
        $rate = TaxRate::query()->create(['name' => 'VAT 20 preview', 'rate' => 20, 'is_enabled' => true]);
        TaxRule::query()->create([
            'tax_class_id' => $class->id,
            'tax_zone_id' => $zone->id,
            'tax_rate_id' => $rate->id,
            'name' => 'VAT20',
            'rate' => 20,
            'priority' => 1,
            'is_compound' => false,
            'is_enabled' => true,
        ]);
        $product->update(['tax_class_id' => $class->id]);

        $identity = ShopIdentity::get();
        $identity['country_code'] = 'US';
        ShopSetting::put(ShopIdentity::SETTING, $identity);

        $this->actingAs($admin, 'admin')
            ->get('/admin/products/'.$product->id.'/edit')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Catalog/Products/Form', false)
                ->has('pricingSettings')
                ->where('pricingSettings.tax_inclusive_input', PricingSettings::taxInclusiveInput())
                ->has('taxClasses')
                ->where('taxClasses', function ($rows) use ($class) {
                    $match = collect($rows)->firstWhere('id', $class->id);

                    return $match
                        && isset($match['tax_layers'])
                        && (float) ($match['tax_layers'][0]['rate'] ?? 0) === 20.0;
                }));

        $form = (string) file_get_contents(resource_path('js/admin/Pages/Catalog/Products/Form.jsx'));
        $this->assertStringContainsString('Tax price preview', $form);
        $this->assertStringContainsString('taxPricePreview', $form);
        $this->assertStringContainsString('taxInputModeLabel', $form);
        $this->assertStringContainsString('Tax inclusive', $form);
        $this->assertStringContainsString('Tax exclusive', $form);
        $this->assertStringContainsString('Excl. tax', $form);
        $this->assertStringContainsString('Incl. tax', $form);
    }

    public function test_product_form_tax_input_label_follows_pricing_setting(): void
    {
        $admin = Admin::query()->firstOrFail();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();

        ShopSetting::put('pricing.tax_inclusive_input', true);
        $this->actingAs($admin, 'admin')
            ->get('/admin/products/'.$product->id.'/edit')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Catalog/Products/Form', false)
                ->where('pricingSettings.tax_inclusive_input', true));

        ShopSetting::put('pricing.tax_inclusive_input', false);
        $this->actingAs($admin, 'admin')
            ->get('/admin/products/'.$product->id.'/edit')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Catalog/Products/Form', false)
                ->where('pricingSettings.tax_inclusive_input', false));
    }

    public function test_decimal_precision_and_rounding_apply_to_money_and_unit_price(): void
    {
        ShopSetting::put('pricing.decimal_precision', 0);
        ShopSetting::put('pricing.rounding', 'up');
        $this->assertMatchesRegularExpression('/11(?:\.0+)?(?!\d)/', Money::format(10.01));
        $this->assertSame(11.0, PricingSettings::roundMoney(10.01));

        ShopSetting::put('pricing.rounding', 'down');
        $this->assertSame(10.0, PricingSettings::roundMoney(10.99));

        ShopSetting::put('pricing.decimal_precision', 3);
        ShopSetting::put('pricing.rounding', 'nearest');
        $this->assertStringContainsString('10.125', Money::format(10.1246));
        // Display keeps 3 places; cart/order money columns persist at 2.
        $this->assertSame(10.13, PricingSettings::roundMoney(10.1246));
    }

    public function test_fixed_and_percent_sale_prices_apply_when_live(): void
    {
        ShopSetting::put('pricing.sale_enabled', true);
        ShopSetting::put('pricing.compare_at_enabled', true);
        ShopSetting::put('pricing.storefront_display', 'without_tax');
        ShopSetting::put('pricing.tax_inclusive_input', false);

        $fixed = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $fixed->update([
            'price' => 100,
            'sale_price' => 75,
            'sale_price_type' => 'fixed',
            'sale_starts_at' => null,
            'sale_ends_at' => null,
            'compare_price' => null,
            'tax_class_id' => null,
        ]);
        $this->assertEqualsWithDelta(75.0, $fixed->fresh()->catalogSellingPrice(), 0.001);

        $row = app(CatalogPresenter::class)->product($fixed->fresh(), 'en', true);
        $this->assertEqualsWithDelta(75.0, (float) $row['price_catalog'], 0.05);
        $this->assertEqualsWithDelta(100.0, (float) $row['compare_price'], 0.05);
        $this->assertSame(25, $row['discount_percent']);
        $this->assertTrue($row['flags']['on_sale']);

        $percent = Product::query()->create([
            'type' => 'simple',
            'sku' => 'SALE-PCT-1',
            'price' => 200,
            'sale_price' => 10,
            'sale_price_type' => 'percent',
            'stock_qty' => 5,
            'is_enabled' => true,
            'manage_stock' => false,
            'available_for_order' => true,
            'visibility' => 'both',
        ]);
        $percent->translations()->create([
            'language_code' => 'en',
            'name' => 'Percent Sale',
            'slug' => 'sale-pct-1',
        ]);
        $this->assertEqualsWithDelta(180.0, $percent->fresh()->catalogSellingPrice(), 0.001);

        $this->postJson('/api/v1/cart', ['product_id' => $fixed->id, 'qty' => 1])->assertOk();
        $unit = (float) $this->getJson('/api/v1/cart')->json('cart.items.0.unit_price');
        $this->assertEqualsWithDelta(75.0, $unit, 0.001);
    }

    public function test_scheduled_expired_and_missing_sale_use_regular_price(): void
    {
        ShopSetting::put('pricing.sale_enabled', true);

        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $product->update([
            'price' => 50,
            'sale_price' => 20,
            'sale_price_type' => 'fixed',
            'sale_starts_at' => now()->addDays(3)->toDateString(),
            'sale_ends_at' => now()->addDays(10)->toDateString(),
        ]);
        $this->assertFalse($product->fresh()->saleIsLive());
        $this->assertEqualsWithDelta(50.0, $product->fresh()->catalogSellingPrice(), 0.001);

        $product->update([
            'sale_starts_at' => now()->subDays(10)->toDateString(),
            'sale_ends_at' => now()->subDays(1)->toDateString(),
        ]);
        $this->assertFalse($product->fresh()->saleIsLive());
        $this->assertEqualsWithDelta(50.0, $product->fresh()->catalogSellingPrice(), 0.001);

        $product->update([
            'sale_price' => null,
            'sale_starts_at' => null,
            'sale_ends_at' => null,
        ]);
        $this->assertFalse($product->fresh()->saleIsLive());
        $this->assertEqualsWithDelta(50.0, $product->fresh()->catalogSellingPrice(), 0.001);

        $row = app(CatalogPresenter::class)->product($product->fresh(), 'en', true);
        $this->assertFalse($row['flags']['on_sale']);
        $this->assertNull($row['compare_price']);
    }

    public function test_compare_at_on_shows_msrp_and_auto_compare_from_regular(): void
    {
        ShopSetting::put('pricing.sale_enabled', true);
        ShopSetting::put('pricing.compare_at_enabled', true);
        ShopSetting::put('pricing.storefront_display', 'without_tax');
        ShopSetting::put('pricing.tax_inclusive_input', false);

        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $product->update([
            'price' => 80,
            'compare_price' => 120,
            'sale_price' => 70,
            'sale_price_type' => 'fixed',
            'sale_starts_at' => null,
            'sale_ends_at' => null,
            'tax_class_id' => null,
        ]);

        $withMsrp = app(CatalogPresenter::class)->product($product->fresh(), 'en', true);
        $this->assertEqualsWithDelta(70.0, (float) $withMsrp['price_catalog'], 0.05);
        $this->assertEqualsWithDelta(120.0, (float) $withMsrp['compare_price'], 0.05);
        $this->assertNotNull($withMsrp['compare_price_formatted']);

        $product->update(['compare_price' => null]);
        $auto = app(CatalogPresenter::class)->product($product->fresh(), 'en', true);
        $this->assertEqualsWithDelta(80.0, (float) $auto['compare_price'], 0.05);
    }

    public function test_fixed_sale_above_regular_is_clamped_and_admin_rejects_invalid_sale(): void
    {
        ShopSetting::put('pricing.sale_enabled', true);

        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $product->update([
            'price' => 40,
            'sale_price' => 55,
            'sale_price_type' => 'fixed',
            'sale_starts_at' => null,
            'sale_ends_at' => null,
        ]);
        $this->assertEqualsWithDelta(40.0, $product->fresh()->catalogSellingPrice(), 0.001);

        $admin = Admin::query()->firstOrFail();
        $translation = $product->translation('en');
        $this->actingAs($admin, 'admin')
            ->from('/admin/products/'.$product->id.'/edit')
            ->put('/admin/products/'.$product->id, $this->withProductCategories([
                'type' => 'simple',
                'sku' => $product->sku,
                'price' => 40,
                'sale_price' => 55,
                'sale_price_type' => 'fixed',
                'name' => $translation?->name ?: 'Demo',
                'slug' => $translation?->slug ?: 'demo-001',
            ]))
            ->assertSessionHasErrors('sale_price');
    }

    public function test_admin_product_form_exposes_sale_and_compare_controls(): void
    {
        $form = (string) file_get_contents(resource_path('js/admin/Pages/Catalog/Products/Form.jsx'));
        $this->assertStringContainsString('Compare-at / MSRP', $form);
        $this->assertStringContainsString('compare_price', $form);
        $this->assertStringContainsString('salePricingEnabled', $form);
        $this->assertStringContainsString('compareAtEnabled', $form);
        $this->assertStringContainsString('Sale pricing is off in Product Settings', $form);
    }
}
