<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Order\Models\Order;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave50Test extends TestCase
{
    use RefreshDatabase;

    public function test_create_admin_reorder_seeds_draft_cart_then_place(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $sku = 'W50-'.strtoupper(substr(uniqid(), -6));
        $productRes = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($sku: String!) {
                createSimpleProduct(sku: $sku, name: "Wave50 Reorder", price: 40, stockQty: 20, enabled: true) { id }
            }',
            'variables' => ['sku' => $sku],
        ]);
        $productRes->assertOk();
        $productId = (string) $productRes->json('data.createSimpleProduct.id');
        Product::query()->whereKey((int) $productId)->update(['manage_stock' => true, 'stock_qty' => 20]);

        $email = 'w50-'.uniqid().'@example.com';
        $customer = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($e: String!) {
                createCustomer(email: $e, firstName: "Wave", lastName: "Fifty") { id }
            }',
            'variables' => ['e' => $email],
        ]);
        $customer->assertOk();
        $customerId = (string) $customer->json('data.createCustomer.id');

        $placed = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!, $pid: ID!) {
                createAdminOrder(
                    customerId: $cid
                    items: [{ productId: $pid, quantity: 2 }]
                    customerNote: "Wave50 source"
                ) { number customer_id subtotal }
            }',
            'variables' => ['cid' => $customerId, 'pid' => $productId],
        ]);
        $placed->assertOk();
        $this->assertArrayNotHasKey('errors', $placed->json() ?? [], json_encode($placed->json()));
        $orderNumber = (string) $placed->json('data.createAdminOrder.number');

        $detail = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($n: String!) {
                adminOrderDetail(number: $n) {
                    number customer_id subtotal billing_address { city } items { sku qty }
                }
            }',
            'variables' => ['n' => $orderNumber],
        ]);
        $detail->assertOk();
        $this->assertArrayNotHasKey('errors', $detail->json() ?? [], json_encode($detail->json()));
        $this->assertSame($orderNumber, (string) $detail->json('data.adminOrderDetail.number'));
        $this->assertSame($customerId, (string) $detail->json('data.adminOrderDetail.customer_id'));
        $this->assertNotEmpty($detail->json('data.adminOrderDetail.items'));

        $reorder = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($n: String!) {
                createAdminReorder(number: $n) {
                    cart_id customer_id item_count subtotal has_addresses
                }
            }',
            'variables' => ['n' => $orderNumber],
        ]);
        $reorder->assertOk();
        $this->assertArrayNotHasKey('errors', $reorder->json() ?? [], json_encode($reorder->json()));
        $cartId = (string) $reorder->json('data.createAdminReorder.cart_id');
        $this->assertNotSame('', $cartId);
        $this->assertSame($customerId, (string) $reorder->json('data.createAdminReorder.customer_id'));
        $this->assertGreaterThanOrEqual(1, (int) $reorder->json('data.createAdminReorder.item_count'));

        $place = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!) {
                placeAdminOrderComposerDraft(cartId: $cid, customerNote: "Wave50 reorder place") {
                    number grand_total
                }
            }',
            'variables' => ['cid' => $cartId],
        ]);
        $place->assertOk();
        $this->assertArrayNotHasKey('errors', $place->json() ?? [], json_encode($place->json()));
        $newNumber = (string) $place->json('data.placeAdminOrderComposerDraft.number');
        $this->assertNotSame($orderNumber, $newNumber);
        $this->assertNotNull(Order::query()->where('number', $newNumber)->first());
    }

    public function test_create_admin_transaction_records_partial_payment(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $sku = 'W50P-'.strtoupper(substr(uniqid(), -6));
        $productRes = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($sku: String!) {
                createSimpleProduct(sku: $sku, name: "Wave50 Pay", price: 50, stockQty: 5, enabled: true) { id }
            }',
            'variables' => ['sku' => $sku],
        ]);
        $productId = (string) $productRes->json('data.createSimpleProduct.id');
        Product::query()->whereKey((int) $productId)->update(['manage_stock' => true, 'stock_qty' => 5]);

        $email = 'w50p-'.uniqid().'@example.com';
        $customerId = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($e: String!) {
                createCustomer(email: $e, firstName: "Pay", lastName: "Fifty") { id }
            }',
            'variables' => ['e' => $email],
        ])->json('data.createCustomer.id');

        $orderNumber = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!, $pid: ID!) {
                createAdminOrder(customerId: $cid, items: [{ productId: $pid, quantity: 1 }]) { number }
            }',
            'variables' => ['cid' => $customerId, 'pid' => $productId],
        ])->json('data.createAdminOrder.number');

        $tx = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($n: String!) {
                createAdminTransaction(number: $n, amount: 20, paymentMethod: "cash") {
                    id transaction_id order_number amount payment_method status
                }
            }',
            'variables' => ['n' => $orderNumber],
        ]);
        $tx->assertOk();
        $this->assertArrayNotHasKey('errors', $tx->json() ?? [], json_encode($tx->json()));
        $this->assertSame($orderNumber, (string) $tx->json('data.createAdminTransaction.order_number'));
        $this->assertEqualsWithDelta(20.0, (float) $tx->json('data.createAdminTransaction.amount'), 0.001);
        $this->assertSame('paid', (string) $tx->json('data.createAdminTransaction.status'));

        $order = Order::query()->where('number', $orderNumber)->first();
        $this->assertNotNull($order);
        $this->assertEqualsWithDelta(20.0, (float) $order->amount_paid, 0.001);
        $this->assertSame('partial', (string) $order->payment_status);
    }
}
