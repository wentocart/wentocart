<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Order\Models\Order;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave51Test extends TestCase
{
    use RefreshDatabase;

    public function test_draft_cart_rejects_booking_service_products(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $sku = 'W51SVC-'.strtoupper(substr(uniqid(), -6));
        $productRes = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($sku: String!) {
                createSimpleProduct(sku: $sku, name: "Wave51 Service", price: 75, stockQty: 0, enabled: true) { id }
            }',
            'variables' => ['sku' => $sku],
        ]);
        $productRes->assertOk();
        $productId = (int) $productRes->json('data.createSimpleProduct.id');
        Product::query()->whereKey($productId)->update([
            'type' => 'service',
            'manage_stock' => false,
        ]);

        $email = 'w51-'.uniqid().'@example.com';
        $customerId = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($e: String!) {
                createCustomer(email: $e, firstName: "Wave", lastName: "FiftyOne") { id }
            }',
            'variables' => ['e' => $email],
        ])->json('data.createCustomer.id');

        $cartId = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!) {
                createAdminOrderComposerDraft(customerId: $cid) { cart_id }
            }',
            'variables' => ['cid' => $customerId],
        ])->json('data.createAdminOrderComposerDraft.cart_id');

        $add = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!, $pid: ID!) {
                addItemAdminOrderComposerDraft(cartId: $cid, productId: $pid, quantity: 1) { item_count }
            }',
            'variables' => ['cid' => $cartId, 'pid' => (string) $productId],
        ]);
        $add->assertOk();
        $this->assertNotEmpty($add->json('errors'));
        $message = (string) ($add->json('errors.0.message') ?? '');
        $this->assertStringContainsString('Booking products cannot be added', $message);
    }

    public function test_create_invoice_can_create_transaction_marks_paid(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $sku = 'W51INV-'.strtoupper(substr(uniqid(), -6));
        $productId = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($sku: String!) {
                createSimpleProduct(sku: $sku, name: "Wave51 Invoice", price: 60, stockQty: 5, enabled: true) { id }
            }',
            'variables' => ['sku' => $sku],
        ])->json('data.createSimpleProduct.id');
        Product::query()->whereKey((int) $productId)->update(['manage_stock' => true, 'stock_qty' => 5]);

        $email = 'w51i-'.uniqid().'@example.com';
        $customerId = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($e: String!) {
                createCustomer(email: $e, firstName: "Inv", lastName: "FiftyOne") { id }
            }',
            'variables' => ['e' => $email],
        ])->json('data.createCustomer.id');

        $orderNumber = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!, $pid: ID!) {
                createAdminOrder(customerId: $cid, items: [{ productId: $pid, quantity: 1 }]) { number }
            }',
            'variables' => ['cid' => $customerId, 'pid' => $productId],
        ])->json('data.createAdminOrder.number');

        $invoice = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($n: String!) {
                createInvoice(number: $n, withHtml: false, canCreateTransaction: true) {
                    order_number invoice_number transaction_recorded
                }
            }',
            'variables' => ['n' => $orderNumber],
        ]);
        $invoice->assertOk();
        $this->assertArrayNotHasKey('errors', $invoice->json() ?? [], json_encode($invoice->json()));
        $this->assertTrue((bool) $invoice->json('data.createInvoice.transaction_recorded'));

        $order = Order::query()->where('number', $orderNumber)->first();
        $this->assertNotNull($order);
        $this->assertSame('paid', (string) $order->payment_status);
        $this->assertEqualsWithDelta((float) $order->grand_total, (float) $order->amount_paid, 0.001);
    }
}
