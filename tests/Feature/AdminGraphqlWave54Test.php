<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Order\Models\Order;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave54Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_named_admin_order_aliases_and_invoice_guards(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $sku = 'W54-'.strtoupper(substr(uniqid(), -6));
        $productId = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($sku: String!) {
                createSimpleProduct(sku: $sku, name: "Wave54 Alias", price: 44, stockQty: 5, enabled: true) { id }
            }',
            'variables' => ['sku' => $sku],
        ])->json('data.createSimpleProduct.id');
        Product::query()->whereKey((int) $productId)->update(['manage_stock' => true, 'stock_qty' => 5]);

        $email = 'w54-'.uniqid().'@example.com';
        $customerId = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($e: String!) {
                createCustomer(email: $e, firstName: "Wave", lastName: "FiftyFour") { id }
            }',
            'variables' => ['e' => $email],
        ])->json('data.createCustomer.id');

        $orderNumber = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!, $pid: ID!) {
                createAdminOrder(customerId: $cid, items: [{ productId: $pid, quantity: 1 }]) { number }
            }',
            'variables' => ['cid' => $customerId, 'pid' => $productId],
        ])->json('data.createAdminOrder.number');

        $list = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ adminOrders(limit: 5) { number status } }',
        ]);
        $list->assertOk();
        $this->assertArrayNotHasKey('errors', $list->json() ?? []);
        $this->assertNotEmpty($list->json('data.adminOrders'));

        $invoice = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($n: String!) {
                createAdminInvoice(number: $n, withHtml: false, canCreateTransaction: true) {
                    order_number invoice_number transaction_recorded
                }
            }',
            'variables' => ['n' => $orderNumber],
        ]);
        $invoice->assertOk();
        $this->assertArrayNotHasKey('errors', $invoice->json() ?? [], json_encode($invoice->json()));
        $this->assertTrue((bool) $invoice->json('data.createAdminInvoice.transaction_recorded'));

        $preview = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($n: String!) {
                previewAdminRefund(number: $n) { order_number refundable_total can_refund }
            }',
            'variables' => ['n' => $orderNumber],
        ]);
        $preview->assertOk();
        $this->assertArrayNotHasKey('errors', $preview->json() ?? []);
        $this->assertTrue((bool) $preview->json('data.previewAdminRefund.can_refund'));

        $refund = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($n: String!) {
                createAdminRefund(number: $n, amount: 10, comment: "Wave54 partial") {
                    number refunded_total payment_status
                }
            }',
            'variables' => ['n' => $orderNumber],
        ]);
        $refund->assertOk();
        $this->assertArrayNotHasKey('errors', $refund->json() ?? [], json_encode($refund->json()));
        $this->assertGreaterThan(0, (float) $refund->json('data.createAdminRefund.refunded_total'));

        $cancelSku = 'W54C-'.strtoupper(substr(uniqid(), -6));
        $cancelPid = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($sku: String!) {
                createSimpleProduct(sku: $sku, name: "Wave54 Cancel", price: 15, stockQty: 3, enabled: true) { id }
            }',
            'variables' => ['sku' => $cancelSku],
        ])->json('data.createSimpleProduct.id');
        Product::query()->whereKey((int) $cancelPid)->update(['manage_stock' => true, 'stock_qty' => 3]);
        $cancelNumber = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!, $pid: ID!) {
                createAdminOrder(customerId: $cid, items: [{ productId: $pid, quantity: 1 }]) { number }
            }',
            'variables' => ['cid' => $customerId, 'pid' => $cancelPid],
        ])->json('data.createAdminOrder.number');

        $cancel = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($n: String!) {
                createAdminCancelOrder(number: $n, comment: "Wave54 cancel") { number status }
            }',
            'variables' => ['n' => $cancelNumber],
        ]);
        $cancel->assertOk();
        $this->assertArrayNotHasKey('errors', $cancel->json() ?? [], json_encode($cancel->json()));
        $this->assertSame('cancelled', (string) $cancel->json('data.createAdminCancelOrder.status'));
        $this->assertSame('cancelled', (string) Order::query()->where('number', $cancelNumber)->value('status'));
    }
}
