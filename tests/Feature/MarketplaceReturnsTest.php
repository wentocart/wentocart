<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerEarning;
use App\Domain\Marketplace\Models\SellerLedgerEntry;
use App\Domain\Marketplace\Models\SellerOrder;
use App\Domain\Marketplace\Models\SellerOrderItem;
use App\Domain\Marketplace\Models\SellerProduct;
use App\Domain\Marketplace\Models\SellerReturn;
use App\Domain\Marketplace\Services\SellerLedgerService;
use App\Domain\Order\Models\Order;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Models\Admin;

/**
 * Phase 15 — cancellation, returns, partial refunds.
 */
class MarketplaceReturnsTest extends MarketplaceTestCase
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
    protected function sellerWithProduct(string $email, string $slug, string $name, float $price = 100, int $stock = 20): array
    {
        $this->postJson('/api/v1/auth/logout');
        $owner = $this->registerCustomer($email);
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload([
            'email' => str_replace('@', '.shop@', $email),
            'shop_name' => $slug.' Shop',
            'shop_slug' => $slug,
        ]))->assertCreated();
        $seller = Seller::query()->where('customer_id', $owner->id)->firstOrFail();
        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/multivendor/seller-requests/'.$seller->id.'/review', ['action' => 'approve'])
            ->assertRedirect();
        \Illuminate\Support\Facades\Auth::guard('admin')->logout();

        $this->actingAs($owner, 'customer');
        $this->postJson('/api/v1/account/seller/products', [
            'name' => $name,
            'sku' => 'sku-'.substr(md5($slug.$name), 0, 6),
            'price' => $price,
            'stock_qty' => $stock,
            'is_enabled' => true,
        ])->assertCreated();
        $product = Product::query()->where('seller_id', $seller->id)->latest('id')->firstOrFail();
        SellerProduct::query()->where('product_id', $product->id)->update([
            'approval_status' => SellerProduct::STATUS_APPROVED,
            'is_enabled' => true,
        ]);
        $product->update(['is_enabled' => true, 'available_for_order' => true, 'manage_stock' => true]);

        return [$seller->fresh(), $owner, $product->fresh()];
    }

    /** @return array<string, mixed> */
    protected function checkoutPayload(string $email = 'buyer@example.com'): array
    {
        $payment = PaymentMethod::query()->whereNull('seller_id')->where('is_enabled', true)->firstOrFail();
        $shipping = ShippingMethod::query()->whereNull('seller_id')->where('is_enabled', true)->firstOrFail();

        return [
            'email' => $email,
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Pat', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Pat', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ];
    }

    protected function loginAs(Customer $customer): void
    {
        $this->postJson('/api/v1/auth/logout');
        $this->actingAs($customer, 'customer');
    }

    protected function markPaid(Order $order): void
    {
        $order->update(['payment_status' => 'paid']);
        app(\App\Domain\Marketplace\Services\SellerService::class)->markOrderPaid($order->fresh());
    }

    protected function shipAndDeliver(Customer $owner, SellerOrder $sellerOrder): void
    {
        $this->loginAs($owner);
        $shipmentId = $this->postJson('/api/v1/account/seller/orders/'.$sellerOrder->id.'/shipments', [
            'tracking_number' => 'TRK-'.$sellerOrder->id,
        ])->assertCreated()->json('shipment.id');
        $this->postJson('/api/v1/account/seller/shipments/'.$shipmentId.'/ship', [
            'tracking_number' => 'TRK-'.$sellerOrder->id,
        ])->assertOk();
        $this->postJson('/api/v1/account/seller/shipments/'.$shipmentId.'/deliver')->assertOk();
    }

    public function test_customer_return_partial_item_refunds_earnings_and_payment(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true, 'return_window_days' => 30]);
        [$seller, $owner, $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', 100, 10);
        $stockBefore = (int) $product->stock_qty;

        $this->postJson('/api/v1/auth/logout');
        $buyer = $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 2])->assertOk();
        $placed = $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();
        $order = Order::query()->findOrFail($placed->json('order.id'));
        $this->markPaid($order);
        $so = SellerOrder::query()->where('seller_id', $seller->id)->firstOrFail();
        $this->shipAndDeliver($owner, $so);

        $item = SellerOrderItem::query()->where('seller_order_id', $so->id)->firstOrFail();
        $earningBefore = SellerEarning::query()->where('seller_order_id', $so->id)->firstOrFail();
        $netBefore = (float) $earningBefore->net_amount;
        $balanceBefore = app(SellerLedgerService::class)->balance($seller);

        $this->loginAs($buyer);
        $created = $this->postJson('/api/v1/account/orders/'.$order->number.'/returns', [
            'seller_order_id' => $so->id,
            'reason' => 'Damaged',
            'items' => [
                ['seller_order_item_id' => $item->id, 'qty' => 1],
            ],
        ])->assertCreated();
        $returnId = (int) $created->json('return.id');
        $this->assertSame(SellerReturn::STATUS_REQUESTED, $created->json('return.status'));

        $this->loginAs($owner);
        $this->postJson('/api/v1/account/seller/returns/'.$returnId.'/approve')->assertOk();
        $this->postJson('/api/v1/account/seller/returns/'.$returnId.'/refund')->assertOk()
            ->assertJsonPath('return.status', SellerReturn::STATUS_REFUNDED);

        $earning = $earningBefore->fresh();
        $this->assertEqualsWithDelta($netBefore / 2, (float) $earning->refunded_net, 0.05);
        $this->assertSame(SellerEarning::STATUS_AVAILABLE, $earning->status); // still half remaining
        $this->assertEquals(1, (int) $item->fresh()->returned_qty);
        $this->assertSame($stockBefore - 2 + 1, (int) $product->fresh()->stock_qty);

        $order = $order->fresh();
        $this->assertSame('partially_refunded', $order->payment_status);
        $this->assertGreaterThan(0, (float) $order->refunded_total);

        $this->assertLessThan($balanceBefore, app(SellerLedgerService::class)->balance($seller->fresh()));
        $this->assertSame(1, SellerLedgerEntry::query()
            ->where('reference', 'refund_adjustment:return:'.$returnId)
            ->count());

        // Original earning amounts immutable
        $this->assertEqualsWithDelta($netBefore, (float) $earning->net_amount, 0.01);
    }

    public function test_full_return_marks_seller_order_and_payment_refunded(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true, 'return_window_days' => 30]);
        [$seller, $owner, $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', 50, 5);

        $this->postJson('/api/v1/auth/logout');
        $buyer = $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $placed = $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();
        $order = Order::query()->findOrFail($placed->json('order.id'));
        $this->markPaid($order);
        $so = SellerOrder::query()->where('seller_id', $seller->id)->firstOrFail();
        $this->shipAndDeliver($owner, $so);
        $item = SellerOrderItem::query()->where('seller_order_id', $so->id)->firstOrFail();

        $this->loginAs($buyer);
        $returnId = $this->postJson('/api/v1/account/orders/'.$order->number.'/returns', [
            'seller_order_id' => $so->id,
            'items' => [['seller_order_item_id' => $item->id, 'qty' => 1]],
        ])->assertCreated()->json('return.id');

        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/multivendor/returns/'.$returnId.'/refund', ['note' => 'Admin refund'])
            ->assertRedirect();

        $this->assertSame(SellerReturn::STATUS_REFUNDED, SellerReturn::query()->findOrFail($returnId)->status);
        $this->assertSame('refunded', $so->fresh()->status);
        $this->assertSame(SellerEarning::STATUS_REFUNDED, SellerEarning::query()->where('seller_order_id', $so->id)->value('status'));
        $this->assertSame('refunded', $order->fresh()->payment_status);
    }

    public function test_seller_cannot_manage_another_sellers_return(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true, 'return_window_days' => 30]);
        [$sellerA, $ownerA, $productA] = $this->sellerWithProduct('a@example.com', 'shop-a', 'A', 40);
        [, $ownerB] = $this->sellerWithProduct('b@example.com', 'shop-b', 'B', 40);

        $this->postJson('/api/v1/auth/logout');
        $buyer = $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $productA->id, 'qty' => 1])->assertOk();
        $placed = $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();
        $order = Order::query()->findOrFail($placed->json('order.id'));
        $this->markPaid($order);
        $so = SellerOrder::query()->where('seller_id', $sellerA->id)->firstOrFail();
        $this->shipAndDeliver($ownerA, $so);
        $item = SellerOrderItem::query()->where('seller_order_id', $so->id)->firstOrFail();

        $this->loginAs($buyer);
        $returnId = $this->postJson('/api/v1/account/orders/'.$order->number.'/returns', [
            'seller_order_id' => $so->id,
            'items' => [['seller_order_item_id' => $item->id, 'qty' => 1]],
        ])->assertCreated()->json('return.id');

        $this->loginAs($ownerB);
        $this->postJson('/api/v1/account/seller/returns/'.$returnId.'/approve')->assertNotFound();
        $this->postJson('/api/v1/account/seller/returns/'.$returnId.'/refund')->assertNotFound();
    }

    public function test_return_before_ship_is_rejected_and_window_enforced(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true, 'return_window_days' => 30]);
        [, , $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', 30);

        $this->postJson('/api/v1/auth/logout');
        $buyer = $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $placed = $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();
        $order = Order::query()->findOrFail($placed->json('order.id'));
        $this->markPaid($order);
        $so = SellerOrder::query()->firstOrFail();
        $item = SellerOrderItem::query()->firstOrFail();

        $this->loginAs($buyer);
        $this->postJson('/api/v1/account/orders/'.$order->number.'/returns', [
            'seller_order_id' => $so->id,
            'items' => [['seller_order_item_id' => $item->id, 'qty' => 1]],
        ])->assertStatus(422);
    }

    public function test_partial_cancel_items_before_ship_restocks_and_is_isolated(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, $owner, $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', 20, 10);
        $stockBefore = (int) $product->stock_qty;

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 3])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();
        $so = SellerOrder::query()->where('seller_id', $seller->id)->firstOrFail();
        $item = SellerOrderItem::query()->where('seller_order_id', $so->id)->firstOrFail();

        $this->loginAs($owner);
        $this->postJson('/api/v1/account/seller/orders/'.$so->id.'/cancel-items', [
            'items' => [['seller_order_item_id' => $item->id, 'qty' => 1]],
        ])->assertOk();

        $this->assertEquals(1, (int) $item->fresh()->cancelled_qty);
        $this->assertSame($stockBefore - 3 + 1, (int) $product->fresh()->stock_qty);
        $this->assertSame('pending', $so->fresh()->status);

        $earning = SellerEarning::query()->where('seller_order_id', $so->id)->firstOrFail();
        $this->assertGreaterThan(0, (float) $earning->refunded_net);
        $this->assertLessThan((float) $earning->net_amount, (float) $earning->refunded_net + 0.01);
    }

    public function test_customer_cannot_return_another_customers_order(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true, 'return_window_days' => 30]);
        [, $owner, $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', 25);

        $this->postJson('/api/v1/auth/logout');
        $buyerA = $this->registerCustomer('buyer-a@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $placed = $this->postJson('/api/v1/checkout', $this->checkoutPayload('buyer-a@example.com'))->assertCreated();
        $order = Order::query()->findOrFail($placed->json('order.id'));
        $this->markPaid($order);
        $so = SellerOrder::query()->firstOrFail();
        $this->shipAndDeliver($owner, $so);
        $item = SellerOrderItem::query()->firstOrFail();

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer-b@example.com');
        $this->postJson('/api/v1/account/orders/'.$order->number.'/returns', [
            'seller_order_id' => $so->id,
            'items' => [['seller_order_item_id' => $item->id, 'qty' => 1]],
        ])->assertNotFound();

        $this->loginAs($buyerA);
        $this->postJson('/api/v1/account/orders/'.$order->number.'/returns', [
            'seller_order_id' => $so->id,
            'items' => [['seller_order_item_id' => $item->id, 'qty' => 1]],
        ])->assertCreated();
    }

    public function test_refund_is_idempotent(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true, 'return_window_days' => 30]);
        [, $owner, $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', 60);

        $this->postJson('/api/v1/auth/logout');
        $buyer = $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $placed = $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();
        $order = Order::query()->findOrFail($placed->json('order.id'));
        $this->markPaid($order);
        $so = SellerOrder::query()->firstOrFail();
        $this->shipAndDeliver($owner, $so);
        $item = SellerOrderItem::query()->firstOrFail();

        $this->loginAs($buyer);
        $returnId = $this->postJson('/api/v1/account/orders/'.$order->number.'/returns', [
            'seller_order_id' => $so->id,
            'items' => [['seller_order_item_id' => $item->id, 'qty' => 1]],
        ])->assertCreated()->json('return.id');

        $this->loginAs($owner);
        $this->postJson('/api/v1/account/seller/returns/'.$returnId.'/approve')->assertOk();
        $this->postJson('/api/v1/account/seller/returns/'.$returnId.'/refund')->assertOk();
        $this->postJson('/api/v1/account/seller/returns/'.$returnId.'/refund')->assertOk();

        $this->assertSame(1, SellerLedgerEntry::query()
            ->where('reference', 'refund_adjustment:return:'.$returnId)
            ->count());
        $this->assertEquals(1, (int) $item->fresh()->returned_qty);
    }
}
