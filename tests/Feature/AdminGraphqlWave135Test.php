<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave135Test extends TestCase
{
    use RefreshDatabase;

    public function test_create_admin_transaction_rest_input_and_nested_payload(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $customer = Customer::factory()->create(['is_enabled' => true]);
        $sku = 'W135-'.strtoupper(substr(uniqid(), -6));
        $productId = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($sku: String!) {
                createSimpleProduct(sku: $sku, name: "Wave135 Tx", price: 40, stockQty: 4, enabled: true) { id }
            }',
            'variables' => ['sku' => $sku],
        ])->json('data.createSimpleProduct.id');
        Product::query()->whereKey((int) $productId)->update(['manage_stock' => true, 'stock_qty' => 4]);

        $order = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!, $pid: ID!) {
                createAdminOrder(customerId: $cid, items: [{ productId: $pid, quantity: 1 }]) { number id }
            }',
            'variables' => ['cid' => (string) $customer->id, 'pid' => $productId],
        ]);
        $order->assertOk();
        $this->assertArrayNotHasKey('errors', $order->json() ?? [], json_encode($order->json()));
        $orderNumber = (string) $order->json('data.createAdminOrder.number');
        $orderId = (string) $order->json('data.createAdminOrder.id');
        $this->assertNotSame('', $orderNumber);

        $invoice = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($n: String!) {
                createAdminInvoice(number: $n, withHtml: false, canCreateTransaction: false) {
                    invoice_number order_number
                }
            }',
            'variables' => ['n' => $orderNumber],
        ]);
        $invoice->assertOk();
        $this->assertArrayNotHasKey('errors', $invoice->json() ?? [], json_encode($invoice->json()));
        $invoiceNumber = (string) $invoice->json('data.createAdminInvoice.invoice_number');
        $this->assertNotSame('', $invoiceNumber);

        $tx = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($inv: ID!, $amt: Float!) {
                createAdminTransaction(input: {
                    invoiceId: $inv
                    paymentMethod: "cod"
                    amount: $amt
                }) {
                    amount paymentMethod status order_number invoice_number
                    adminTransaction {
                        transactionId amount formattedAmount paymentMethod status orderId invoiceId
                    }
                }
            }',
            'variables' => ['inv' => $invoiceNumber, 'amt' => 12.5],
        ]);
        $tx->assertOk();
        $this->assertArrayNotHasKey('errors', $tx->json() ?? [], json_encode($tx->json()));
        $this->assertSame($orderNumber, (string) $tx->json('data.createAdminTransaction.order_number'));
        $this->assertSame('cod', (string) $tx->json('data.createAdminTransaction.paymentMethod'));
        $this->assertEqualsWithDelta(12.5, (float) $tx->json('data.createAdminTransaction.adminTransaction.amount'), 0.001);
        $this->assertSame($orderId, (string) $tx->json('data.createAdminTransaction.adminTransaction.orderId'));
        $this->assertNotSame('', (string) $tx->json('data.createAdminTransaction.adminTransaction.transactionId'));
        $this->assertSame('12.50', (string) $tx->json('data.createAdminTransaction.adminTransaction.formattedAmount'));
    }
}
