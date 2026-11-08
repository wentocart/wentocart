<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Settings\ShopSetting;
use App\Domain\Store\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave232Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_shop_customer_order_cancel_and_reorder_rest(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);

        $storeId = app(StoreContext::class)->requireId();
        $customer = Customer::factory()->create(['store_id' => $storeId]);
        $other = Customer::factory()->create(['store_id' => $storeId]);
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();

        $pending = Order::createTrusted([
            'number' => 'W232-PEND',
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'pending',
            'currency_code' => 'USD',
            'payment_status' => 'pending',
            'subtotal' => 29.99,
            'grand_total' => 29.99,
            'billing_address' => ['country_code' => 'US'],
            'shipping_address' => ['country_code' => 'US'],
        ]);
        $pending->items()->create([
            'product_id' => $product->id,
            'sku' => $product->sku,
            'name' => 'Demo',
            'qty' => 1,
            'unit_price' => 29.99,
            'total' => 29.99,
        ]);

        $done = Order::createTrusted([
            'number' => 'W232-DONE',
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'completed',
            'currency_code' => 'USD',
            'payment_status' => 'paid',
            'subtotal' => 29.99,
            'grand_total' => 29.99,
            'billing_address' => ['country_code' => 'US'],
            'shipping_address' => ['country_code' => 'US'],
        ]);
        $done->items()->create([
            'product_id' => $product->id,
            'sku' => $product->sku,
            'name' => 'Demo',
            'qty' => 2,
            'unit_price' => 14.99,
            'total' => 29.98,
        ]);

        $cancel = $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/store/customer-orders/'.$pending->id.'/cancel');
        $cancel->assertOk();
        $this->assertTrue((bool) $cancel->json('success'));
        $this->assertSame('canceled', $cancel->json('status'));
        $this->assertSame((int) $pending->id, (int) $cancel->json('orderId'));
        $this->assertSame('cancelled', (string) $pending->fresh()->status);

        $again = $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/store/customer-orders/'.$pending->id.'/cancel');
        $again->assertOk();
        $this->assertFalse((bool) $again->json('success'));

        $this->actingAs($other, 'customer')
            ->postJson('/api/v1/store/customer-orders/'.$done->id.'/cancel')
            ->assertNotFound();

        $reorder = $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/store/customer-orders/'.$done->id.'/reorder');
        $reorder->assertCreated();
        $this->assertTrue((bool) $reorder->json('success'));
        $this->assertGreaterThan(0, (int) $reorder->json('itemsAdded'));
        $this->assertNotEmpty((string) $reorder->json('cartToken'));
    }
}
