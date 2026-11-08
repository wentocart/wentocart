<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Promo\Models\DiscountRule;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave49Test extends TestCase
{
    use RefreshDatabase;

    public function test_draft_cart_apply_and_remove_coupon_and_place_with_discount(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $sku = 'W49-'.strtoupper(substr(uniqid(), -6));
        $productRes = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($sku: String!) {
                createSimpleProduct(sku: $sku, name: "Wave49 Coupon", price: 100, stockQty: 10, enabled: true) { id }
            }',
            'variables' => ['sku' => $sku],
        ]);
        $productRes->assertOk();
        $productId = (string) $productRes->json('data.createSimpleProduct.id');
        Product::query()->whereKey((int) $productId)->update(['manage_stock' => true, 'stock_qty' => 10]);

        $code = 'W49SAVE'.strtoupper(substr(uniqid(), -4));
        DiscountRule::query()->create([
            'name' => 'Wave49 Coupon',
            'apply_as' => 'coupon',
            'code' => $code,
            'action' => 'percent',
            'value' => 10,
            'priority' => 10,
            'stop_further' => false,
            'used_count' => 0,
            'is_enabled' => true,
        ]);

        $email = 'w49-'.uniqid().'@example.com';
        $customer = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($e: String!) {
                createCustomer(email: $e, firstName: "Wave", lastName: "FortyNine") { id }
            }',
            'variables' => ['e' => $email],
        ]);
        $customer->assertOk();
        $customerId = (string) $customer->json('data.createCustomer.id');

        $draft = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!) {
                createAdminOrderComposerDraft(customerId: $cid) { cart_id }
            }',
            'variables' => ['cid' => $customerId],
        ]);
        $draft->assertOk();
        $cartId = (string) $draft->json('data.createAdminOrderComposerDraft.cart_id');

        $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!, $pid: ID!) {
                addItemAdminOrderComposerDraft(cartId: $cid, productId: $pid, quantity: 1) { subtotal }
            }',
            'variables' => ['cid' => $cartId, 'pid' => $productId],
        ])->assertOk();

        $apply = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!, $code: String!) {
                applyCouponAdminOrderComposerDraft(cartId: $cid, code: $code) {
                    coupon_code discount_total grand_total subtotal
                }
            }',
            'variables' => ['cid' => $cartId, 'code' => $code],
        ]);
        $apply->assertOk();
        $this->assertArrayNotHasKey('errors', $apply->json() ?? [], json_encode($apply->json()));
        $this->assertSame($code, (string) $apply->json('data.applyCouponAdminOrderComposerDraft.coupon_code'));
        $this->assertEqualsWithDelta(10.0, (float) $apply->json('data.applyCouponAdminOrderComposerDraft.discount_total'), 0.001);
        $this->assertEqualsWithDelta(90.0, (float) $apply->json('data.applyCouponAdminOrderComposerDraft.grand_total'), 0.001);

        $dup = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!, $code: String!) {
                applyCouponAdminOrderComposerDraft(cartId: $cid, code: $code) { coupon_code }
            }',
            'variables' => ['cid' => $cartId, 'code' => $code],
        ]);
        $dup->assertOk();
        $this->assertNotEmpty($dup->json('errors'));

        $remove = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!) {
                removeCouponAdminOrderComposerDraft(cartId: $cid) {
                    coupon_code discount_total grand_total
                }
            }',
            'variables' => ['cid' => $cartId],
        ]);
        $remove->assertOk();
        $this->assertArrayNotHasKey('errors', $remove->json() ?? []);
        $this->assertNull($remove->json('data.removeCouponAdminOrderComposerDraft.coupon_code'));
        $this->assertEqualsWithDelta(0.0, (float) $remove->json('data.removeCouponAdminOrderComposerDraft.discount_total'), 0.001);

        $reapply = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!, $code: String!) {
                applyCouponAdminOrderComposerDraft(cartId: $cid, code: $code) { coupon_code discount_total }
            }',
            'variables' => ['cid' => $cartId, 'code' => $code],
        ]);
        $reapply->assertOk();
        $this->assertArrayNotHasKey('errors', $reapply->json() ?? []);

        $place = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($cid: ID!) {
                placeAdminOrderComposerDraft(cartId: $cid, customerNote: "Wave49 coupon place") {
                    number discount_total grand_total
                }
            }',
            'variables' => ['cid' => $cartId],
        ]);
        $place->assertOk();
        $this->assertArrayNotHasKey('errors', $place->json() ?? [], json_encode($place->json()));
        $orderNumber = (string) $place->json('data.placeAdminOrderComposerDraft.number');
        $order = Order::query()->where('number', $orderNumber)->first();
        $this->assertNotNull($order);
        $this->assertEqualsWithDelta(10.0, (float) $order->discount_total, 0.001);
        $this->assertLessThan((float) $order->subtotal + (float) $order->shipping_total, (float) $order->grand_total + 0.001);
    }

    public function test_customer_compare_list_from_persisted_profile(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $product = Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);

        $email = 'w49-cmp-'.uniqid().'@example.com';
        $customerRes = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($e: String!) {
                createCustomer(email: $e, firstName: "Cmp", lastName: "Panel") { id }
            }',
            'variables' => ['e' => $email],
        ]);
        $customerRes->assertOk();
        $customerId = (int) $customerRes->json('data.createCustomer.id');
        $customer = Customer::query()->findOrFail($customerId);
        $customer->forceFill([
            'profile' => ['compare_ids' => [(int) $product->id]],
        ])->save();

        $query = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($cid: ID!) {
                customerCompareItems(customerId: $cid) {
                    product_id sku source price
                }
            }',
            'variables' => ['cid' => (string) $customerId],
        ]);
        $query->assertOk();
        $this->assertArrayNotHasKey('errors', $query->json() ?? [], json_encode($query->json()));
        $items = $query->json('data.customerCompareItems');
        $this->assertIsArray($items);
        $this->assertNotEmpty($items);
        $this->assertSame((string) $product->id, (string) $items[0]['product_id']);
        $this->assertSame('compare', (string) $items[0]['source']);
    }
}
