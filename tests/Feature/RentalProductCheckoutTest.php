<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductRentalAsset;
use App\Domain\Catalog\Services\CatalogPresenter;
use App\Domain\Catalog\Services\RentalProductService;
use App\Domain\Catalog\Support\ProductCsvSchema;
use App\Domain\Checkout\Services\CartService;
use App\Domain\Checkout\Services\CheckoutService;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\InvoiceDocument;
use App\Domain\Order\OrderStageService;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Domain\Tax\Models\TaxClass;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RentalProductCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_presenter_exposes_rental_config_and_preview_price(): void
    {
        $this->seed();
        $product = $this->makeRental([
            'sku' => 'RENT-PRES',
            'price' => 50,
            'rental' => [
                'rental_unit' => 'day',
                'min_duration' => 1,
                'max_duration' => 14,
                'default_duration' => 3,
                'pricing_model' => 'per_day',
                'security_deposit_type' => 'fixed',
                'security_deposit_value' => 100,
                'cleaning_fee' => 10,
                'inventory_mode' => 'quantity',
            ],
            'rental_pricing_tiers' => [
                ['min_duration' => 1, 'max_duration' => 2, 'unit' => 'day', 'price' => 40, 'position' => 0],
                ['min_duration' => 3, 'max_duration' => 7, 'unit' => 'day', 'price' => 90, 'position' => 1],
            ],
        ]);

        $data = app(CatalogPresenter::class)->product(
            $product->fresh()->load(['rentalSetting', 'rentalPricingTiers', 'rentalAssets']),
            'en',
            true,
        );
        $this->assertSame('rental', $data['type']);
        $this->assertTrue($data['requires_booking']);
        $this->assertTrue($data['requires_shipping']);
        $this->assertNotNull($data['rental']);
        $this->assertEquals(90.0, (float) $data['price']); // default duration 3 → tier
        $this->assertEquals(100.0, (float) $data['rental']['preview']['deposit']);
        $this->assertEquals(10.0, (float) $data['rental']['preview']['cleaning_fee']);
    }

    public function test_duration_validation_and_tier_pricing_on_cart(): void
    {
        $this->seed();
        Carbon::setTestNow(Carbon::parse('2026-09-29 12:00:00'));
        $product = $this->makeRental([
            'sku' => 'RENT-TIER',
            'price' => 50,
            'stock_qty' => 5,
            'rental' => [
                'rental_unit' => 'day',
                'min_duration' => 2,
                'max_duration' => 10,
                'default_duration' => 2,
                'pricing_model' => 'per_day',
                'security_deposit_type' => 'percent',
                'security_deposit_value' => 20,
                'cleaning_fee' => 5,
                'inventory_mode' => 'quantity',
            ],
            'rental_pricing_tiers' => [
                ['min_duration' => 2, 'max_duration' => 4, 'unit' => 'day', 'price' => 80, 'position' => 0],
                ['min_duration' => 5, 'max_duration' => 10, 'unit' => 'day', 'price' => 140, 'position' => 1],
            ],
        ]);

        $this->postJson('/api/v1/cart', [
            'product_id' => $product->id,
            'qty' => 1,
            'booking_date' => '2026-10-01',
            'rental_duration' => 1,
        ])->assertStatus(422);

        $this->postJson('/api/v1/cart', [
            'product_id' => $product->id,
            'qty' => 1,
            'booking_date' => '2026-09-20',
            'rental_duration' => 3,
        ])->assertStatus(422);

        $ok = $this->postJson('/api/v1/cart', [
            'product_id' => $product->id,
            'qty' => 1,
            'booking_date' => '2026-10-01',
            'booking_end_date' => '2026-10-03',
            'rental_duration' => 3,
        ])->assertOk();

        $item = $ok->json('cart.items.0');
        $this->assertSame('2026-10-01', $item['booking_date']);
        $this->assertSame('2026-10-03', $item['booking_end_date']);
        $this->assertEquals(3, (int) $item['rental_duration']);
        $this->assertEquals(80.0, (float) $item['unit_price']);
        $this->assertEquals(16.0, (float) ($item['rental_data']['deposit'] ?? 0)); // 20% of 80
        $this->assertEquals(5.0, (float) ($item['rental_data']['cleaning_fee'] ?? 0));
    }

    public function test_checkout_shipping_deposit_fees_and_cancel_releases_availability(): void
    {
        $this->seed();
        Carbon::setTestNow(Carbon::parse('2026-09-29 12:00:00'));
        $tax = TaxClass::query()->first();
        $product = $this->makeRental([
            'sku' => 'RENT-CHK',
            'price' => 40,
            'stock_qty' => 2,
            'tax_class_id' => $tax?->id,
            'rental' => [
                'rental_unit' => 'day',
                'min_duration' => 1,
                'max_duration' => 14,
                'default_duration' => 2,
                'pricing_model' => 'per_day',
                'security_deposit_type' => 'fixed',
                'security_deposit_value' => 50,
                'cleaning_fee' => 10,
                'inventory_mode' => 'quantity',
            ],
            'rental_pricing_tiers' => [],
        ]);

        $customer = Customer::factory()->create();
        $carts = app(CartService::class);
        $cart = $carts->current($customer);
        $carts->add(
            $cart,
            $product->fresh()->load(['rentalSetting', 'rentalPricingTiers']),
            1,
            [],
            [],
            null,
            [],
            null,
            '2026-10-05',
            null,
            null,
            null,
            '2026-10-06',
            2,
        );
        $totals = $carts->summary($cart->fresh(['items.product.rentalSetting']), 0, 'US', 'TX', $customer);
        $this->assertEquals(80.0, (float) $totals['subtotal']); // 40 * 2 days
        $this->assertEquals(50.0, (float) $totals['rental_deposit_total']);
        $this->assertEquals(10.0, (float) $totals['rental_cleaning_fee_total']);
        $this->assertGreaterThanOrEqual(60.0, (float) $totals['fee_total']);
        $this->assertTrue($product->requiresShipping());

        $pay = PaymentMethod::query()->where('is_enabled', true)->firstOrFail();
        $ship = ShippingMethod::query()->where('is_enabled', true)->value('id');
        $addr = [
            'first_name' => 'R', 'last_name' => 'A', 'line1' => '1 Main',
            'city' => 'Austin', 'state' => 'TX', 'postcode' => '78701', 'country_code' => 'US',
        ];
        $result = app(CheckoutService::class)->place($cart->fresh(['items.product']), [
            'email' => $customer->email,
            'payment_method_id' => $pay->id,
            'shipping_method_id' => $ship,
            'billing_address' => $addr,
            'shipping_address' => $addr,
        ], $customer);
        $order = $result['order']->fresh('items');
        $line = $order->items->first();
        $this->assertSame('2026-10-05', $line->booking_date?->toDateString());
        $this->assertSame('2026-10-06', $line->booking_end_date?->toDateString());
        $this->assertEquals(2, (int) $line->rental_duration);
        $this->assertEquals(80.0, (float) $line->unit_price);
        $this->assertEquals(50.0, (float) ($line->rental_data['deposit'] ?? 0));
        $this->assertSame('held', $line->rental_data['deposit_status'] ?? null);
        $this->assertEquals(2, (int) $product->fresh()->stock_qty); // calendar — stock not permanently deducted

        $invoice = app(InvoiceDocument::class)->payload($order);
        $this->assertSame('2026-10-05', $invoice['items'][0]['booking_date']);
        $this->assertSame('2026-10-06', $invoice['items'][0]['booking_end_date']);

        // Overlap with capacity 2 and qty 1 still OK for second booking same dates.
        $c2 = Customer::factory()->create();
        $cart2 = $carts->current($c2);
        $carts->add($cart2, $product->fresh()->load(['rentalSetting', 'rentalPricingTiers']), 1, [], [], null, [], null, '2026-10-05', null, null, null, '2026-10-06', 2);
        app(CheckoutService::class)->place($cart2->fresh(['items.product']), [
            'email' => $c2->email,
            'payment_method_id' => $pay->id,
            'shipping_method_id' => $ship,
            'billing_address' => $addr,
            'shipping_address' => $addr,
        ], $c2);

        // Third overlapping booking must fail (capacity 2).
        $c3 = Customer::factory()->create();
        $cart3 = $carts->current($c3);
        try {
            $carts->add($cart3, $product->fresh()->load(['rentalSetting', 'rentalPricingTiers']), 1, [], [], null, [], null, '2026-10-05', null, null, null, '2026-10-06', 2);
            $this->fail('Expected overlap capacity rejection');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('booking_date', $e->errors());
        }

        app(OrderStageService::class)->apply($order, 'cancelled', 'test cancel');
        $released = $order->items()->first()->fresh();
        $this->assertSame('released', $released->rental_data['deposit_status'] ?? null);

        // After cancel, a new overlapping booking fits again.
        $carts->add($cart3, $product->fresh()->load(['rentalSetting', 'rentalPricingTiers']), 1, [], [], null, [], null, '2026-10-05', null, null, null, '2026-10-06', 2);
        $this->assertTrue(true);
    }

    public function test_asset_mode_reserves_and_releases_on_cancel(): void
    {
        $this->seed();
        Carbon::setTestNow(Carbon::parse('2026-09-29 12:00:00'));
        $product = $this->makeRental([
            'sku' => 'RENT-ASSET',
            'price' => 25,
            'stock_qty' => 0,
            'manage_stock' => false,
            'rental' => [
                'rental_unit' => 'day',
                'min_duration' => 1,
                'max_duration' => 7,
                'default_duration' => 1,
                'pricing_model' => 'per_day',
                'security_deposit_type' => 'none',
                'inventory_mode' => 'asset',
            ],
            'rental_pricing_tiers' => [],
            'rental_assets' => [
                ['asset_id' => 'CAM-1', 'status' => 'available', 'position' => 0],
                ['asset_id' => 'CAM-2', 'status' => 'available', 'position' => 1],
            ],
        ]);

        $customer = Customer::factory()->create();
        $carts = app(CartService::class);
        $cart = $carts->current($customer);
        $carts->add($cart, $product->fresh()->load(['rentalSetting', 'rentalAssets']), 1, [], [], null, [], null, '2026-10-10', null, null, null, null, 1);
        $pay = PaymentMethod::query()->where('is_enabled', true)->firstOrFail();
        $ship = ShippingMethod::query()->where('is_enabled', true)->value('id');
        $addr = [
            'first_name' => 'R', 'last_name' => 'A', 'line1' => '1 Main',
            'city' => 'Austin', 'state' => 'TX', 'postcode' => '78701', 'country_code' => 'US',
        ];
        $result = app(CheckoutService::class)->place($cart->fresh(['items.product']), [
            'email' => $customer->email,
            'payment_method_id' => $pay->id,
            'shipping_method_id' => $ship,
            'billing_address' => $addr,
            'shipping_address' => $addr,
        ], $customer);
        $order = $result['order']->fresh('items');
        $assetIds = $order->items->first()->rental_data['assigned_asset_ids'] ?? [];
        $this->assertCount(1, $assetIds);
        $this->assertSame('rented', ProductRentalAsset::query()->find($assetIds[0])?->status);

        app(OrderStageService::class)->apply($order, 'cancelled', 'release asset');
        $this->assertSame('available', ProductRentalAsset::query()->find($assetIds[0])?->fresh()->status);
    }

    public function test_csv_round_trip_exports_rental_tiers_and_deposit(): void
    {
        $this->seed();
        $product = $this->makeRental([
            'sku' => 'RENT-CSV',
            'price' => 35,
            'rental' => [
                'rental_unit' => 'day',
                'min_duration' => 1,
                'max_duration' => 14,
                'default_duration' => 2,
                'pricing_model' => 'per_day',
                'security_deposit_type' => 'fixed',
                'security_deposit_value' => 100,
                'cleaning_fee' => 15,
                'inventory_mode' => 'quantity',
                'late_fee_type' => 'per_day',
                'late_fee_value' => 20,
            ],
            'rental_pricing_tiers' => [
                ['min_duration' => 1, 'max_duration' => 3, 'unit' => 'day', 'price' => 35, 'position' => 0],
                ['min_duration' => 4, 'max_duration' => 7, 'unit' => 'day', 'price' => 30, 'position' => 1],
            ],
        ]);

        $cols = ProductCsvSchema::columns();
        $vals = ProductCsvSchema::exportValues($product->fresh()->load(['rentalSetting', 'rentalPricingTiers', 'translations', 'categories', 'brand', 'suppliers', 'tags']), 'en');
        $map = array_combine($cols, $vals);
        $this->assertSame('day', $map['rental_unit']);
        $this->assertSame('fixed', $map['rental_security_deposit_type']);
        $this->assertNotSame('', (string) $map['rental_tiers']);
        $this->assertStringContainsString('1:3:day:35', (string) $map['rental_tiers']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makeRental(array $overrides): Product
    {
        $this->actingAs(Admin::query()->first(), 'admin');
        $payload = $this->withProductCategories(array_merge([
            'type' => 'rental',
            'name' => 'Rental '.$overrides['sku'],
            'slug' => strtolower($overrides['sku']),
            'sku' => $overrides['sku'],
            'price' => $overrides['price'] ?? 50,
            'manage_stock' => $overrides['manage_stock'] ?? true,
            'stock_qty' => $overrides['stock_qty'] ?? 10,
            'is_enabled' => true,
            'available_for_order' => true,
            'rental' => $overrides['rental'] ?? [],
            'rental_pricing_tiers' => $overrides['rental_pricing_tiers'] ?? [],
            'rental_assets' => $overrides['rental_assets'] ?? [],
        ], array_diff_key($overrides, array_flip(['rental', 'rental_pricing_tiers', 'rental_assets']))));

        $this->post('/admin/products', $payload)->assertProductSaveRedirect();

        return Product::query()->where('sku', $overrides['sku'])->firstOrFail();
    }
}
