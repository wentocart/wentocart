<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave38Test extends TestCase
{
    use RefreshDatabase;

    public function test_create_admin_order_for_customer(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $product = Product::query()->where('is_enabled', true)->where('price', '>', 0)->first();
        $this->assertNotNull($product);
        $product->update(['stock_qty' => max(5, (float) $product->stock_qty), 'manage_stock' => true]);

        $email = 'w38-'.uniqid().'@example.com';
        $customerRes = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($e: String!) {
                createCustomer(email: $e, firstName: "Wave", lastName: "ThirtyEight") { id email }
            }',
            'variables' => ['e' => $email],
        ]);
        $customerRes->assertOk();
        $this->assertArrayNotHasKey('errors', $customerRes->json() ?? []);
        $customerId = (string) $customerRes->json('data.createCustomer.id');

        $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!) {
                createCustomerAddress(
                    customerId: $cid
                    firstName: "Wave"
                    lastName: "ThirtyEight"
                    line1: "38 Admin Way"
                    city: "Dallas"
                    state: "TX"
                    postalCode: "75201"
                    countryCode: "US"
                    defaultBilling: true
                    defaultShipping: true
                ) { id }
            }',
            'variables' => ['cid' => $customerId],
        ])->assertOk();

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!, $pid: ID!) {
                createAdminOrder(
                    customerId: $cid
                    items: [{ productId: $pid, quantity: 2 }]
                    customerNote: "Wave38 admin order"
                ) {
                    id number email status payment_status grand_total
                    items { id sku qty price total }
                }
            }',
            'variables' => ['cid' => $customerId, 'pid' => (string) $product->id],
        ]);
        $create->assertOk();
        $this->assertArrayNotHasKey('errors', $create->json() ?? [], json_encode($create->json()));
        $this->assertNotEmpty($create->json('data.createAdminOrder.number'));
        $create->assertJsonPath('data.createAdminOrder.email', $email);
        $create->assertJsonPath('data.createAdminOrder.status', 'pending');
        $this->assertCount(1, $create->json('data.createAdminOrder.items') ?? []);
        $this->assertEqualsWithDelta(2.0, (float) $create->json('data.createAdminOrder.items.0.qty'), 0.001);

        $orderId = (int) $create->json('data.createAdminOrder.id');
        $this->assertNotNull(Order::query()->find($orderId));
        $this->assertSame((int) $customerId, (int) Customer::query()->find((int) $customerId)?->id);
    }
}
