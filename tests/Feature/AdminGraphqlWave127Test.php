<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave127Test extends TestCase
{
    use RefreshDatabase;

    public function test_shop_rest_doc_aliases_wishlist_reviews_order_cart(): void
    {
        $this->seed();

        $customer = Customer::factory()->create();
        $product = Product::query()->where('is_enabled', true)->where('type', 'simple')->first()
            ?? Product::query()->where('is_enabled', true)->firstOrFail();

        $reviews = $this->postJson('/api/v1/graphql', [
            'query' => 'query ($id: ID!) {
                reviews(productId: $id, first: 5) { totalCount edges { node { id } } }
            }',
            'variables' => ['id' => (string) $product->id],
        ]);
        $reviews->assertOk();
        $this->assertArrayNotHasKey('errors', $reviews->json() ?? [], json_encode($reviews->json()));

        $addWl = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'mutation ($id: ID!) {
                addToWishlist(input: { productId: $id }) {
                    status message
                    wishlist { id items { totalCount edges { node { id product { id } } } } }
                }
            }',
            'variables' => ['id' => (string) $product->id],
        ]);
        $addWl->assertOk();
        $this->assertArrayNotHasKey('errors', $addWl->json() ?? [], json_encode($addWl->json()));
        $this->assertTrue((bool) $addWl->json('data.addToWishlist.status'));
        $this->assertGreaterThanOrEqual(1, (int) $addWl->json('data.addToWishlist.wishlist.items.totalCount'));
        $itemId = (string) $addWl->json('data.addToWishlist.wishlist.items.edges.0.node.id');

        $rm = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'mutation ($id: ID!) {
                removeFromWishlist(input: { wishlistItemId: $id }) { status message }
            }',
            'variables' => ['id' => $itemId],
        ]);
        $rm->assertOk();
        $this->assertArrayNotHasKey('errors', $rm->json() ?? [], json_encode($rm->json()));
        $this->assertTrue((bool) $rm->json('data.removeFromWishlist.status'));

        $add = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation ($id: ID!) {
                addItemToCart(productId: $id, quantity: 2) { itemsCount itemsList { id quantity } }
            }',
            'variables' => ['id' => (string) $product->id],
        ]);
        $add->assertOk();
        $this->assertArrayNotHasKey('errors', $add->json() ?? [], json_encode($add->json()));
        $cartItemId = (string) $add->json('data.addItemToCart.itemsList.0.id');
        $this->assertNotEmpty($cartItemId);

        $upd = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation ($id: ID!) {
                updateCartItem(input: { cartItemId: $id, quantity: 3 }) {
                    itemsList { id quantity }
                }
            }',
            'variables' => ['id' => $cartItemId],
        ]);
        $upd->assertOk();
        $this->assertArrayNotHasKey('errors', $upd->json() ?? [], json_encode($upd->json()));
        $this->assertEqualsWithDelta(3.0, (float) $upd->json('data.updateCartItem.itemsList.0.quantity'), 0.001);

        $sub = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation { subscribe(email: "wave127@example.com") { ok message } }',
        ]);
        $sub->assertOk();
        $this->assertArrayNotHasKey('errors', $sub->json() ?? [], json_encode($sub->json()));
        $this->assertTrue((bool) $sub->json('data.subscribe.ok'));

        $order = new Order;
        $order->forceFill([
            'customer_id' => $customer->id,
            'number' => 'W127-'.uniqid(),
            'email' => $customer->email,
            'status' => 'processing',
            'currency_code' => 'USD',
            'subtotal' => 10,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'grand_total' => 10,
            'payment_status' => 'paid',
            'billing_address' => [
                'first_name' => 'Wave',
                'last_name' => '127',
                'line1' => '1 Alias Rd',
                'city' => 'Leeds',
                'country_code' => 'GB',
                'postal_code' => 'LS1',
            ],
            'shipping_address' => [
                'first_name' => 'Wave',
                'last_name' => '127',
                'line1' => '1 Alias Rd',
                'city' => 'Leeds',
                'country_code' => 'GB',
                'postal_code' => 'LS1',
            ],
        ]);
        $order->save();

        $ord = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'query ($id: ID!) { order(id: $id) { id number status grandTotal } }',
            'variables' => ['id' => (string) $order->id],
        ]);
        $ord->assertOk();
        $this->assertArrayNotHasKey('errors', $ord->json() ?? [], json_encode($ord->json()));
        $this->assertSame((string) $order->id, (string) $ord->json('data.order.id'));
    }

    public function test_admin_rest_create_product_and_short_queries(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $sku = 'W127-'.uniqid();

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($sku: String!) {
                createProduct(input: {
                    sku: $sku
                    name: "Wave 127 Product"
                    price: 19.5
                    stockQty: 4
                    enabled: true
                }) {
                    product { id sku name price }
                }
            }',
            'variables' => ['sku' => $sku],
        ]);
        $create->assertOk();
        $this->assertArrayNotHasKey('errors', $create->json() ?? [], json_encode($create->json()));
        $productId = (string) $create->json('data.createProduct.product.id');
        $this->assertNotEmpty($productId);
        $this->assertSame($sku, (string) $create->json('data.createProduct.product.sku'));

        $lookup = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($id: ID!) {
                adminProduct(id: $id) { id sku name }
                categories(limit: 3) { id name }
            }',
            'variables' => ['id' => $productId],
        ]);
        $lookup->assertOk();
        $this->assertArrayNotHasKey('errors', $lookup->json() ?? [], json_encode($lookup->json()));
        $this->assertSame($sku, (string) $lookup->json('data.adminProduct.sku'));
        $this->assertIsArray($lookup->json('data.categories'));
    }
}
