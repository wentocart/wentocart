<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductQtyPrice;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave124Test extends TestCase
{
    use RefreshDatabase;

    public function test_customer_order_items_and_addresses(): void
    {
        $this->seed();

        $customer = Customer::factory()->create();
        $product = Product::query()->where('is_enabled', true)->firstOrFail();
        $order = Order::createTrusted([
            'customer_id' => $customer->id,
            'number' => 'W124-'.uniqid(),
            'email' => $customer->email,
            'status' => 'processing',
            'currency_code' => 'USD',
            'subtotal' => 40,
            'tax_total' => 0,
            'shipping_total' => 5,
            'discount_total' => 0,
            'grand_total' => 45,
            'payment_status' => 'paid',
            'billing_address' => [
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'line1' => '10 Analytical Engine Rd',
                'city' => 'London',
                'state' => 'LDN',
                'country_code' => 'GB',
                'postal_code' => 'SW1A',
                'phone' => '07000000000',
            ],
            'shipping_address' => [
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'line1' => '10 Analytical Engine Rd',
                'city' => 'London',
                'country_code' => 'GB',
                'postal_code' => 'SW1A',
            ],
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'name' => 'Wave124 Line',
            'qty' => 2,
            'unit_price' => 20,
            'total' => 40,
        ]);

        $response = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'query ($id: ID!) {
                customerOrder(id: $id) {
                    id itemsCount
                    billingAddress { firstName lastName address city country zipCode }
                    shippingAddress { firstName address city }
                    items(first: 10) {
                        totalCount
                        edges { node { productId productName sku quantity price totalPrice } }
                    }
                }
            }',
            'variables' => ['id' => (string) $order->id],
        ]);
        $response->assertOk();
        $this->assertArrayNotHasKey('errors', $response->json() ?? [], json_encode($response->json()));
        $this->assertSame(1, (int) $response->json('data.customerOrder.itemsCount'));
        $this->assertSame('Ada', (string) $response->json('data.customerOrder.billingAddress.firstName'));
        $this->assertSame('10 Analytical Engine Rd', (string) $response->json('data.customerOrder.billingAddress.address'));
        $this->assertSame('GB', (string) $response->json('data.customerOrder.billingAddress.country'));
        $this->assertSame(1, (int) $response->json('data.customerOrder.items.totalCount'));
        $this->assertSame('Wave124 Line', (string) $response->json('data.customerOrder.items.edges.0.node.productName'));
        $this->assertEqualsWithDelta(2.0, (float) $response->json('data.customerOrder.items.edges.0.node.quantity'), 0.001);
        $this->assertEqualsWithDelta(40.0, (float) $response->json('data.customerOrder.items.edges.0.node.totalPrice'), 0.001);
    }

    public function test_rest_create_customer_group_price_aliases(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $product = Product::query()->where('is_enabled', true)->firstOrFail();
        $base = (float) $product->price;

        $fixed = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($pid: ID!) {
                createCustomerGroupPrice(productId: $pid, qty: 5, valueType: "fixed", value: 12.5) {
                    id qty value valueType product_id
                }
            }',
            'variables' => ['pid' => (string) $product->id],
        ]);
        $fixed->assertOk();
        $this->assertArrayNotHasKey('errors', $fixed->json() ?? [], json_encode($fixed->json()));
        $this->assertSame(5.0, (float) $fixed->json('data.createCustomerGroupPrice.qty'));
        $this->assertSame(12.5, (float) $fixed->json('data.createCustomerGroupPrice.value'));
        $this->assertSame('fixed', (string) $fixed->json('data.createCustomerGroupPrice.valueType'));
        $tierId = (string) $fixed->json('data.createCustomerGroupPrice.id');

        $discount = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($pid: ID!) {
                createAdminCatalogProductCustomerGroupPrice(productId: $pid, qty: 10, valueType: "discount", value: 20) {
                    id qty valueType price value
                }
            }',
            'variables' => ['pid' => (string) $product->id],
        ]);
        $discount->assertOk();
        $this->assertArrayNotHasKey('errors', $discount->json() ?? [], json_encode($discount->json()));
        $expected = max(0, round($base * 0.8, 2));
        $this->assertEqualsWithDelta($expected, (float) $discount->json('data.createAdminCatalogProductCustomerGroupPrice.price'), 0.01);
        $this->assertSame('discount', (string) $discount->json('data.createAdminCatalogProductCustomerGroupPrice.valueType'));

        $del = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) { deleteCustomerGroupPrice(id: $id) { ok id } }',
            'variables' => ['id' => $tierId],
        ]);
        $del->assertOk();
        $this->assertArrayNotHasKey('errors', $del->json() ?? [], json_encode($del->json()));
        $this->assertTrue((bool) $del->json('data.deleteCustomerGroupPrice.ok'));
        $this->assertNull(ProductQtyPrice::query()->find((int) $tierId));
    }
}
