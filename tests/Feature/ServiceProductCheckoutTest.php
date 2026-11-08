<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\CatalogPresenter;
use App\Domain\Catalog\Services\ServiceProductService;
use App\Domain\Checkout\Services\CartService;
use App\Domain\Checkout\Services\CheckoutService;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\InvoiceDocument;
use App\Domain\Order\Models\Order;
use App\Domain\Order\OrderStageService;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Tax\Models\TaxClass;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ServiceProductCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_time_service_does_not_require_booking_date(): void
    {
        $this->seed();
        $product = $this->makeService([
            'sku' => 'SVC-ONCE',
            'price' => 50,
            'service' => [
                'service_type' => 'one_time',
                'pricing_model' => 'fixed',
                'delivery_method' => 'online',
            ],
        ]);

        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])
            ->assertOk()
            ->assertJsonPath('cart.items.0.booking_date', null);
    }

    public function test_catalog_presenter_exposes_service_config_and_pricing(): void
    {
        $this->seed();
        $product = $this->makeService([
            'sku' => 'SVC-HOUR',
            'price' => 100,
            'service' => [
                'service_type' => 'hourly',
                'duration' => 2,
                'duration_unit' => 'hours',
                'pricing_model' => 'per_hour',
                'delivery_method' => 'online',
            ],
        ]);

        $data = app(CatalogPresenter::class)->product($product->fresh()->load('serviceSetting'), 'en', true);
        $this->assertSame('service', $data['type']);
        $this->assertNotNull($data['service']);
        $this->assertTrue($data['requires_booking']);
        $this->assertSame('per_hour', $data['service']['pricing_model']);
        $this->assertEquals(200.0, (float) $data['price']);
    }

    public function test_per_day_pricing_and_travel_fee_on_checkout(): void
    {
        $this->seed();
        $tax = TaxClass::query()->first();
        $product = $this->makeService([
            'sku' => 'SVC-TRAVEL',
            'price' => 40,
            'tax_class_id' => $tax?->id,
            'service' => [
                'service_type' => 'hourly',
                'duration' => 2,
                'duration_unit' => 'days',
                'pricing_model' => 'per_day',
                'delivery_method' => 'onsite',
                'travel_fee' => 15,
                'service_area' => 'Austin',
            ],
        ]);

        $customer = Customer::factory()->create();
        $carts = app(CartService::class);
        $cart = $carts->current($customer);
        $carts->add($cart, $product->fresh()->load('serviceSetting'), 1, [], [], null, [], null, now()->addDays(3)->toDateString());
        $totals = $carts->summary($cart->fresh(['items.product.serviceSetting']), 0, 'US', 'TX', $customer);

        $this->assertEquals(80.0, (float) $totals['subtotal']); // 40 * 2 days
        $this->assertEquals(15.0, (float) $totals['travel_fee_total']);
        $this->assertGreaterThanOrEqual(15.0, (float) $totals['fee_total']);
        $this->assertEquals(0.0, (float) $totals['shipping']);

        $pay = PaymentMethod::query()->where('is_enabled', true)->firstOrFail();
        $result = app(CheckoutService::class)->place($cart->fresh(['items.product']), [
            'email' => $customer->email,
            'payment_method_id' => $pay->id,
            'billing_address' => [
                'first_name' => 'Svc',
                'last_name' => 'Test',
                'line1' => '1 Main',
                'city' => 'Austin',
                'state' => 'TX',
                'postcode' => '78701',
                'country_code' => 'US',
            ],
        ], $customer);

        /** @var Order $order */
        $order = $result['order'];
        $this->assertEquals(0.0, (float) $order->shipping_total);
        $this->assertGreaterThanOrEqual(15.0, (float) $order->fee_total);
        $item = $order->items()->first();
        $this->assertNotNull($item->booking_date);
        $this->assertEquals(80.0, (float) $item->unit_price);

        $invoice = app(InvoiceDocument::class)->payload($order->fresh('items'));
        $this->assertNotEmpty($invoice['items'][0]['booking_date']);
    }

    public function test_appointment_availability_rejects_wrong_weekday_and_respects_max_bookings(): void
    {
        $this->seed();
        Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:00')); // Monday
        $product = $this->makeService([
            'sku' => 'SVC-APPT',
            'price' => 75,
            'service' => [
                'service_type' => 'appointment',
                'duration' => 60,
                'duration_unit' => 'minutes',
                'pricing_model' => 'fixed',
                'delivery_method' => 'online',
                'availability_config' => [
                    'available_days' => ['mon', 'wed', 'fri'],
                    'start_time' => '09:00',
                    'end_time' => '17:00',
                    'min_advance_hours' => 2,
                    'max_advance_days' => 14,
                    'max_bookings' => 1,
                ],
            ],
        ]);

        // Tuesday not allowed
        $this->postJson('/api/v1/cart', [
            'product_id' => $product->id,
            'qty' => 1,
            'booking_date' => '2026-10-06',
        ])->assertStatus(422);

        // Too far ahead
        $this->postJson('/api/v1/cart', [
            'product_id' => $product->id,
            'qty' => 1,
            'booking_date' => '2026-11-02',
        ])->assertStatus(422);

        $okDate = '2026-10-07'; // Wednesday within 14 days
        $customer = Customer::factory()->create();
        $this->actingAs($customer, 'customer');
        $this->postJson('/api/v1/cart', [
            'product_id' => $product->id,
            'qty' => 1,
            'booking_date' => $okDate,
        ])->assertOk();

        $carts = app(CartService::class);
        $cart = $carts->current($customer);
        $this->assertGreaterThan(0, $cart->items()->count());
        $pay = PaymentMethod::query()->where('is_enabled', true)->firstOrFail();
        app(CheckoutService::class)->place($cart->fresh(['items.product']), [
            'email' => $customer->email,
            'payment_method_id' => $pay->id,
            'billing_address' => [
                'first_name' => 'A',
                'last_name' => 'B',
                'line1' => '1 Main',
                'city' => 'Austin',
                'state' => 'TX',
                'postcode' => '78701',
                'country_code' => 'US',
            ],
        ], $customer);

        // Second booking same day blocked by max_bookings=1
        $this->postJson('/api/v1/cart', [
            'product_id' => $product->id,
            'qty' => 1,
            'booking_date' => $okDate,
        ])->assertStatus(422);

        Carbon::setTestNow();
    }

    public function test_service_cancel_does_not_change_stock_and_starting_from_uses_base_price(): void
    {
        $this->seed();
        $product = $this->makeService([
            'sku' => 'SVC-FROM',
            'price' => 120,
            'manage_stock' => false,
            'stock_qty' => 0,
            'service' => [
                'service_type' => 'one_time',
                'pricing_model' => 'starting_from',
                'delivery_method' => 'online',
            ],
        ]);

        $customer = Customer::factory()->create();
        $carts = app(CartService::class);
        $cart = $carts->current($customer);
        $item = $carts->add($cart, $product->fresh()->load('serviceSetting'), 1);
        $this->assertEquals(120.0, (float) $item->unit_price);

        $pay = PaymentMethod::query()->where('is_enabled', true)->firstOrFail();
        $result = app(CheckoutService::class)->place($cart->fresh(['items.product']), [
            'email' => $customer->email,
            'payment_method_id' => $pay->id,
            'billing_address' => [
                'first_name' => 'A',
                'last_name' => 'B',
                'line1' => '1 Main',
                'city' => 'Austin',
                'state' => 'TX',
                'postcode' => '78701',
                'country_code' => 'US',
            ],
        ], $customer);
        $order = $result['order'];
        $before = (float) $product->fresh()->stock_qty;
        app(OrderStageService::class)->apply($order, 'cancelled', 'service test cancel');
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertEquals($before, (float) $product->fresh()->stock_qty);
    }

    public function test_csv_sample_imports_service_and_simple_unaffected(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
        $service = app(\App\Domain\Catalog\Services\ProductService::class);
        $csv = $service->sampleCsv(';', ',');
        $service->importCsv($csv, 'en', ';', ',');
        $row = Product::query()->where('sku', 'SVC-CONSULT-01')->with('serviceSetting')->first();
        $this->assertNotNull($row);
        $this->assertSame('appointment', $row->serviceSetting?->service_type);
        $this->assertNotNull(Product::query()->where('type', 'simple')->first());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makeService(array $overrides): Product
    {
        $this->actingAs(Admin::query()->first(), 'admin');
        $service = $overrides['service'] ?? [
            'service_type' => 'one_time',
            'pricing_model' => 'fixed',
            'delivery_method' => 'online',
        ];
        unset($overrides['service']);

        $payload = $this->withProductCategories(array_merge([
            'type' => 'service',
            'name' => $overrides['sku'] ?? 'Service',
            'slug' => strtolower((string) ($overrides['sku'] ?? 'service')).'-'.uniqid(),
            'sku' => 'SVC-TMP',
            'price' => 100,
            'is_enabled' => true,
            'available_for_order' => true,
            'show_price' => true,
            'manage_stock' => false,
            'service' => $service,
        ], $overrides, ['service' => $service]));

        $this->post('/admin/products', $payload)->assertProductSaveRedirect();

        return Product::query()->where('sku', $payload['sku'])->with('serviceSetting')->firstOrFail();
    }
}
