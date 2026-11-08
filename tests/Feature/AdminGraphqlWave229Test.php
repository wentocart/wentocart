<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Settings\ShopSetting;
use App\Domain\Store\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave229Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_shop_customer_invoices_rest(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);
        ShopSetting::put('documents.invoices_enabled', true);

        $storeId = app(StoreContext::class)->requireId();
        $customer = Customer::factory()->create(['store_id' => $storeId]);
        $other = Customer::factory()->create(['store_id' => $storeId]);
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();

        $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/store/customer-invoices')
            ->assertOk()
            ->assertExactJson([]);

        $uninvoiced = Order::createTrusted([
            'number' => 'W229-PEND',
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'pending',
            'currency_code' => 'USD',
            'payment_status' => 'pending',
            'subtotal' => 10,
            'grand_total' => 10,
            'billing_address' => ['country_code' => 'US', 'first_name' => 'A', 'line1' => '1'],
            'shipping_address' => ['country_code' => 'US', 'first_name' => 'A', 'line1' => '1'],
        ]);

        $order = Order::createTrusted([
            'number' => 'W229-INV',
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'completed',
            'currency_code' => 'USD',
            'payment_status' => 'paid',
            'subtotal' => 29.99,
            'tax_total' => 0,
            'shipping_total' => 5,
            'discount_total' => 0,
            'grand_total' => 34.99,
            'billing_address' => [
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'line1' => '1 Main',
                'city' => 'Austin',
                'state' => 'TX',
                'country_code' => 'US',
                'postal_code' => '78701',
            ],
            'shipping_address' => [
                'first_name' => 'Ada',
                'line1' => '1 Main',
                'city' => 'Austin',
                'country_code' => 'US',
            ],
            'fulfillment_details' => ['invoice_number' => 'INV-W229-1'],
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'sku' => $product->sku,
            'name' => 'Demo',
            'qty' => 1,
            'unit_price' => 29.99,
            'total' => 29.99,
        ]);

        Order::createTrusted([
            'number' => 'W229-OTHER',
            'customer_id' => $other->id,
            'email' => $other->email,
            'status' => 'completed',
            'currency_code' => 'USD',
            'payment_status' => 'paid',
            'grand_total' => 5,
            'billing_address' => ['country_code' => 'US'],
            'shipping_address' => ['country_code' => 'US'],
            'fulfillment_details' => ['invoice_number' => 'INV-OTHER'],
        ]);

        $list = $this->actingAs($customer, 'customer')->getJson('/api/v1/store/customer-invoices');
        $list->assertOk();
        $this->assertCount(1, $list->json());
        $this->assertSame((int) $order->id, (int) $list->json('0.id'));
        $this->assertSame('INV-W229-1', $list->json('0.incrementId'));
        $this->assertSame('paid', $list->json('0.state'));
        $this->assertStringContainsString('/shop/customer-invoices/'.$order->id.'/pdf', (string) $list->json('0.downloadUrl'));

        $byOrder = $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/store/customer-invoices?order_id='.$order->id);
        $byOrder->assertOk()->assertJsonCount(1);

        $none = $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/store/customer-invoices?order_id='.$uninvoiced->id);
        $none->assertOk()->assertExactJson([]);

        $paid = $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/store/customer-invoices?state=paid');
        $paid->assertOk()->assertJsonCount(1);

        $detail = $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/store/customer-invoices/'.$order->id);
        $detail->assertOk();
        $this->assertSame(34.99, (float) $detail->json('grandTotal'));
        $this->assertNotEmpty($detail->json('items'));
        $this->assertNotEmpty($detail->json('addresses'));

        $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/store/customer-invoices/999999')
            ->assertNotFound()
            ->assertJsonPath('detail', 'Customer invoice with ID "999999" not found');

        $this->actingAs($other, 'customer')
            ->getJson('/api/v1/store/customer-invoices/'.$order->id)
            ->assertNotFound();

        $pdf = $this->actingAs($customer, 'customer')
            ->get('/api/v1/store/customer-invoices/'.$order->id.'/pdf');
        $pdf->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());
    }
}
