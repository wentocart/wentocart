<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Shipping\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave123Test extends TestCase
{
    use RefreshDatabase;

    public function test_currencies_connection_and_estimate_shipping_relay(): void
    {
        $this->seed();

        $geo = $this->postJson('/api/v1/graphql', [
            'query' => '{ currencies(first: 5) { totalCount edges { node { id code symbol is_default } } } }',
        ]);
        $geo->assertOk();
        $this->assertArrayNotHasKey('errors', $geo->json() ?? [], json_encode($geo->json()));
        $this->assertGreaterThanOrEqual(1, (int) $geo->json('data.currencies.totalCount'));

        $product = Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();

        $ship = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation {
                estimateShipping(input: { country: "US", state: "CA", zipCode: "90210" }) {
                    shippingMethods { totalCount edges { node { code title price } } }
                }
            }',
        ]);
        $ship->assertOk();
        $this->assertArrayNotHasKey('errors', $ship->json() ?? [], json_encode($ship->json()));
        $this->assertGreaterThanOrEqual(1, (int) $ship->json('data.estimateShipping.shippingMethods.totalCount'));
    }

    public function test_customer_order_shipments_connection(): void
    {
        $this->seed();

        $customer = Customer::factory()->create();
        $order = Order::createTrusted([
            'customer_id' => $customer->id,
            'number' => 'W123-'.uniqid(),
            'email' => $customer->email,
            'status' => 'processing',
            'currency_code' => 'USD',
            'subtotal' => 10,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'grand_total' => 10,
            'payment_status' => 'paid',
            'billing_address' => [
                'first_name' => 'Wave',
                'last_name' => '123',
                'line1' => '1 Test St',
                'city' => 'Austin',
                'country_code' => 'US',
            ],
            'shipping_address' => [
                'first_name' => 'Wave',
                'last_name' => '123',
                'line1' => '1 Test St',
                'city' => 'Austin',
                'country_code' => 'US',
            ],
        ]);

        Shipment::query()->create([
            'order_id' => $order->id,
            'status' => 'shipped',
            'carrier' => 'UPS',
            'tracking_number' => '1Z999W123',
        ]);

        $response = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'query ($id: ID!) {
                customerOrder(id: $id) {
                    id incrementId
                    shipments(first: 10) {
                        totalCount
                        edges { node { id carrierTitle trackNumber status } }
                    }
                }
            }',
            'variables' => ['id' => (string) $order->id],
        ]);
        $response->assertOk();
        $this->assertArrayNotHasKey('errors', $response->json() ?? [], json_encode($response->json()));
        $this->assertGreaterThanOrEqual(1, (int) $response->json('data.customerOrder.shipments.totalCount'));
        $this->assertSame('UPS', (string) $response->json('data.customerOrder.shipments.edges.0.node.carrierTitle'));
        $this->assertSame('1Z999W123', (string) $response->json('data.customerOrder.shipments.edges.0.node.trackNumber'));
    }
}
