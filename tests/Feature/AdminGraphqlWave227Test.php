<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Settings\ShopSetting;
use App\Domain\Store\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AdminGraphqlWave227Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_shop_customer_orders_rest(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);

        $storeId = app(StoreContext::class)->requireId();
        $customer = Customer::factory()->create([
            'store_id' => $storeId,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
        ]);
        $other = Customer::factory()->create(['store_id' => $storeId]);
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();

        $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/store/customer-orders')
            ->assertOk()
            ->assertExactJson([]);

        $pending = Order::createTrusted([
            'number' => 'W227-PENDING',
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'pending',
            'currency_code' => 'USD',
            'payment_status' => 'pending',
            'subtotal' => 29.99,
            'tax_total' => 0,
            'shipping_total' => 5,
            'discount_total' => 0,
            'grand_total' => 34.99,
            'billing_address' => [
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'line1' => '1 Main St',
                'city' => 'Austin',
                'state' => 'TX',
                'country_code' => 'US',
                'postal_code' => '78701',
                'phone' => '+15551212',
            ],
            'shipping_address' => [
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'line1' => '1 Main St',
                'city' => 'Austin',
                'state' => 'TX',
                'country_code' => 'US',
                'postal_code' => '78701',
                'phone' => '+15551212',
            ],
        ]);
        $pending->items()->create([
            'product_id' => $product->id,
            'sku' => $product->sku,
            'name' => 'Demo',
            'qty' => 1,
            'unit_price' => 29.99,
            'total' => 29.99,
        ]);

        $cancelled = Order::createTrusted([
            'number' => 'W227-CANCEL',
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'cancelled',
            'currency_code' => 'USD',
            'payment_status' => 'pending',
            'subtotal' => 10,
            'grand_total' => 10,
            'billing_address' => ['first_name' => 'Ada', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Ada', 'country_code' => 'US'],
        ]);
        $cancelled->items()->create([
            'product_id' => $product->id,
            'sku' => $product->sku,
            'name' => 'Demo',
            'qty' => 1,
            'unit_price' => 10,
            'total' => 10,
        ]);

        Order::createTrusted([
            'number' => 'W227-OTHER',
            'customer_id' => $other->id,
            'email' => $other->email,
            'status' => 'pending',
            'currency_code' => 'USD',
            'payment_status' => 'pending',
            'subtotal' => 5,
            'grand_total' => 5,
            'billing_address' => ['country_code' => 'US'],
            'shipping_address' => ['country_code' => 'US'],
        ]);

        $list = $this->actingAs($customer, 'customer')->getJson('/api/v1/store/customer-orders');
        $list->assertOk();
        $this->assertIsArray($list->json());
        $this->assertCount(2, $list->json());
        $this->assertSame((int) $cancelled->id, (int) $list->json('0.id'));
        $this->assertSame('canceled', $list->json('0.status'));
        $this->assertSame('W227-CANCEL', $list->json('0.incrementId'));
        $this->assertSame((int) $pending->id, (int) $list->json('1.id'));
        $this->assertSame(34.99, (float) $list->json('1.grandTotal'));

        $filtered = $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/store/customer-orders?status=pending');
        $filtered->assertOk();
        $this->assertCount(1, $filtered->json());
        $this->assertSame('W227-PENDING', $filtered->json('0.incrementId'));

        $canceledFilter = $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/store/customer-orders?status=canceled');
        $canceledFilter->assertOk();
        $this->assertCount(1, $canceledFilter->json());
        $this->assertSame('canceled', $canceledFilter->json('0.status'));

        $detail = $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/store/customer-orders/'.$pending->id);
        $detail->assertOk();
        $this->assertSame('W227-PENDING', $detail->json('incrementId'));
        $this->assertSame('Ada', $detail->json('customerFirstName'));
        $this->assertNotEmpty($detail->json('items'));
        $this->assertSame($product->id, (int) $detail->json('items.0.productId'));
        $this->assertSame(1, (int) $detail->json('items.0.qtyOrdered'));
        $types = collect($detail->json('addresses'))->pluck('addressType')->all();
        $this->assertContains('order_billing', $types);
        $this->assertContains('order_shipping', $types);
        $this->assertIsArray($detail->json('shipments'));

        $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/store/customer-orders/999999')
            ->assertNotFound()
            ->assertJsonPath('detail', 'Customer order with ID "999999" not found');

        $this->actingAs($other, 'customer')
            ->getJson('/api/v1/store/customer-orders/'.$pending->id)
            ->assertNotFound();

        Auth::guard('customer')->logout();
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $guest = $this->withHeaders(['Authorization' => ''])->getJson('/api/v1/store/customer-orders');
        $this->assertContains($guest->status(), [401, 403], (string) $guest->getContent());
    }
}
