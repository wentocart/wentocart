<?php

namespace Tests\Feature;

use App\Domain\Order\Models\Order;
use App\Domain\Search\Models\SearchRedirect;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave45Test extends TestCase
{
    use RefreshDatabase;

    public function test_search_redirects_and_refunds_listing(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $query = 'w45-shoes-'.substr(uniqid(), -5);
        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($q: String!) {
                createSearchRedirect(query: $q, targetPath: "/catalog/shoes", enabled: true) {
                    id query target_path is_enabled
                }
            }',
            'variables' => ['q' => $query],
        ]);
        $create->assertOk();
        $this->assertArrayNotHasKey('errors', $create->json() ?? [], json_encode($create->json()));
        $redirectId = (string) $create->json('data.createSearchRedirect.id');
        $this->assertSame($query, (string) $create->json('data.createSearchRedirect.query'));
        $this->assertSame('/catalog/shoes', (string) $create->json('data.createSearchRedirect.target_path'));
        $this->assertNotNull(SearchRedirect::query()->find((int) $redirectId));
        $this->assertSame('/catalog/shoes', SearchRedirect::targetFor($query));

        $list = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ searchRedirects(limit: 50) { id query target_path } }',
        ]);
        $list->assertOk();
        $this->assertArrayNotHasKey('errors', $list->json() ?? []);
        $queries = collect($list->json('data.searchRedirects') ?? [])->pluck('query')->all();
        $this->assertContains($query, $queries);

        $bad = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation {
                createSearchRedirect(query: "evil", targetPath: "https://evil.example/phish") {
                    id
                }
            }',
        ]);
        $bad->assertOk();
        $this->assertNotEmpty($bad->json('errors'));

        $del = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) { deleteSearchRedirect(id: $id) { ok id } }',
            'variables' => ['id' => $redirectId],
        ]);
        $del->assertOk();
        $this->assertTrue((bool) $del->json('data.deleteSearchRedirect.ok'));
        $this->assertNull(SearchRedirect::query()->find((int) $redirectId));

        $sku = 'W45-'.strtoupper(substr(uniqid(), -6));
        $product = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($sku: String!) {
                createSimpleProduct(sku: $sku, name: "Wave45 Refund", price: 40, stockQty: 5, enabled: true) { id }
            }',
            'variables' => ['sku' => $sku],
        ]);
        $product->assertOk();
        $productId = (string) $product->json('data.createSimpleProduct.id');

        $email = 'w45-'.uniqid().'@example.com';
        $customer = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($e: String!) {
                createCustomer(email: $e, firstName: "Wave", lastName: "FortyFive") { id }
            }',
            'variables' => ['e' => $email],
        ]);
        $customer->assertOk();
        $customerId = (string) $customer->json('data.createCustomer.id');
        $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!) {
                createCustomerAddress(
                    customerId: $cid
                    firstName: "Wave"
                    lastName: "FortyFive"
                    line1: "45 Refund Rd"
                    city: "Denver"
                    state: "CO"
                    postalCode: "80202"
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
                    number grand_total
                }
            }',
            'variables' => ['cid' => $customerId, 'pid' => $productId],
        ]);
        $orderRes->assertOk();
        $this->assertArrayNotHasKey('errors', $orderRes->json() ?? [], json_encode($orderRes->json()));
        $orderNumber = (string) $orderRes->json('data.createAdminOrder.number');
        $order = Order::query()->where('number', $orderNumber)->firstOrFail();
        $order->forceFill([
            'payment_status' => 'paid',
            'amount_paid' => $order->grand_total,
            'amount_due' => 0,
            'refunded_total' => 0,
        ])->save();

        $refundAmount = min(5.0, max(1.0, round(((float) $order->grand_total) / 4, 2)));
        $refund = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($n: String!, $a: Float!) {
                createRefund(number: $n, amount: $a, comment: "Wave45 partial") {
                    number refunded_total payment_status
                }
            }',
            'variables' => ['n' => $orderNumber, 'a' => $refundAmount],
        ]);
        $refund->assertOk();
        $this->assertArrayNotHasKey('errors', $refund->json() ?? [], json_encode($refund->json()));

        $refunds = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ refunds(limit: 20) { order_number refunded_total payment_status } }',
        ]);
        $refunds->assertOk();
        $this->assertArrayNotHasKey('errors', $refunds->json() ?? [], json_encode($refunds->json()));
        $numbers = collect($refunds->json('data.refunds') ?? [])->pluck('order_number')->all();
        $this->assertContains($orderNumber, $numbers);
        $row = collect($refunds->json('data.refunds') ?? [])->firstWhere('order_number', $orderNumber);
        $this->assertNotNull($row);
        $this->assertEqualsWithDelta($refundAmount, (float) $row['refunded_total'], 0.001);
    }
}
