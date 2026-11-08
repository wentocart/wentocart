<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\ShopReturn;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AdminGraphqlWave73Test extends TestCase
{
    use RefreshDatabase;

    public function test_view_stats_and_admin_returns_rest_aliases(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $viewSales = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query {
                viewStatsAdminReportingSales(type: "sales_total", start: "2026-10-01", end: "2026-10-14") {
                    entity type statistics
                }
            }',
        ]);
        $viewSales->assertOk();
        $this->assertArrayNotHasKey('errors', $viewSales->json() ?? [], json_encode($viewSales->json()));
        $salesTable = json_decode((string) $viewSales->json('data.viewStatsAdminReportingSales.statistics'), true);
        $this->assertIsArray($salesTable);
        $this->assertArrayHasKey('columns', $salesTable);
        $this->assertArrayHasKey('records', $salesTable);

        $viewCustomers = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query {
                viewStatsAdminReportingCustomers(type: "customers-with-most-sales", start: "2026-10-01", end: "2026-10-14") {
                    entity statistics
                }
            }',
        ]);
        $viewCustomers->assertOk();
        $this->assertArrayNotHasKey('errors', $viewCustomers->json() ?? [], json_encode($viewCustomers->json()));

        $viewProducts = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query {
                viewStatsAdminReportingProducts(type: "top_products-by-revenue", start: "2026-10-01", end: "2026-10-14") {
                    entity statistics
                }
            }',
        ]);
        $viewProducts->assertOk();
        $this->assertArrayNotHasKey('errors', $viewProducts->json() ?? [], json_encode($viewProducts->json()));

        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $payment = PaymentMethod::query()->firstOrFail();
        $shipping = ShippingMethod::query()->where('is_enabled', true)->firstOrFail();
        $customer = Customer::factory()->create();

        Auth::guard('admin')->logout();
        $this->actingAs($customer, 'customer')->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $placed = $this->actingAs($customer, 'customer')->postJson('/api/v1/checkout', [
            'email' => $customer->email,
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Wave', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Wave', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ])->assertCreated();
        $orderId = (string) ($placed->json('order.id') ?? $placed->json('data.order.id') ?? '');
        $this->assertNotSame('', $orderId, 'checkout body: '.json_encode($placed->json()));

        Auth::guard('customer')->logout();
        $this->postJson('/api/v1/auth/logout');

        $returnable = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($oid: ID!) {
                adminReturnableItems(orderId: $oid) { id orderItemId sku qty rmaQty }
                adminReturnReasons { id name title }
            }',
            'variables' => ['oid' => $orderId],
        ]);
        $returnable->assertOk();
        $this->assertArrayNotHasKey('errors', $returnable->json() ?? [], json_encode($returnable->json()));
        $items = $returnable->json('data.adminReturnableItems') ?? [];
        $this->assertNotEmpty($items);
        $orderItemId = (string) ($items[0]['orderItemId'] ?? $items[0]['id']);

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($oid: ID!, $items: String!) {
                createAdminReturn(orderId: $oid, itemsJson: $items, information: "Wave73 damaged") {
                    id status statusTitle orderId canReopen availableStatuses item
                }
            }',
            'variables' => [
                'oid' => $orderId,
                'items' => json_encode([['order_item_id' => (int) $orderItemId, 'qty' => 1]]),
            ],
        ]);
        $create->assertOk();
        $this->assertArrayNotHasKey('errors', $create->json() ?? [], json_encode($create->json()));
        $returnId = (string) $create->json('data.createAdminReturn.id');
        $this->assertNotSame('', $returnId);
        $this->assertNotEmpty($create->json('data.createAdminReturn.availableStatuses'));

        $list = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query {
                adminReturns(order_id: '.(int) $orderId.', limit: 10) { id statusTitle number }
                adminReturn(id: "'.$returnId.'") { id status statusTitle availableStatuses canReopen }
            }',
        ]);
        $list->assertOk();
        $this->assertArrayNotHasKey('errors', $list->json() ?? [], json_encode($list->json()));
        $this->assertNotEmpty($list->json('data.adminReturns'));

        $available = json_decode((string) $list->json('data.adminReturn.availableStatuses'), true) ?: [];
        $this->assertNotEmpty($available);
        $approveStatusId = (string) ($available[0]['id'] ?? '');
        $this->assertNotSame('', $approveStatusId);

        $update = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!, $sid: String!) {
                updateStatusAdminReturn(id: $id, statusId: $sid, note: "Wave73 approved") {
                    id status statusTitle
                }
            }',
            'variables' => ['id' => $returnId, 'sid' => $approveStatusId],
        ]);
        $update->assertOk();
        $this->assertArrayNotHasKey('errors', $update->json() ?? [], json_encode($update->json()));
        $this->assertSame('approved', (string) $update->json('data.updateStatusAdminReturn.status'));

        $msg = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($rid: ID!) {
                createAdminReturnMessage(returnId: $rid, body: "Wave73 packing tip") { id body isAdmin }
            }',
            'variables' => ['rid' => $returnId],
        ]);
        $msg->assertOk();
        $this->assertArrayNotHasKey('errors', $msg->json() ?? [], json_encode($msg->json()));
        $this->assertSame('Wave73 packing tip', (string) $msg->json('data.createAdminReturnMessage.body'));

        $messages = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($rid: ID!) { adminReturnMessages(returnId: $rid) { id body } }',
            'variables' => ['rid' => $returnId],
        ]);
        $messages->assertOk();
        $this->assertNotEmpty($messages->json('data.adminReturnMessages'));

        $row = ShopReturn::query()->find((int) $returnId);
        $this->assertNotNull($row);
        $row->update(['status' => ShopReturn::STATUS_REJECTED]);

        $reopen = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) { reopenAdminReturn(id: $id) { id status canReopen } }',
            'variables' => ['id' => $returnId],
        ]);
        $reopen->assertOk();
        $this->assertArrayNotHasKey('errors', $reopen->json() ?? [], json_encode($reopen->json()));
        $this->assertSame('requested', (string) $reopen->json('data.reopenAdminReturn.status'));
    }
}
