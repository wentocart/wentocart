<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductComponent;
use App\Domain\Catalog\Models\Warehouse;
use App\Domain\Shipping\Models\Shipment;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave53Test extends TestCase
{
    use RefreshDatabase;

    public function test_create_admin_shipment_and_admin_shipments_list(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $sku = 'W53S-'.strtoupper(substr(uniqid(), -6));
        $productId = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($sku: String!) {
                createSimpleProduct(sku: $sku, name: "Wave53 Ship", price: 33, stockQty: 5, enabled: true) { id }
            }',
            'variables' => ['sku' => $sku],
        ])->json('data.createSimpleProduct.id');
        Product::query()->whereKey((int) $productId)->update(['manage_stock' => true, 'stock_qty' => 5]);

        $email = 'w53s-'.uniqid().'@example.com';
        $customerId = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($e: String!) {
                createCustomer(email: $e, firstName: "Ship", lastName: "FiftyThree") { id }
            }',
            'variables' => ['e' => $email],
        ])->json('data.createCustomer.id');

        $orderNumber = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!, $pid: ID!) {
                createAdminOrder(customerId: $cid, items: [{ productId: $pid, quantity: 1 }]) { number }
            }',
            'variables' => ['cid' => $customerId, 'pid' => $productId],
        ])->json('data.createAdminOrder.number');

        $warehouse = Warehouse::query()->where('is_enabled', true)->orderBy('id')->first();
        $this->assertNotNull($warehouse);

        $ship = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($n: String!, $wid: ID!) {
                createAdminShipment(
                    number: $n
                    carrier: "ups"
                    trackingNumber: "W53TRACK"
                    warehouseId: $wid
                ) { id order_id status carrier tracking_number }
            }',
            'variables' => ['n' => $orderNumber, 'wid' => (string) $warehouse->id],
        ]);
        $ship->assertOk();
        $this->assertArrayNotHasKey('errors', $ship->json() ?? [], json_encode($ship->json()));
        $shipId = (string) $ship->json('data.createAdminShipment.id');
        $this->assertSame('ups', (string) $ship->json('data.createAdminShipment.carrier'));
        $this->assertSame('W53TRACK', (string) $ship->json('data.createAdminShipment.tracking_number'));
        $this->assertNotNull(Shipment::query()->find((int) $shipId));

        $list = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($n: String!, $sid: ID!) {
                adminShipments(orderNumber: $n) { id tracking_number }
                adminShipment(id: $sid) { id carrier }
            }',
            'variables' => ['n' => $orderNumber, 'sid' => $shipId],
        ]);
        $list->assertOk();
        $this->assertArrayNotHasKey('errors', $list->json() ?? [], json_encode($list->json()));
        $this->assertNotEmpty($list->json('data.adminShipments'));
        $this->assertSame('ups', (string) $list->json('data.adminShipment.carrier'));
    }

    public function test_draft_cart_adds_bundle_via_bundle_options(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $childA = Product::query()->create([
            'type' => 'simple',
            'sku' => 'W53-BA-'.strtoupper(substr(uniqid(), -5)),
            'price' => 8,
            'is_enabled' => true,
            'available_for_order' => true,
            'manage_stock' => false,
        ]);
        $childB = Product::query()->create([
            'type' => 'simple',
            'sku' => 'W53-BB-'.strtoupper(substr(uniqid(), -5)),
            'price' => 12,
            'is_enabled' => true,
            'available_for_order' => true,
            'manage_stock' => false,
        ]);
        $bundle = Product::query()->create([
            'type' => 'bundle',
            'sku' => 'W53-BN-'.strtoupper(substr(uniqid(), -5)),
            'price' => 18,
            'is_enabled' => true,
            'available_for_order' => true,
            'bundle_type' => 'fixed',
            'pricing_mode' => 'fixed',
            'manage_stock' => false,
        ]);
        ProductComponent::query()->create([
            'parent_product_id' => $bundle->id,
            'child_product_id' => $childA->id,
            'quantity' => 1,
            'is_required' => true,
            'default_selected' => true,
            'position' => 1,
        ]);
        ProductComponent::query()->create([
            'parent_product_id' => $bundle->id,
            'child_product_id' => $childB->id,
            'quantity' => 1,
            'is_required' => true,
            'default_selected' => true,
            'position' => 2,
        ]);

        $email = 'w53b-'.uniqid().'@example.com';
        $customerId = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($e: String!) {
                createCustomer(email: $e, firstName: "Bundle", lastName: "FiftyThree") { id }
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
            'query' => 'mutation ($cid: ID!, $bid: ID!, $a: ID!, $b: ID!) {
                addItemAdminOrderComposerDraft(
                    cartId: $cid
                    productId: $bid
                    quantity: 1
                    bundleOptions: [
                        { productId: $a, quantity: 1 }
                        { productId: $b, quantity: 1 }
                    ]
                ) { item_count subtotal }
            }',
            'variables' => [
                'cid' => $cartId,
                'bid' => (string) $bundle->id,
                'a' => (string) $childA->id,
                'b' => (string) $childB->id,
            ],
        ]);
        $add->assertOk();
        $this->assertArrayNotHasKey('errors', $add->json() ?? [], json_encode($add->json()));
        $this->assertGreaterThanOrEqual(2, (int) $add->json('data.addItemAdminOrderComposerDraft.item_count'));
    }
}
