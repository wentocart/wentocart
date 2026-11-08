<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductComponent;
use App\Domain\Order\Models\OrderComment;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminGraphqlWave52Test extends TestCase
{
    use RefreshDatabase;

    public function test_create_admin_order_comment_customer_notified(): void
    {
        $this->seed();
        Mail::fake();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $sku = 'W52C-'.strtoupper(substr(uniqid(), -6));
        $productId = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($sku: String!) {
                createSimpleProduct(sku: $sku, name: "Wave52 Comment", price: 22, stockQty: 5, enabled: true) { id }
            }',
            'variables' => ['sku' => $sku],
        ])->json('data.createSimpleProduct.id');
        Product::query()->whereKey((int) $productId)->update(['manage_stock' => true, 'stock_qty' => 5]);

        $email = 'w52c-'.uniqid().'@example.com';
        $customerId = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($e: String!) {
                createCustomer(email: $e, firstName: "Wave", lastName: "FiftyTwo") { id }
            }',
            'variables' => ['e' => $email],
        ])->json('data.createCustomer.id');

        $orderNumber = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!, $pid: ID!) {
                createAdminOrder(customerId: $cid, items: [{ productId: $pid, quantity: 1 }]) { number }
            }',
            'variables' => ['cid' => $customerId, 'pid' => $productId],
        ])->json('data.createAdminOrder.number');

        $comment = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($n: String!) {
                createAdminOrderComment(
                    number: $n
                    body: "Wave52 customer note"
                    customerNotified: true
                ) {
                    id body visibility customer_notified
                }
            }',
            'variables' => ['n' => $orderNumber],
        ]);
        $comment->assertOk();
        $this->assertArrayNotHasKey('errors', $comment->json() ?? [], json_encode($comment->json()));
        $this->assertSame('customer', (string) $comment->json('data.createAdminOrderComment.visibility'));
        $this->assertTrue((bool) $comment->json('data.createAdminOrderComment.customer_notified'));
        $this->assertNotNull(OrderComment::query()->where('body', 'Wave52 customer note')->first());
        Mail::assertOutgoingCount(2); // order lifecycle mail + comment notification
    }

    public function test_draft_cart_adds_group_via_grouped_quantities(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $childA = Product::query()->create([
            'type' => 'simple',
            'sku' => 'W52-A-'.strtoupper(substr(uniqid(), -5)),
            'price' => 10,
            'is_enabled' => true,
            'manage_stock' => false,
            'stock_qty' => 0,
        ]);
        $childB = Product::query()->create([
            'type' => 'simple',
            'sku' => 'W52-B-'.strtoupper(substr(uniqid(), -5)),
            'price' => 15,
            'is_enabled' => true,
            'manage_stock' => false,
            'stock_qty' => 0,
        ]);
        $group = Product::query()->create([
            'type' => 'group',
            'sku' => 'W52-G-'.strtoupper(substr(uniqid(), -5)),
            'price' => 0,
            'is_enabled' => true,
            'manage_stock' => false,
            'selection_mode' => 'all',
        ]);
        ProductComponent::query()->create([
            'parent_product_id' => $group->id,
            'child_product_id' => $childA->id,
            'quantity' => 1,
            'is_required' => true,
            'qty_editable' => false,
            'position' => 1,
        ]);
        ProductComponent::query()->create([
            'parent_product_id' => $group->id,
            'child_product_id' => $childB->id,
            'quantity' => 2,
            'is_required' => true,
            'qty_editable' => false,
            'position' => 2,
        ]);

        $email = 'w52g-'.uniqid().'@example.com';
        $customerId = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($e: String!) {
                createCustomer(email: $e, firstName: "Group", lastName: "FiftyTwo") { id }
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
            'query' => 'mutation ($cid: ID!, $gid: ID!, $a: ID!, $b: ID!) {
                addItemAdminOrderComposerDraft(
                    cartId: $cid
                    productId: $gid
                    quantity: 1
                    groupedQuantities: [
                        { productId: $a, quantity: 1 }
                        { productId: $b, quantity: 2 }
                    ]
                ) { item_count subtotal }
            }',
            'variables' => [
                'cid' => $cartId,
                'gid' => (string) $group->id,
                'a' => (string) $childA->id,
                'b' => (string) $childB->id,
            ],
        ]);
        $add->assertOk();
        $this->assertArrayNotHasKey('errors', $add->json() ?? [], json_encode($add->json()));
        $this->assertGreaterThanOrEqual(2, (int) $add->json('data.addItemAdminOrderComposerDraft.item_count'));
        $this->assertEqualsWithDelta(40.0, (float) $add->json('data.addItemAdminOrderComposerDraft.subtotal'), 0.001);
    }
}
