<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerAddress;
use App\Domain\Order\Models\OrderComment;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave57Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_order_comments_transactions_and_customer_address_rest_aliases(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $email = 'w57-'.uniqid().'@example.com';
        $customerId = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($e: String!) {
                createAdminCustomer(email: $e, firstName: "Wave", lastName: "FiftySeven", phone: "5550177") { id }
            }',
            'variables' => ['e' => $email],
        ])->json('data.createAdminCustomer.id');
        $this->assertNotSame('', $customerId);

        $address = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!) {
                adminAddCustomerAddress(
                    customerId: $cid
                    firstName: "Wave"
                    lastName: "FiftySeven"
                    line1: "57 API Lane"
                    city: "Austin"
                    state: "TX"
                    postalCode: "78701"
                    countryCode: "US"
                    phone: "5550177"
                    defaultBilling: true
                    defaultShipping: true
                ) { id line1 city is_default_billing }
            }',
            'variables' => ['cid' => $customerId],
        ]);
        $address->assertOk();
        $this->assertArrayNotHasKey('errors', $address->json() ?? [], json_encode($address->json()));
        $this->assertSame('57 API Lane', (string) $address->json('data.adminAddCustomerAddress.line1'));
        $this->assertTrue((bool) $address->json('data.adminAddCustomerAddress.is_default_billing'));
        $this->assertNotNull(CustomerAddress::query()->where('customer_id', (int) $customerId)->first());

        $sku = 'W57-'.strtoupper(substr(uniqid(), -6));
        $productId = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($sku: String!) {
                createSimpleProduct(sku: $sku, name: "Wave57 Tx", price: 40, stockQty: 4, enabled: true) { id }
            }',
            'variables' => ['sku' => $sku],
        ])->json('data.createSimpleProduct.id');
        Product::query()->whereKey((int) $productId)->update(['manage_stock' => true, 'stock_qty' => 4]);

        $orderNumber = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!, $pid: ID!) {
                createAdminOrder(customerId: $cid, items: [{ productId: $pid, quantity: 1 }]) { number id }
            }',
            'variables' => ['cid' => $customerId, 'pid' => $productId],
        ])->json('data.createAdminOrder.number');
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
            'query' => 'mutation ($inv: String!) {
                createAdminTransaction(invoiceNumber: $inv, amount: 10, paymentMethod: "cod") {
                    id transaction_id transactionId order_number orderIncrementId invoice_number amount paymentMethod status
                }
            }',
            'variables' => ['inv' => $invoiceNumber],
        ]);
        $tx->assertOk();
        $this->assertArrayNotHasKey('errors', $tx->json() ?? [], json_encode($tx->json()));
        $this->assertSame($orderNumber, (string) $tx->json('data.createAdminTransaction.order_number'));
        $this->assertSame($orderNumber, (string) $tx->json('data.createAdminTransaction.orderIncrementId'));
        $this->assertSame($invoiceNumber, (string) $tx->json('data.createAdminTransaction.invoice_number'));
        $this->assertSame('cod', (string) $tx->json('data.createAdminTransaction.paymentMethod'));
        $txId = (string) $tx->json('data.createAdminTransaction.transaction_id');

        $listTx = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($n: String!, $inv: String!, $tid: String!) {
                byOrder: adminTransactions(orderNumber: $n, limit: 10) { transaction_id order_number amount }
                byInvoice: adminTransactions(invoiceNumber: $inv, limit: 5) { invoice_number }
                adminTransaction(transactionId: $tid, orderNumber: $n) { transactionId amount invoice_number }
            }',
            'variables' => ['n' => $orderNumber, 'inv' => $invoiceNumber, 'tid' => $txId],
        ]);
        $listTx->assertOk();
        $this->assertArrayNotHasKey('errors', $listTx->json() ?? [], json_encode($listTx->json()));
        $this->assertNotEmpty($listTx->json('data.byOrder'));
        $this->assertSame($txId, (string) $listTx->json('data.adminTransaction.transactionId'));
        $this->assertSame($invoiceNumber, (string) $listTx->json('data.adminTransaction.invoice_number'));
        $this->assertSame($invoiceNumber, (string) $listTx->json('data.byInvoice.0.invoice_number'));

        $comment = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($n: String!) {
                createAdminOrderComment(number: $n, body: "Wave57 comment", customerNotified: true) {
                    id order_id body comment customer_notified customerNotified visibility
                }
            }',
            'variables' => ['n' => $orderNumber],
        ]);
        $comment->assertOk();
        $this->assertArrayNotHasKey('errors', $comment->json() ?? [], json_encode($comment->json()));
        $this->assertSame('Wave57 comment', (string) $comment->json('data.createAdminOrderComment.comment'));
        $this->assertTrue((bool) $comment->json('data.createAdminOrderComment.customerNotified'));

        $comments = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($n: String!) {
                adminOrderComments(number: $n, limit: 10) {
                    id order_id comment body customerNotified visibility
                }
            }',
            'variables' => ['n' => $orderNumber],
        ]);
        $comments->assertOk();
        $this->assertArrayNotHasKey('errors', $comments->json() ?? [], json_encode($comments->json()));
        $this->assertNotEmpty($comments->json('data.adminOrderComments'));
        $this->assertSame('Wave57 comment', (string) $comments->json('data.adminOrderComments.0.comment'));
        $this->assertTrue((bool) $comments->json('data.adminOrderComments.0.customerNotified'));
        $this->assertNotNull(OrderComment::query()->where('body', 'Wave57 comment')->first());
        $this->assertNotNull(Customer::query()->find((int) $customerId));
    }
}
