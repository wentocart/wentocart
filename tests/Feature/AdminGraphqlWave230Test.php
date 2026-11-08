<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Settings\ShopSetting;
use App\Domain\Shipping\Models\Shipment;
use App\Domain\Store\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave230Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_shop_customer_shipments_rest(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);

        $storeId = app(StoreContext::class)->requireId();
        $customer = Customer::factory()->create(['store_id' => $storeId]);
        $other = Customer::factory()->create(['store_id' => $storeId]);
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();

        $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/store/customer-shipments')
            ->assertOk()
            ->assertExactJson([]);

        $order = Order::createTrusted([
            'number' => 'W230-SHIP',
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'processing',
            'currency_code' => 'USD',
            'payment_status' => 'paid',
            'grand_total' => 40,
            'billing_address' => ['country_code' => 'US'],
            'shipping_address' => ['country_code' => 'US'],
        ]);
        $item = $order->items()->create([
            'product_id' => $product->id,
            'sku' => $product->sku,
            'name' => 'Demo',
            'qty' => 2,
            'unit_price' => 20,
            'total' => 40,
        ]);

        $shipment = Shipment::query()->create([
            'order_id' => $order->id,
            'status' => Shipment::STATUS_SHIPPED,
            'carrier' => 'FedEx',
            'tracking_number' => 'FX123',
            'tracking_url' => 'https://track.example/FX123',
            'shipped_at' => now(),
        ]);
        $shipment->items()->create([
            'order_item_id' => $item->id,
            'qty' => 2,
        ]);

        $foreign = Order::createTrusted([
            'number' => 'W230-OTHER',
            'customer_id' => $other->id,
            'email' => $other->email,
            'status' => 'processing',
            'currency_code' => 'USD',
            'payment_status' => 'paid',
            'grand_total' => 10,
            'billing_address' => ['country_code' => 'US'],
            'shipping_address' => ['country_code' => 'US'],
        ]);
        Shipment::query()->create([
            'order_id' => $foreign->id,
            'status' => Shipment::STATUS_SHIPPED,
            'carrier' => 'UPS',
            'tracking_number' => 'UP999',
        ]);

        $list = $this->actingAs($customer, 'customer')->getJson('/api/v1/store/customer-shipments');
        $list->assertOk();
        $this->assertCount(1, $list->json());
        $this->assertSame((int) $shipment->id, (int) $list->json('0.id'));
        $this->assertSame('shipped', $list->json('0.status'));
        $this->assertSame('FX123', $list->json('0.trackNumber'));
        $this->assertSame('FedEx', $list->json('0.carrierTitle'));
        $this->assertSame(2, (int) $list->json('0.totalQty'));

        $byOrder = $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/store/customer-shipments?order_id='.$order->id);
        $byOrder->assertOk()->assertJsonCount(1);

        $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/store/customer-shipments?order_id='.$foreign->id)
            ->assertNotFound();

        $detail = $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/store/customer-shipments/'.$shipment->id);
        $detail->assertOk();
        $this->assertSame('SHP-'.$shipment->id, $detail->json('shippingNumber'));
        $this->assertNotEmpty($detail->json('items'));
        $this->assertSame('Demo', $detail->json('items.0.name'));

        $this->actingAs($other, 'customer')
            ->getJson('/api/v1/store/customer-shipments/'.$shipment->id)
            ->assertNotFound()
            ->assertJsonPath('detail', 'Customer shipment with ID "'.$shipment->id.'" not found');
    }
}
