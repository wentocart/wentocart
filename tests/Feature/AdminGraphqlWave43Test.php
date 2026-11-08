<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductQtyPrice;
use App\Domain\Customer\Models\CustomerGroup;
use App\Domain\Marketing\Models\NewsletterSubscriber;
use App\Domain\Order\Models\Order;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave43Test extends TestCase
{
    use RefreshDatabase;

    public function test_qty_price_update_customer_group_crud_newsletter_and_payment_transactions(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $sku = 'W43-'.strtoupper(substr(uniqid(), -6));
        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($sku: String!) {
                createSimpleProduct(sku: $sku, name: "Wave43 Tier", price: 30, stockQty: 12, enabled: true) { id }
            }',
            'variables' => ['sku' => $sku],
        ]);
        $create->assertOk();
        $this->assertArrayNotHasKey('errors', $create->json() ?? []);
        $productId = (string) $create->json('data.createSimpleProduct.id');

        $tier = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($pid: ID!) {
                createProductQtyPrice(productId: $pid, minQty: 3, maxQty: 10, price: 25, position: 0) {
                    id min_qty price
                }
            }',
            'variables' => ['pid' => $productId],
        ]);
        $tier->assertOk();
        $this->assertArrayNotHasKey('errors', $tier->json() ?? []);
        $tierId = (string) $tier->json('data.createProductQtyPrice.id');

        $updateTier = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                updateProductQtyPrice(id: $id, minQty: 4, price: 22.5, position: 2) {
                    id min_qty price position
                }
            }',
            'variables' => ['id' => $tierId],
        ]);
        $updateTier->assertOk();
        $this->assertArrayNotHasKey('errors', $updateTier->json() ?? [], json_encode($updateTier->json()));
        $this->assertEqualsWithDelta(4.0, (float) $updateTier->json('data.updateProductQtyPrice.min_qty'), 0.001);
        $this->assertEqualsWithDelta(22.5, (float) $updateTier->json('data.updateProductQtyPrice.price'), 0.001);
        $this->assertSame(2, (int) $updateTier->json('data.updateProductQtyPrice.position'));
        $this->assertEqualsWithDelta(22.5, (float) ProductQtyPrice::query()->find((int) $tierId)?->price, 0.001);

        $code = 'w43-'.strtolower(substr(uniqid(), -6));
        $groupCreate = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($name: String!, $code: String!) {
                createCustomerGroup(name: $name, code: $code, discountPercent: 7.5, enabled: true) {
                    id code name discount_percent is_enabled is_system customers_count
                }
            }',
            'variables' => ['name' => 'Wave43 Wholesale', 'code' => $code],
        ]);
        $groupCreate->assertOk();
        $this->assertArrayNotHasKey('errors', $groupCreate->json() ?? [], json_encode($groupCreate->json()));
        $groupId = (string) $groupCreate->json('data.createCustomerGroup.id');
        $this->assertSame($code, (string) $groupCreate->json('data.createCustomerGroup.code'));
        $this->assertEqualsWithDelta(7.5, (float) $groupCreate->json('data.createCustomerGroup.discount_percent'), 0.001);
        $this->assertFalse((bool) $groupCreate->json('data.createCustomerGroup.is_system'));

        $groupUpdate = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                updateCustomerGroup(id: $id, name: "Wave43 Wholesale Plus", discountPercent: 9) {
                    id name discount_percent
                }
            }',
            'variables' => ['id' => $groupId],
        ]);
        $groupUpdate->assertOk();
        $this->assertArrayNotHasKey('errors', $groupUpdate->json() ?? []);
        $this->assertSame('Wave43 Wholesale Plus', (string) $groupUpdate->json('data.updateCustomerGroup.name'));
        $freshGroup = CustomerGroup::query()->find((int) $groupId);
        $this->assertNotNull($freshGroup);
        $this->assertEqualsWithDelta(9.0, (float) $freshGroup->discount_percent, 0.001);

        $groupDelete = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) { deleteCustomerGroup(id: $id) { ok id } }',
            'variables' => ['id' => $groupId],
        ]);
        $groupDelete->assertOk();
        $this->assertTrue((bool) $groupDelete->json('data.deleteCustomerGroup.ok'));
        $this->assertNull(CustomerGroup::query()->find((int) $groupId));

        $subscriber = NewsletterSubscriber::query()->create([
            'email' => 'wave43-'.uniqid().'@example.test',
        ]);
        $subs = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($q: String) { newsletterSubscribers(limit: 20, q: $q) { id email } }',
            'variables' => ['q' => $subscriber->email],
        ]);
        $subs->assertOk();
        $this->assertArrayNotHasKey('errors', $subs->json() ?? [], json_encode($subs->json()));
        $this->assertNotEmpty($subs->json('data.newsletterSubscribers'));

        $delSub = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) { deleteNewsletterSubscriber(id: $id) { ok id } }',
            'variables' => ['id' => (string) $subscriber->id],
        ]);
        $delSub->assertOk();
        $this->assertTrue((bool) $delSub->json('data.deleteNewsletterSubscriber.ok'));
        $this->assertNull(NewsletterSubscriber::query()->find($subscriber->id));

        $product = Product::query()->find((int) $productId);
        $this->assertNotNull($product);
        $product->update(['stock_qty' => max(5, (float) $product->stock_qty), 'manage_stock' => true]);

        $email = 'w43-'.uniqid().'@example.com';
        $customerRes = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($e: String!) {
                createCustomer(email: $e, firstName: "Wave", lastName: "FortyThree") { id }
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
                    lastName: "FortyThree"
                    line1: "43 Admin Way"
                    city: "Austin"
                    state: "TX"
                    postalCode: "78701"
                    countryCode: "US"
                    defaultBilling: true
                    defaultShipping: true
                ) { id }
            }',
            'variables' => ['cid' => $customerId],
        ])->assertOk();

        $orderRes = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!, $pid: ID!) {
                createAdminOrder(customerId: $cid, items: [{ productId: $pid, quantity: 1 }]) {
                    number payment_status grand_total
                }
            }',
            'variables' => ['cid' => $customerId, 'pid' => $productId],
        ]);
        $orderRes->assertOk();
        $this->assertArrayNotHasKey('errors', $orderRes->json() ?? [], json_encode($orderRes->json()));
        $orderNumber = (string) $orderRes->json('data.createAdminOrder.number');
        $order = Order::query()->where('number', $orderNumber)->first();
        $this->assertNotNull($order);
        $order->forceFill([
            'payment_status' => 'pending',
            'amount_paid' => 0,
            'amount_due' => $order->grand_total,
            'payment_details' => null,
        ])->save();

        $pay = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($n: String!) {
                recordPayment(number: $n, amount: 1.25, paymentMethod: "bank_transfer") {
                    order_number amount_recorded payment_status
                }
            }',
            'variables' => ['n' => $orderNumber],
        ]);
        $pay->assertOk();
        $this->assertArrayNotHasKey('errors', $pay->json() ?? [], json_encode($pay->json()));
        $this->assertEqualsWithDelta(1.25, (float) $pay->json('data.recordPayment.amount_recorded'), 0.001);

        $txs = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($n: String!) {
                paymentTransactions(limit: 10, orderNumber: $n) {
                    id transaction_id order_number amount payment_method status
                }
            }',
            'variables' => ['n' => $orderNumber],
        ]);
        $txs->assertOk();
        $this->assertArrayNotHasKey('errors', $txs->json() ?? [], json_encode($txs->json()));
        $list = $txs->json('data.paymentTransactions') ?? [];
        $this->assertNotEmpty($list);
        $this->assertEqualsWithDelta(1.25, (float) $list[0]['amount'], 0.001);
        $this->assertSame('bank_transfer', (string) $list[0]['payment_method']);
        $this->assertSame('paid', (string) $list[0]['status']);
    }
}
