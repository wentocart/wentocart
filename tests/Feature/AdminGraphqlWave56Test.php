<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave56Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_customers_and_refunds_rest_aliases(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $created = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation {
                createAdminCustomer(
                    email: "w56-'.uniqid().'@example.com"
                    firstName: "Wave"
                    lastName: "FiftySix"
                    phone: "5550166"
                ) { id email name }
            }',
        ]);
        $created->assertOk();
        $this->assertArrayNotHasKey('errors', $created->json() ?? [], json_encode($created->json()));
        $customerId = (string) $created->json('data.createAdminCustomer.id');

        $list = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($q: String!) {
                adminCustomers(limit: 10, q: $q) { id email }
                adminCustomer(id: "'.$customerId.'") { id name phone }
            }',
            'variables' => ['q' => 'FiftySix'],
        ]);
        $list->assertOk();
        $this->assertArrayNotHasKey('errors', $list->json() ?? [], json_encode($list->json()));
        $this->assertNotEmpty($list->json('data.adminCustomers'));
        $this->assertSame($customerId, (string) $list->json('data.adminCustomer.id'));

        $updated = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                updateAdminCustomer(id: $id, phone: "5550199") { id phone }
            }',
            'variables' => ['id' => $customerId],
        ]);
        $updated->assertOk();
        $this->assertArrayNotHasKey('errors', $updated->json() ?? []);
        $this->assertSame('5550199', (string) $updated->json('data.updateAdminCustomer.phone'));

        $sku = 'W56-'.strtoupper(substr(uniqid(), -6));
        $productId = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($sku: String!) {
                createSimpleProduct(sku: $sku, name: "Wave56 Refund", price: 50, stockQty: 3, enabled: true) { id }
            }',
            'variables' => ['sku' => $sku],
        ])->json('data.createSimpleProduct.id');
        Product::query()->whereKey((int) $productId)->update(['manage_stock' => true, 'stock_qty' => 3]);

        $orderNumber = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!, $pid: ID!) {
                createAdminOrder(customerId: $cid, items: [{ productId: $pid, quantity: 1 }]) { number }
            }',
            'variables' => ['cid' => $customerId, 'pid' => $productId],
        ])->json('data.createAdminOrder.number');

        $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($n: String!) {
                createAdminInvoice(number: $n, withHtml: false, canCreateTransaction: true) { transaction_recorded }
            }',
            'variables' => ['n' => $orderNumber],
        ])->assertOk();

        $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($n: String!) {
                createAdminRefund(number: $n, amount: 15) { number refunded_total }
            }',
            'variables' => ['n' => $orderNumber],
        ])->assertOk();

        $refunds = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($n: String!) {
                adminRefunds(orderNumber: $n) { order_number refunded_total }
                adminRefund(orderNumber: $n) { order_number refunded_total }
            }',
            'variables' => ['n' => $orderNumber],
        ]);
        $refunds->assertOk();
        $this->assertArrayNotHasKey('errors', $refunds->json() ?? [], json_encode($refunds->json()));
        $this->assertNotEmpty($refunds->json('data.adminRefunds'));
        $this->assertSame($orderNumber, (string) $refunds->json('data.adminRefund.order_number'));

        $this->assertNotNull(Customer::query()->find((int) $customerId));
    }
}
