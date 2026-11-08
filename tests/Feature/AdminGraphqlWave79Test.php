<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Shipping\Models\Shipment;
use App\Domain\Store\StoreContext;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AdminGraphqlWave79Test extends TestCase
{
    use RefreshDatabase;

    public function test_shipments_refunds_products_csv_exports(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $storeId = app(StoreContext::class)->requireId();
        $customer = Customer::factory()->create(['store_id' => $storeId]);
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();

        $order = Order::createTrusted([
            'number' => 'W79-EXP-1',
            'customer_id' => $customer->id,
            'email' => 'wave79@example.com',
            'status' => 'processing',
            'currency_code' => 'USD',
            'payment_status' => 'partially_refunded',
            'grand_total' => 100,
            'refunded_total' => 25,
            'billing_address' => ['country_code' => 'US', 'first_name' => 'Wave', 'line1' => '1 Main'],
            'shipping_address' => ['country_code' => 'US', 'first_name' => 'Wave', 'line1' => '1 Main'],
        ]);

        Shipment::query()->create([
            'order_id' => $order->id,
            'status' => Shipment::STATUS_PROCESSING,
            'carrier' => 'Wave79 Carrier',
            'tracking_number' => 'W79TRACK1',
        ]);

        $shipCsv = $this->actingAs($admin, 'admin')->get('/api/v1/console/shipments/export');
        $shipCsv->assertOk();
        $this->assertStringContainsString('text/csv', (string) $shipCsv->headers->get('content-type'));
        $this->assertStringContainsString('W79TRACK1', $shipCsv->streamedContent());

        $refundCsv = $this->actingAs($admin, 'admin')->get('/api/v1/console/refunds/export');
        $refundCsv->assertOk();
        $this->assertStringContainsString('W79-EXP-1', $refundCsv->streamedContent());

        $prodCsv = $this->actingAs($admin, 'admin')->get('/api/v1/console/products/export?sku=DEMO');
        $prodCsv->assertOk();
        $body = $prodCsv->streamedContent();
        $this->assertStringContainsString('DEMO-001', $body);
        $this->assertStringContainsString((string) $product->sku, $body);

        Auth::guard('admin')->logout();
        $this->app['auth']->forgetGuards();
        $this->get('/api/v1/console/refunds/export')->assertUnauthorized();
        $this->get('/api/v1/console/products/export')->assertUnauthorized();
    }
}
