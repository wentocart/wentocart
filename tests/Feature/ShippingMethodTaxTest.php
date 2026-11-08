<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Order\Models\Order;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Settings\ShopSetting;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Domain\Tax\Models\TaxClass;
use App\Domain\Tax\Models\TaxRate;
use App\Domain\Tax\Models\TaxRule;
use App\Domain\Tax\Models\TaxZone;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShippingMethodTaxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /**
     * @return array{0: TaxClass, 1: TaxClass}
     */
    protected function makeTaxClasses(float $productRate = 10, float $shippingRate = 20): array
    {
        $productClass = TaxClass::query()->create(['name' => 'Product VAT '.$productRate]);
        $shippingClass = TaxClass::query()->create(['name' => 'Shipping VAT '.$shippingRate]);
        $zone = TaxZone::query()->create(['name' => 'WW ship tax', 'is_enabled' => true, 'position' => 90]);
        $productTaxRate = TaxRate::query()->create(['name' => 'P'.$productRate, 'rate' => $productRate, 'is_enabled' => true]);
        $shippingTaxRate = TaxRate::query()->create(['name' => 'S'.$shippingRate, 'rate' => $shippingRate, 'is_enabled' => true]);
        TaxRule::query()->create([
            'tax_class_id' => $productClass->id,
            'tax_zone_id' => $zone->id,
            'tax_rate_id' => $productTaxRate->id,
            'name' => 'Product '.$productRate,
            'rate' => $productRate,
            'priority' => 1,
            'is_compound' => false,
            'is_enabled' => true,
        ]);
        TaxRule::query()->create([
            'tax_class_id' => $shippingClass->id,
            'tax_zone_id' => $zone->id,
            'tax_rate_id' => $shippingTaxRate->id,
            'name' => 'Shipping '.$shippingRate,
            'rate' => $shippingRate,
            'priority' => 1,
            'is_compound' => false,
            'is_enabled' => true,
        ]);

        return [$productClass, $shippingClass];
    }

    public function test_admin_can_persist_shipping_method_tax_class_when_shipping_tax_enabled(): void
    {
        $admin = Admin::query()->firstOrFail();
        [$productClass, $shippingClass] = $this->makeTaxClasses();
        ShopSetting::put('tax.shipping', true);

        $this->actingAs($admin, 'admin')
            ->get('/admin/shipping/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shipping/Form', false)
                ->where('shipping_tax_enabled', true)
                ->has('options.tax_classes')
                ->where('options.tax_classes', fn ($rows) => collect($rows)->contains(fn ($row) => (int) $row['id'] === (int) $shippingClass->id)));

        $this->actingAs($admin, 'admin')->post('/admin/shipping', [
            'name' => 'Taxed Express',
            'description' => 'With shipping tax class',
            'type' => 'flat',
            'driver' => 'offline',
            'price' => 15,
            'tax_class_id' => $shippingClass->id,
            'is_enabled' => true,
            'conditions' => [],
            'tiers' => [],
        ])->assertRedirect('/admin/shipping');

        $method = ShippingMethod::query()->where('name', 'Taxed Express')->firstOrFail();
        $this->assertSame((int) $shippingClass->id, (int) $method->tax_class_id);

        $this->actingAs($admin, 'admin')
            ->get('/admin/shipping/'.$method->id.'/edit')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shipping/Form', false)
                ->where('shipping_tax_enabled', true)
                ->where('method.tax_class_id', $shippingClass->id));

        $this->actingAs($admin, 'admin')->put('/admin/shipping/'.$method->id, [
            'name' => 'Taxed Express',
            'description' => 'Updated',
            'type' => 'flat',
            'driver' => 'offline',
            'price' => 15,
            'tax_class_id' => $productClass->id,
            'is_enabled' => true,
            'conditions' => [],
            'tiers' => [],
        ])->assertRedirect('/admin/shipping');

        $this->assertSame((int) $productClass->id, (int) $method->fresh()->tax_class_id);

        $this->actingAs($admin, 'admin')->put('/admin/shipping/'.$method->id, [
            'name' => 'Taxed Express',
            'description' => 'Cleared tax',
            'type' => 'flat',
            'driver' => 'offline',
            'price' => 15,
            'tax_class_id' => '',
            'is_enabled' => true,
            'conditions' => [],
            'tiers' => [],
        ])->assertRedirect('/admin/shipping');

        $this->assertNull($method->fresh()->tax_class_id);
    }

    public function test_tax_selector_hidden_on_create_and_edit_when_shipping_tax_disabled(): void
    {
        $admin = Admin::query()->firstOrFail();
        [, $shippingClass] = $this->makeTaxClasses();
        ShopSetting::put('tax.shipping', false);

        $method = ShippingMethod::query()->create([
            'code' => 'hide-tax-selector',
            'name' => 'Hide tax selector',
            'type' => 'flat',
            'driver' => 'offline',
            'price' => 8,
            'tax_class_id' => $shippingClass->id,
            'is_enabled' => true,
            'position' => 88,
            'conditions' => ShippingMethod::emptyConditions(),
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin/shipping/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shipping/Form', false)
                ->where('shipping_tax_enabled', false)
                ->where('options.tax_classes', []));

        $this->actingAs($admin, 'admin')
            ->get('/admin/shipping/'.$method->id.'/edit')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shipping/Form', false)
                ->where('shipping_tax_enabled', false)
                ->where('options.tax_classes', [])
                ->where('method.tax_class_id', $shippingClass->id));
    }

    public function test_disabled_shipping_tax_ignores_tax_class_updates_and_preserves_stored_value(): void
    {
        $admin = Admin::query()->firstOrFail();
        [$productClass, $shippingClass] = $this->makeTaxClasses();
        ShopSetting::put('tax.shipping', false);

        $method = ShippingMethod::query()->create([
            'code' => 'preserve-tax-class',
            'name' => 'Preserve tax class',
            'type' => 'flat',
            'driver' => 'offline',
            'price' => 9,
            'tax_class_id' => $shippingClass->id,
            'is_enabled' => true,
            'position' => 87,
            'conditions' => ShippingMethod::emptyConditions(),
        ]);

        $this->actingAs($admin, 'admin')->put('/admin/shipping/'.$method->id, [
            'name' => 'Preserve tax class',
            'description' => 'Attempt overwrite',
            'type' => 'flat',
            'driver' => 'offline',
            'price' => 9,
            'tax_class_id' => $productClass->id,
            'is_enabled' => true,
            'conditions' => [],
            'tiers' => [],
        ])->assertRedirect('/admin/shipping');

        $this->assertSame((int) $shippingClass->id, (int) $method->fresh()->tax_class_id);
    }

    public function test_enabled_with_tax_class_calculates_shipping_tax(): void
    {
        [$productClass, $shippingClass] = $this->makeTaxClasses(10, 20);
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $product->update(['tax_class_id' => $productClass->id, 'price' => 100, 'sale_price' => null]);

        $shipping = ShippingMethod::query()->firstOrFail();
        $shipping->update([
            'type' => 'flat',
            'price' => 10,
            'is_enabled' => true,
            'tax_class_id' => $shippingClass->id,
        ]);

        ShopSetting::put('pricing.tax_inclusive_input', false);
        ShopSetting::put('tax.shipping', true);
        ShopSetting::put('products.default_tax_class_id', $productClass->id);

        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $totals = $this->getJson('/api/v1/cart?country_code=US&shipping_method_id='.$shipping->id)
            ->assertOk()
            ->json('totals');

        // Product tax 10 on 100 + shipping tax 20 on 10
        $this->assertEqualsWithDelta(10.0, (float) $totals['shipping'], 0.01);
        $this->assertEqualsWithDelta(2.0, (float) $totals['shipping_tax'], 0.05);
        $this->assertEqualsWithDelta(12.0, (float) $totals['tax'], 0.05);
        $this->assertEqualsWithDelta(122.0, (float) $totals['grand_total'], 0.05);
    }

    public function test_enabled_without_tax_class_does_not_fallback_to_product_or_default(): void
    {
        [$productClass] = $this->makeTaxClasses(10, 20);
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $product->update(['tax_class_id' => $productClass->id, 'price' => 100, 'sale_price' => null]);

        $shipping = ShippingMethod::query()->firstOrFail();
        $shipping->update([
            'type' => 'flat',
            'price' => 10,
            'is_enabled' => true,
            'tax_class_id' => null,
        ]);

        ShopSetting::put('pricing.tax_inclusive_input', false);
        ShopSetting::put('tax.shipping', true);
        ShopSetting::put('products.default_tax_class_id', $productClass->id);

        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $totals = $this->getJson('/api/v1/cart?country_code=US&shipping_method_id='.$shipping->id)
            ->assertOk()
            ->json('totals');

        $this->assertEqualsWithDelta(0.0, (float) $totals['shipping_tax'], 0.01);
        $this->assertEqualsWithDelta(10.0, (float) $totals['tax'], 0.05);
        $this->assertEqualsWithDelta(120.0, (float) $totals['grand_total'], 0.05);
    }

    public function test_disabled_with_saved_tax_class_charges_no_shipping_tax(): void
    {
        [$productClass, $shippingClass] = $this->makeTaxClasses(10, 20);
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $product->update(['tax_class_id' => $productClass->id, 'price' => 100, 'sale_price' => null]);
        $shipping = ShippingMethod::query()->firstOrFail();
        $shipping->update(['price' => 10, 'is_enabled' => true, 'tax_class_id' => $shippingClass->id]);

        ShopSetting::put('pricing.tax_inclusive_input', false);
        ShopSetting::put('tax.shipping', false);

        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $totals = $this->getJson('/api/v1/cart?country_code=US&shipping_method_id='.$shipping->id)
            ->assertOk()
            ->json('totals');

        $this->assertEqualsWithDelta(0.0, (float) $totals['shipping_tax'], 0.01);
        $this->assertEqualsWithDelta(10.0, (float) $totals['tax'], 0.05);
        $this->assertEqualsWithDelta(120.0, (float) $totals['grand_total'], 0.05);
        $this->assertSame((int) $shippingClass->id, (int) $shipping->fresh()->tax_class_id);
    }

    public function test_re_enabling_shipping_tax_uses_explicit_method_tax_class(): void
    {
        [$productClass, $shippingClass] = $this->makeTaxClasses(10, 20);
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $product->update(['tax_class_id' => $productClass->id, 'price' => 100, 'sale_price' => null]);
        $shipping = ShippingMethod::query()->firstOrFail();
        $shipping->update(['price' => 10, 'is_enabled' => true, 'tax_class_id' => $shippingClass->id]);

        ShopSetting::put('pricing.tax_inclusive_input', false);
        ShopSetting::put('tax.shipping', false);
        ShopSetting::put('products.default_tax_class_id', $productClass->id);

        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $off = $this->getJson('/api/v1/cart?country_code=US&shipping_method_id='.$shipping->id)->json('totals');
        $this->assertEqualsWithDelta(0.0, (float) $off['shipping_tax'], 0.01);

        ShopSetting::put('tax.shipping', true);
        $on = $this->getJson('/api/v1/cart?country_code=US&shipping_method_id='.$shipping->id)->json('totals');
        $this->assertEqualsWithDelta(2.0, (float) $on['shipping_tax'], 0.05);
        $this->assertEqualsWithDelta(12.0, (float) $on['tax'], 0.05);
        $this->assertEqualsWithDelta(122.0, (float) $on['grand_total'], 0.05);
    }

    public function test_product_tax_unchanged_when_shipping_tax_disabled(): void
    {
        [$productClass, $shippingClass] = $this->makeTaxClasses(10, 20);
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $product->update(['tax_class_id' => $productClass->id, 'price' => 100, 'sale_price' => null]);
        $shipping = ShippingMethod::query()->firstOrFail();
        $shipping->update(['price' => 10, 'is_enabled' => true, 'tax_class_id' => $shippingClass->id]);

        ShopSetting::put('pricing.tax_inclusive_input', false);
        ShopSetting::put('tax.shipping', false);

        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $totals = $this->getJson('/api/v1/cart?country_code=US&shipping_method_id='.$shipping->id)
            ->assertOk()
            ->json('totals');

        $this->assertEqualsWithDelta(10.0, (float) $totals['tax'], 0.05);
        $this->assertEqualsWithDelta(100.0, (float) $totals['subtotal'], 0.05);
    }

    public function test_free_shipping_has_zero_shipping_tax(): void
    {
        [$productClass, $shippingClass] = $this->makeTaxClasses(10, 20);
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $product->update(['tax_class_id' => $productClass->id, 'price' => 50, 'sale_price' => null]);
        $shipping = ShippingMethod::query()->create([
            'code' => 'free-tax-test',
            'name' => 'Free taxed class',
            'type' => 'free',
            'driver' => 'offline',
            'price' => 0,
            'tax_class_id' => $shippingClass->id,
            'is_enabled' => true,
            'position' => 99,
            'conditions' => ShippingMethod::emptyConditions(),
        ]);

        ShopSetting::put('pricing.tax_inclusive_input', false);
        ShopSetting::put('tax.shipping', true);

        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $totals = $this->getJson('/api/v1/cart?country_code=US&shipping_method_id='.$shipping->id)
            ->assertOk()
            ->json('totals');

        $this->assertEqualsWithDelta(0.0, (float) $totals['shipping'], 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $totals['shipping_tax'], 0.01);
    }

    public function test_tax_inclusive_shipping_does_not_double_count_on_grand_total(): void
    {
        [$productClass, $shippingClass] = $this->makeTaxClasses(10, 20);
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $product->update(['tax_class_id' => $productClass->id, 'price' => 110, 'sale_price' => null]);
        $shipping = ShippingMethod::query()->firstOrFail();
        $shipping->update(['price' => 12, 'is_enabled' => true, 'tax_class_id' => $shippingClass->id]);

        ShopSetting::put('pricing.tax_inclusive_input', true);
        ShopSetting::put('tax.shipping', true);

        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $totals = $this->getJson('/api/v1/cart?country_code=US&shipping_method_id='.$shipping->id)
            ->assertOk()
            ->json('totals');

        // Inclusive: product 110 (tax 10) + shipping 12 (tax 2) → grand 122, not 124.
        $this->assertEqualsWithDelta(2.0, (float) $totals['shipping_tax'], 0.05);
        $this->assertEqualsWithDelta(12.0, (float) $totals['tax'], 0.05);
        $this->assertEqualsWithDelta(122.0, (float) $totals['grand_total'], 0.05);
    }

    public function test_checkout_order_persists_shipping_tax_in_tax_total(): void
    {
        [$productClass, $shippingClass] = $this->makeTaxClasses(10, 20);
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $product->update(['tax_class_id' => $productClass->id, 'price' => 100, 'sale_price' => null]);
        $payment = PaymentMethod::query()->firstOrFail();
        $shipping = ShippingMethod::query()->firstOrFail();
        $shipping->update(['price' => 10, 'is_enabled' => true, 'tax_class_id' => $shippingClass->id]);

        ShopSetting::put('pricing.tax_inclusive_input', false);
        ShopSetting::put('tax.shipping', true);

        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', [
            'email' => 'ship-tax@example.com',
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Ada', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ])->assertCreated();

        $order = Order::query()->where('email', 'ship-tax@example.com')->firstOrFail();
        $this->assertEqualsWithDelta(10.0, (float) $order->shipping_total, 0.05);
        $this->assertEqualsWithDelta(12.0, (float) $order->tax_total, 0.05);
        $this->assertEqualsWithDelta(122.0, (float) $order->grand_total, 0.05);
        $this->assertNotEmpty($order->tax_details);
    }

    public function test_shipping_csv_sample_includes_and_imports_tax_class_when_enabled(): void
    {
        $admin = Admin::query()->firstOrFail();
        TaxClass::query()->firstOrCreate(['name' => 'Standard']);
        ShopSetting::put('tax.shipping', true);

        $csv = $this->actingAs($admin, 'admin')
            ->get('/admin/shipping/import/sample')
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString('tax_class', explode("\n", $csv)[0]);

        $this->actingAs($admin, 'admin')->post('/admin/shipping/import', [
            'file' => UploadedFile::fake()->createWithContent('shipping.csv', $csv),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $imported = ShippingMethod::query()->where('code', 'csv-flat')->firstOrFail();
        $this->assertNotNull($imported->tax_class_id);
        $this->assertSame('Standard', $imported->taxClass?->name);
    }

    public function test_mixed_methods_use_each_methods_tax_class(): void
    {
        [$productClass, $shippingClass] = $this->makeTaxClasses(0, 20);
        $zeroClass = TaxClass::query()->create(['name' => 'Zero ship']);
        $zone = TaxZone::query()->where('name', 'WW ship tax')->firstOrFail();
        $rate = TaxRate::query()->create(['name' => 'Zero', 'rate' => 0, 'is_enabled' => true]);
        TaxRule::query()->create([
            'tax_class_id' => $zeroClass->id,
            'tax_zone_id' => $zone->id,
            'tax_rate_id' => $rate->id,
            'name' => 'Zero',
            'rate' => 0,
            'priority' => 1,
            'is_compound' => false,
            'is_enabled' => true,
        ]);

        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $product->update(['tax_class_id' => $productClass->id, 'price' => 40, 'sale_price' => null]);

        $taxed = ShippingMethod::query()->create([
            'code' => 'taxed-method',
            'name' => 'Taxed method',
            'type' => 'flat',
            'driver' => 'offline',
            'price' => 10,
            'tax_class_id' => $shippingClass->id,
            'is_enabled' => true,
            'position' => 1,
            'conditions' => ShippingMethod::emptyConditions(),
        ]);
        $untaxed = ShippingMethod::query()->create([
            'code' => 'untaxed-method',
            'name' => 'Untaxed method',
            'type' => 'flat',
            'driver' => 'offline',
            'price' => 10,
            'tax_class_id' => null,
            'is_enabled' => true,
            'position' => 2,
            'conditions' => ShippingMethod::emptyConditions(),
        ]);

        ShopSetting::put('pricing.tax_inclusive_input', false);
        ShopSetting::put('tax.shipping', true);

        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $taxedTotals = $this->getJson('/api/v1/cart?country_code=US&shipping_method_id='.$taxed->id)->json('totals');
        $untaxedTotals = $this->getJson('/api/v1/cart?country_code=US&shipping_method_id='.$untaxed->id)->json('totals');

        $this->assertEqualsWithDelta(2.0, (float) $taxedTotals['shipping_tax'], 0.05);
        $this->assertEqualsWithDelta(0.0, (float) $untaxedTotals['shipping_tax'], 0.05);
        $this->assertGreaterThan((float) $untaxedTotals['grand_total'], (float) $taxedTotals['grand_total']);
    }
}
