<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Order\Models\Order;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave47Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_draft_cart_flow_and_customer_interest_panels(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $sku = 'W47-'.strtoupper(substr(uniqid(), -6));
        $productRes = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($sku: String!) {
                createSimpleProduct(sku: $sku, name: "Wave47 Draft", price: 22, stockQty: 15, enabled: true) { id }
            }',
            'variables' => ['sku' => $sku],
        ]);
        $productRes->assertOk();
        $this->assertArrayNotHasKey('errors', $productRes->json() ?? []);
        $productId = (string) $productRes->json('data.createSimpleProduct.id');
        Product::query()->whereKey((int) $productId)->update(['manage_stock' => true, 'stock_qty' => 15]);

        $email = 'w47-'.uniqid().'@example.com';
        $customer = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($e: String!) {
                createCustomer(email: $e, firstName: "Wave", lastName: "FortySeven") { id }
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
                    lastName: "FortySeven"
                    line1: "47 Draft St"
                    city: "Portland"
                    state: "OR"
                    postalCode: "97201"
                    countryCode: "US"
                    defaultBilling: true
                    defaultShipping: true
                ) { id }
            }',
            'variables' => ['cid' => $customerId],
        ])->assertOk();

        $draft = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!) {
                createAdminOrderComposerDraft(customerId: $cid) {
                    cart_id customer_id item_count subtotal
                }
            }',
            'variables' => ['cid' => $customerId],
        ]);
        $draft->assertOk();
        $this->assertArrayNotHasKey('errors', $draft->json() ?? [], json_encode($draft->json()));
        $cartId = (string) $draft->json('data.createAdminOrderComposerDraft.cart_id');
        $this->assertNotSame('', $cartId);
        $this->assertSame(0, (int) $draft->json('data.createAdminOrderComposerDraft.item_count'));

        $add = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!, $pid: ID!) {
                addItemAdminOrderComposerDraft(cartId: $cid, productId: $pid, quantity: 2) {
                    cart_id item_count subtotal
                    items { item_id product_id quantity unit_price total sku }
                }
            }',
            'variables' => ['cid' => $cartId, 'pid' => $productId],
        ]);
        $add->assertOk();
        $this->assertArrayNotHasKey('errors', $add->json() ?? [], json_encode($add->json()));
        $this->assertSame(1, (int) $add->json('data.addItemAdminOrderComposerDraft.item_count'));
        $this->assertEqualsWithDelta(2.0, (float) $add->json('data.addItemAdminOrderComposerDraft.items.0.quantity'), 0.001);
        $itemId = (string) $add->json('data.addItemAdminOrderComposerDraft.items.0.item_id');

        $update = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!, $iid: ID!) {
                updateItemAdminOrderComposerDraft(cartId: $cid, itemId: $iid, quantity: 3) {
                    items { quantity total }
                    subtotal
                }
            }',
            'variables' => ['cid' => $cartId, 'iid' => $itemId],
        ]);
        $update->assertOk();
        $this->assertArrayNotHasKey('errors', $update->json() ?? []);
        $this->assertEqualsWithDelta(3.0, (float) $update->json('data.updateItemAdminOrderComposerDraft.items.0.quantity'), 0.001);

        $get = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($cid: ID!) {
                adminOrderComposerDraft(cartId: $cid) { cart_id item_count subtotal }
            }',
            'variables' => ['cid' => $cartId],
        ]);
        $get->assertOk();
        $this->assertSame(1, (int) $get->json('data.adminOrderComposerDraft.item_count'));

        $place = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!) {
                placeAdminOrderComposerDraft(cartId: $cid, customerNote: "Wave47 draft place") {
                    number email status items { qty sku }
                }
            }',
            'variables' => ['cid' => $cartId],
        ]);
        $place->assertOk();
        $this->assertArrayNotHasKey('errors', $place->json() ?? [], json_encode($place->json()));
        $orderNumber = (string) $place->json('data.placeAdminOrderComposerDraft.number');
        $this->assertNotSame('', $orderNumber);
        $this->assertSame($email, (string) $place->json('data.placeAdminOrderComposerDraft.email'));
        $this->assertEqualsWithDelta(3.0, (float) $place->json('data.placeAdminOrderComposerDraft.items.0.qty'), 0.001);
        $this->assertNotNull(Order::query()->where('number', $orderNumber)->first());

        $gone = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($cid: ID!) { adminOrderComposerDraft(cartId: $cid) { cart_id } }',
            'variables' => ['cid' => $cartId],
        ]);
        $gone->assertOk();
        $this->assertNull($gone->json('data.adminOrderComposerDraft'));

        $customerModel = \App\Domain\Customer\Models\Customer::query()->find((int) $customerId);
        $this->assertNotNull($customerModel);
        $customerModel->wishlist()->syncWithoutDetaching([(int) $productId]);

        $wishlist = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($cid: ID!) {
                customerWishlistItems(customerId: $cid) { product_id sku source }
            }',
            'variables' => ['cid' => $customerId],
        ]);
        $wishlist->assertOk();
        $this->assertArrayNotHasKey('errors', $wishlist->json() ?? []);
        $this->assertNotEmpty($wishlist->json('data.customerWishlistItems'));
        $this->assertSame('wishlist', (string) $wishlist->json('data.customerWishlistItems.0.source'));

        $recent = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($cid: ID!) {
                customerRecentOrderItems(customerId: $cid, limit: 5) { product_id source }
            }',
            'variables' => ['cid' => $customerId],
        ]);
        $recent->assertOk();
        $this->assertArrayNotHasKey('errors', $recent->json() ?? []);
        $this->assertNotEmpty($recent->json('data.customerRecentOrderItems'));
        $this->assertSame('recent_order', (string) $recent->json('data.customerRecentOrderItems.0.source'));
    }
}
