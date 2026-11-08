<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerOrder;
use App\Domain\Marketplace\Models\SellerOrderItem;
use App\Domain\Marketplace\Models\SellerProduct;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Models\Admin;

/**
 * Phase 06 — multi-seller checkout: one parent Order + SellerOrder slices.
 */
class MarketplaceCheckoutTest extends MarketplaceTestCase
{

    /** @return array<string, mixed> */
    protected function applyPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Ada',
            'last_name' => 'Seller',
            'shop_name' => 'Ada Market',
            'shop_slug' => 'ada-market',
            'profile_name' => 'Ada Shop',
            'email' => 'ada.seller@example.com',
            'phone' => '+1 555 0100',
            'contact_name' => 'Ada Seller',
            'contact_phone' => '+1 555 0100',
            'address_line1' => '12 Market Street',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Handmade goods',
        ], $overrides);
    }

    protected function registerCustomer(string $email = 'buyer@example.com'): Customer
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Pat Buyer',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertOk();

        return Customer::query()->where('email', $email)->firstOrFail();
    }

    /** @return array{0: Seller, 1: Customer, 2: Product} */
    protected function sellerWithApprovedProduct(
        string $email,
        string $shopSlug,
        string $productName,
        float $price = 15,
        int $stock = 10,
    ): array {
        $this->postJson('/api/v1/auth/logout');
        $owner = $this->registerCustomer($email);
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload([
            'email' => str_replace('@', '.shop@', $email),
            'shop_name' => $shopSlug.' Shop',
            'shop_slug' => $shopSlug,
        ]))->assertCreated();
        $seller = Seller::query()->where('customer_id', $owner->id)->firstOrFail();
        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/multivendor/seller-requests/'.$seller->id.'/review', ['action' => 'approve'])
            ->assertRedirect();

        \Illuminate\Support\Facades\Auth::guard('admin')->logout();
        $this->actingAs($owner, 'customer');
        $this->postJson('/api/v1/account/seller/products', [
            'name' => $productName,
            'sku' => 'sku-'.substr(md5($shopSlug.$productName), 0, 6),
            'price' => $price,
            'stock_qty' => $stock,
            'is_enabled' => true,
        ])->assertCreated();

        $product = Product::query()->where('seller_id', $seller->id)->firstOrFail();
        SellerProduct::query()->where('product_id', $product->id)->update([
            'approval_status' => SellerProduct::STATUS_APPROVED,
            'is_enabled' => true,
        ]);
        $product->update(['is_enabled' => true, 'available_for_order' => true, 'manage_stock' => true]);

        return [$seller->fresh(), $owner, $product->fresh()];
    }

    /** @return array{payment_method_id: int, shipping_method_id: int, email: string, billing_address: array, shipping_address: array} */
    protected function checkoutPayload(string $email = 'buyer@example.com', array $extra = []): array
    {
        $payment = PaymentMethod::query()->whereNull('seller_id')->where('is_enabled', true)->firstOrFail();
        $shipping = ShippingMethod::query()->whereNull('seller_id')->where('is_enabled', true)->firstOrFail();

        return array_merge([
            'email' => $email,
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Pat', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Pat', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ], $extra);
    }

    public function test_single_seller_checkout_creates_one_parent_and_one_seller_order(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, , $product] = $this->sellerWithApprovedProduct('a@example.com', 'shop-a', 'Mug A', 20);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();

        $response = $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();

        $orderId = (int) $response->json('order.id');
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, SellerOrder::query()->count());
        $this->assertSame(1, SellerOrderItem::query()->count());
        $this->assertSame(1, OrderItem::query()->count());

        $sellerOrder = SellerOrder::query()->firstOrFail();
        $this->assertSame($seller->id, (int) $sellerOrder->seller_id);
        $this->assertSame($orderId, (int) $sellerOrder->order_id);
        $this->assertSame(20.0, (float) $sellerOrder->subtotal);

        $response->assertJsonCount(1, 'seller_orders')
            ->assertJsonPath('seller_orders.0.seller_id', $seller->id);
    }

    public function test_two_seller_checkout_creates_one_parent_and_two_seller_orders(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$sellerA, , $productA] = $this->sellerWithApprovedProduct('a@example.com', 'shop-a', 'Mug A', 10);
        [$sellerB, , $productB] = $this->sellerWithApprovedProduct('b@example.com', 'shop-b', 'Mug B', 25);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $productA->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/cart', ['product_id' => $productB->id, 'qty' => 1])->assertOk();

        $response = $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();

        $orderId = (int) $response->json('order.id');
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(2, OrderItem::query()->count());
        $this->assertSame(2, SellerOrder::query()->count());
        $this->assertSame(2, SellerOrderItem::query()->count());

        $this->assertTrue(SellerOrder::query()->where('seller_id', $sellerA->id)->where('order_id', $orderId)->exists());
        $this->assertTrue(SellerOrder::query()->where('seller_id', $sellerB->id)->where('order_id', $orderId)->exists());

        $order = Order::query()->findOrFail($orderId);
        $sellerSubtotalSum = (float) SellerOrder::query()->where('order_id', $orderId)->sum('subtotal');
        $this->assertEqualsWithDelta((float) $order->subtotal, $sellerSubtotalSum, 0.01);
        $this->assertEqualsWithDelta(35.0, (float) $order->subtotal, 0.01);

        $response->assertJsonCount(2, 'seller_orders');
    }

    public function test_multiple_items_same_seller_plus_other_seller_groups_correctly(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$sellerA, $ownerA, $productA1] = $this->sellerWithApprovedProduct('a@example.com', 'shop-a', 'Mug A', 10);
        $this->actingAs($ownerA, 'customer');
        $created = $this->postJson('/api/v1/account/seller/products', [
            'name' => 'Mug A2',
            'sku' => 'a2',
            'price' => 12,
            'stock_qty' => 10,
            'is_enabled' => true,
        ])->assertCreated();
        $productA2 = Product::query()->findOrFail((int) $created->json('product.id'));
        SellerProduct::query()->where('product_id', $productA2->id)->update([
            'approval_status' => SellerProduct::STATUS_APPROVED,
            'is_enabled' => true,
        ]);
        $productA2->update(['is_enabled' => true, 'available_for_order' => true, 'manage_stock' => true]);

        [$sellerB, , $productB] = $this->sellerWithApprovedProduct('b@example.com', 'shop-b', 'Mug B', 30);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $productA1->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/cart', ['product_id' => $productA2->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/cart', ['product_id' => $productB->id, 'qty' => 1])->assertOk();

        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(3, OrderItem::query()->count());
        $this->assertSame(2, SellerOrder::query()->count());
        $this->assertSame(3, SellerOrderItem::query()->count());

        $orderA = SellerOrder::query()->where('seller_id', $sellerA->id)->firstOrFail();
        $orderB = SellerOrder::query()->where('seller_id', $sellerB->id)->firstOrFail();
        $this->assertSame(2, $orderA->items()->count());
        $this->assertSame(1, $orderB->items()->count());
        $this->assertEqualsWithDelta(22.0, (float) $orderA->subtotal, 0.01);
        $this->assertEqualsWithDelta(30.0, (float) $orderB->subtotal, 0.01);
    }

    public function test_platform_only_cart_creates_parent_without_seller_orders(): void
    {
        $this->seed();
        $platform = Product::query()->whereNull('seller_id')->where('is_enabled', true)->firstOrFail();

        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $platform->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated()
            ->assertJsonCount(0, 'seller_orders');

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(0, SellerOrder::query()->count());
    }

    public function test_blocked_seller_rejects_checkout_with_no_partial_order(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, , $product] = $this->sellerWithApprovedProduct('a@example.com', 'shop-a', 'Mug A');
        $stockBefore = (int) $product->stock_qty;

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();

        $seller->update(['status' => Seller::STATUS_BLOCKED, 'blocked_at' => now(), 'is_active' => false]);

        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertStatus(422);

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, SellerOrder::query()->count());
        $this->assertSame($stockBefore, (int) $product->fresh()->stock_qty);
    }

    public function test_inactive_product_rejects_checkout_with_no_partial_order(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [, , $product] = $this->sellerWithApprovedProduct('a@example.com', 'shop-a', 'Mug A');
        $stockBefore = (int) $product->stock_qty;

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();

        $product->update(['is_enabled' => false]);

        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertStatus(422);

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, SellerOrder::query()->count());
        $this->assertSame($stockBefore, (int) $product->fresh()->stock_qty);
    }

    public function test_insufficient_stock_rejects_checkout_atomically(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [, , $productA] = $this->sellerWithApprovedProduct('a@example.com', 'shop-a', 'Mug A', 10, 1);
        [, , $productB] = $this->sellerWithApprovedProduct('b@example.com', 'shop-b', 'Mug B', 10, 5);
        $stockA = (int) $productA->stock_qty;
        $stockB = (int) $productB->stock_qty;

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $productA->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/cart', ['product_id' => $productB->id, 'qty' => 1])->assertOk();

        // Force stock failure after cart add.
        $productA->update(['stock_qty' => 0, 'stock_status' => 'out_of_stock']);

        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertStatus(422);

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, SellerOrder::query()->count());
        $this->assertSame(0, (int) $productA->fresh()->stock_qty);
        $this->assertSame($stockB, (int) $productB->fresh()->stock_qty);
    }

    public function test_empty_cart_checkout_rejected(): void
    {
        $this->seed();
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertStatus(422);
        $this->assertSame(0, Order::query()->count());
    }

    public function test_duplicate_checkout_does_not_create_second_order(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [, , $product] = $this->sellerWithApprovedProduct('a@example.com', 'shop-a', 'Mug A');

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();

        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertStatus(422);

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, SellerOrder::query()->count());
    }

    public function test_client_seller_id_and_totals_are_ignored(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, , $product] = $this->sellerWithApprovedProduct('a@example.com', 'shop-a', 'Mug A', 18);
        [$other] = $this->sellerWithApprovedProduct('b@example.com', 'shop-b', 'Other', 99);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();

        $response = $this->postJson('/api/v1/checkout', $this->checkoutPayload('buyer@example.com', [
            'seller_id' => $other->id,
            'grand_total' => 1,
            'subtotal' => 1,
            'unit_price' => 1,
            'order_total' => 1,
        ]))->assertCreated();

        $sellerOrder = SellerOrder::query()->firstOrFail();
        $this->assertSame($seller->id, (int) $sellerOrder->seller_id);
        $this->assertNotSame($other->id, (int) $sellerOrder->seller_id);
        $this->assertEqualsWithDelta(18.0, (float) $response->json('order.subtotal'), 0.01);
        $this->assertNotEquals(1, (float) $response->json('order.grand_total'));
    }

    public function test_price_change_after_cart_uses_authoritative_catalog_price(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [, , $product] = $this->sellerWithApprovedProduct('a@example.com', 'shop-a', 'Mug A', 10);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();

        $product->update(['price' => 40]);

        $response = $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();
        $this->assertEqualsWithDelta(40.0, (float) $response->json('order.subtotal'), 0.01);
        $this->assertEqualsWithDelta(40.0, (float) OrderItem::query()->firstOrFail()->unit_price, 0.01);
    }

    public function test_seller_cannot_access_other_sellers_order(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$sellerA, $ownerA, $productA] = $this->sellerWithApprovedProduct('a@example.com', 'shop-a', 'Mug A');
        [$sellerB, $ownerB] = $this->sellerWithApprovedProduct('b@example.com', 'shop-b', 'Mug B');

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $productA->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();

        $sellerOrderA = SellerOrder::query()->where('seller_id', $sellerA->id)->firstOrFail();

        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->postJson('/api/v1/auth/login', [
            'email' => $ownerB->email,
            'password' => 'password123',
        ])->assertOk();
        $this->getJson('/api/v1/account/seller/orders/'.$sellerOrderA->id)->assertNotFound();
        $this->patchJson('/api/v1/account/seller/orders/'.$sellerOrderA->id, ['status' => 'processing'])->assertNotFound();
        $this->postJson('/api/v1/account/seller/orders/'.$sellerOrderA->id.'/shipments', [
            'tracking_number' => 'HACK',
        ])->assertNotFound();

        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->postJson('/api/v1/auth/login', [
            'email' => $ownerA->email,
            'password' => 'password123',
        ])->assertOk();
        $this->getJson('/api/v1/account/seller/orders/'.$sellerOrderA->id)->assertOk()
            ->assertJsonPath('order.id', $sellerOrderA->id);

        $this->assertSame($sellerB->id, (int) $ownerB->fresh()->seller->id);
    }

    public function test_customer_cannot_access_another_customers_order(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [, , $product] = $this->sellerWithApprovedProduct('a@example.com', 'shop-a', 'Mug A');

        $this->postJson('/api/v1/auth/logout');
        $buyerA = $this->registerCustomer('buyer-a@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $placed = $this->postJson('/api/v1/checkout', $this->checkoutPayload('buyer-a@example.com'))->assertCreated();
        $number = $placed->json('order.number');

        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->registerCustomer('buyer-b@example.com');
        $this->getJson('/api/v1/account/orders/'.$number)->assertNotFound();

        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->postJson('/api/v1/auth/login', [
            'email' => $buyerA->email,
            'password' => 'password123',
        ])->assertOk();
        $this->getJson('/api/v1/account/orders/'.$number)->assertOk()
            ->assertJsonPath('order.number', $number)
            ->assertJsonStructure(['seller_orders']);
    }

    public function test_seller_order_items_link_to_parent_order_items(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [, , $productA] = $this->sellerWithApprovedProduct('a@example.com', 'shop-a', 'Mug A', 10);
        [, , $productB] = $this->sellerWithApprovedProduct('b@example.com', 'shop-b', 'Mug B', 20);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $productA->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/cart', ['product_id' => $productB->id, 'qty' => 2])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();

        $order = Order::query()->with('items')->firstOrFail();
        foreach (SellerOrderItem::query()->get() as $soi) {
            $this->assertNotNull(OrderItem::query()->find($soi->order_item_id));
            $this->assertSame($order->id, (int) OrderItem::query()->findOrFail($soi->order_item_id)->order_id);
        }

        $itemTotals = (float) $order->items->sum('total');
        $sellerItemTotals = (float) SellerOrderItem::query()->sum('total');
        $this->assertEqualsWithDelta($itemTotals, $sellerItemTotals, 0.01);
    }
}
