<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerEarning;
use App\Domain\Marketplace\Models\SellerOrder;
use App\Domain\Marketplace\Models\SellerProduct;
use App\Domain\Marketplace\Services\SellerShippingAllocationService;
use App\Domain\Order\Models\Order;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Shipping\Models\Shipment;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Models\Admin;

/**
 * Phase 14 — seller shipping allocation + multi-shipment reconciliation.
 */
class MarketplaceShippingTest extends MarketplaceTestCase
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
    protected function sellerWithProduct(string $email, string $slug, string $name, float $price = 20): array
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
            'stock_qty' => 20,
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
    protected function checkoutPayload(string $email = 'buyer@example.com', ?int $shippingMethodId = null): array
    {
        $payment = PaymentMethod::query()->whereNull('seller_id')->where('is_enabled', true)->firstOrFail();
        $shippingId = $shippingMethodId
            ?? (int) ShippingMethod::query()->where('code', 'flat')->value('id')
            ?: ShippingMethod::query()->whereNull('seller_id')->where('is_enabled', true)->value('id');

        return [
            'email' => $email,
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shippingId,
            'billing_address' => ['first_name' => 'Pat', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Pat', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ];
    }

    protected function setPlatformFlatShipping(float $price): ShippingMethod
    {
        $shipping = ShippingMethod::query()->where('code', 'flat')->firstOrFail();
        $shipping->update([
            'type' => 'flat',
            'driver' => 'offline',
            'price' => $price,
        ]);

        return $shipping->fresh();
    }

    protected function loginAs(Customer $customer): void
    {
        $this->postJson('/api/v1/auth/logout');
        $this->actingAs($customer, 'customer');
    }

    public function test_single_seller_receives_full_parent_shipping(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, , $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', 100);
        $this->setPlatformFlatShipping(15);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $placed = $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();

        $order = Order::query()->findOrFail($placed->json('order.id'));
        $so = SellerOrder::query()->where('seller_id', $seller->id)->firstOrFail();

        $this->assertEquals(15.0, (float) $order->shipping_total);
        $this->assertEquals(15.0, (float) $so->shipping_total);
        $this->assertSame((int) $order->shipping_method_id, (int) $so->shipping_method_id);
        $this->assertEqualsWithDelta(
            (float) $so->subtotal - (float) $so->discount_total + (float) $so->tax_total + 15.0,
            (float) $so->grand_total,
            0.01
        );

        // Commission still excludes shipping.
        $earning = SellerEarning::query()->where('seller_order_id', $so->id)->sum('net_amount');
        $this->assertEqualsWithDelta((float) $so->earning_total, (float) $earning, 0.01);
        $this->assertLessThan((float) $so->grand_total, (float) $so->earning_total + (float) $so->commission_total);
    }

    public function test_two_sellers_split_shipping_proportionally_with_exact_reconcile(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$sellerA, , $productA] = $this->sellerWithProduct('a@example.com', 'shop-a', 'A', 10);
        [$sellerB, , $productB] = $this->sellerWithProduct('b@example.com', 'shop-b', 'B', 30);
        $this->setPlatformFlatShipping(10);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $productA->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/cart', ['product_id' => $productB->id, 'qty' => 1])->assertOk();
        $placed = $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();

        $order = Order::query()->findOrFail($placed->json('order.id'));
        $soA = SellerOrder::query()->where('seller_id', $sellerA->id)->firstOrFail();
        $soB = SellerOrder::query()->where('seller_id', $sellerB->id)->firstOrFail();

        $this->assertEquals(10.0, (float) $order->shipping_total);
        // 10/(10+30)=2.5 and 30/40=7.5 — remainder on largest (B).
        $this->assertEqualsWithDelta(2.5, (float) $soA->shipping_total, 0.01);
        $this->assertEqualsWithDelta(7.5, (float) $soB->shipping_total, 0.01);
        $this->assertEqualsWithDelta(
            (float) $order->shipping_total,
            (float) $soA->shipping_total + (float) $soB->shipping_total,
            0.01
        );

        $allocation = app(SellerShippingAllocationService::class)->allocate($order);
        $this->assertTrue(app(SellerShippingAllocationService::class)->assertReconciles($allocation));
        $this->assertSame(SellerShippingAllocationService::STRATEGY_PROPORTIONAL, $allocation['strategy']);
    }

    public function test_seller_owned_shipping_method_charges_that_seller_only(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$sellerA, $ownerA, $productA] = $this->sellerWithProduct('a@example.com', 'shop-a', 'A', 50);
        [$sellerB, , $productB] = $this->sellerWithProduct('b@example.com', 'shop-b', 'B', 50);

        $this->loginAs($ownerA);
        $this->postJson('/api/v1/account/seller/shipping', [
            'name' => 'Ada Express',
            'type' => 'flat',
            'price' => 12,
            'is_enabled' => true,
        ])->assertCreated();

        $sellerMethod = ShippingMethod::query()
            ->where('seller_id', $sellerA->id)
            ->where('is_enabled', true)
            ->firstOrFail();
        $sellerMethod->update(['price' => 12]);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $productA->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/cart', ['product_id' => $productB->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload('buyer@example.com', (int) $sellerMethod->id))
            ->assertCreated();

        $order = Order::query()->latest('id')->firstOrFail();
        $soA = SellerOrder::query()->where('seller_id', $sellerA->id)->firstOrFail();
        $soB = SellerOrder::query()->where('seller_id', $sellerB->id)->firstOrFail();

        $this->assertEquals(12.0, (float) $order->shipping_total);
        $this->assertEquals(12.0, (float) $soA->shipping_total);
        $this->assertEquals(0.0, (float) $soB->shipping_total);

        $allocation = app(SellerShippingAllocationService::class)->allocate($order);
        $this->assertSame(SellerShippingAllocationService::STRATEGY_SELLER_OWNED, $allocation['strategy']);
    }

    public function test_mixed_platform_and_seller_leaves_platform_shipping_bucket(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, , $sellerProduct] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Seller Mug', 30);
        $platform = Product::query()->whereNull('seller_id')->where('is_enabled', true)->where('available_for_order', true)->firstOrFail();
        $platform->update(['price' => 10, 'manage_stock' => false]);

        $this->setPlatformFlatShipping(8);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $sellerProduct->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/cart', ['product_id' => $platform->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();

        $order = Order::query()->latest('id')->firstOrFail();
        $so = SellerOrder::query()->where('seller_id', $seller->id)->firstOrFail();
        $allocation = app(SellerShippingAllocationService::class)->allocate($order);

        $this->assertEquals(8.0, (float) $order->shipping_total);
        $this->assertTrue(app(SellerShippingAllocationService::class)->assertReconciles($allocation));
        $this->assertGreaterThan(0.0, (float) $allocation['platform']);
        $this->assertEqualsWithDelta(
            (float) $order->shipping_total,
            (float) $so->shipping_total + (float) $allocation['platform'],
            0.01
        );
        // 30/(30+10)=6 and 10/40=2
        $this->assertEqualsWithDelta(6.0, (float) $so->shipping_total, 0.01);
        $this->assertEqualsWithDelta(2.0, (float) $allocation['platform'], 0.01);
    }

    public function test_shipment_snapshots_shipping_cost_and_customer_sees_all_shipments(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$sellerA, $ownerA, $productA] = $this->sellerWithProduct('a@example.com', 'shop-a', 'A', 20);
        [$sellerB, $ownerB, $productB] = $this->sellerWithProduct('b@example.com', 'shop-b', 'B', 20);
        ShippingMethod::query()->whereNull('seller_id')->where('is_enabled', true)->update(['price' => 10]);

        $this->postJson('/api/v1/auth/logout');
        $buyer = $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $productA->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/cart', ['product_id' => $productB->id, 'qty' => 1])->assertOk();
        $number = $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated()->json('order.number');

        $soA = SellerOrder::query()->where('seller_id', $sellerA->id)->firstOrFail();
        $soB = SellerOrder::query()->where('seller_id', $sellerB->id)->firstOrFail();

        $this->loginAs($ownerA);
        $shipA = $this->postJson('/api/v1/account/seller/orders/'.$soA->id.'/shipments', [
            'carrier' => 'UPS',
            'tracking_number' => 'A-TRACK',
        ])->assertCreated();
        $this->assertEqualsWithDelta((float) $soA->shipping_total, (float) $shipA->json('shipment.shipping_cost'), 0.01);
        $this->assertSame((int) $soA->shipping_method_id, (int) $shipA->json('shipment.shipping_method_id'));

        $this->loginAs($ownerB);
        $this->postJson('/api/v1/account/seller/orders/'.$soB->id.'/shipments', [
            'carrier' => 'FedEx',
            'tracking_number' => 'B-TRACK',
        ])->assertCreated();

        $this->assertSame(2, Shipment::query()->where('order_id', $soA->order_id)->count());

        $this->loginAs($buyer);
        $view = $this->getJson('/api/v1/account/orders/'.$number)->assertOk();
        $this->assertCount(2, $view->json('shipments'));
        $trackings = collect($view->json('shipments'))->pluck('tracking_number')->sort()->values()->all();
        $this->assertSame(['A-TRACK', 'B-TRACK'], $trackings);
    }

    public function test_rounding_remainder_reconciles_exactly_across_three_sellers(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$sellerA, , $productA] = $this->sellerWithProduct('a@example.com', 'shop-a', 'A', 33.33);
        [, , $productB] = $this->sellerWithProduct('b@example.com', 'shop-b', 'B', 33.33);
        [, , $productC] = $this->sellerWithProduct('c@example.com', 'shop-c', 'C', 33.34);
        $this->setPlatformFlatShipping(0.01);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $productA->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/cart', ['product_id' => $productB->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/cart', ['product_id' => $productC->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();

        $order = Order::query()->latest('id')->firstOrFail();
        $sum = (float) SellerOrder::query()->where('order_id', $order->id)->sum('shipping_total');
        $this->assertEqualsWithDelta((float) $order->shipping_total, $sum, 0.01);
        $this->assertSame(1, SellerOrder::query()->where('seller_id', $sellerA->id)->where('order_id', $order->id)->count());
    }

    public function test_zero_shipping_allocates_zero(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, , $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'FreeShip Mug', 40);
        $this->setPlatformFlatShipping(0);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();

        $so = SellerOrder::query()->where('seller_id', $seller->id)->firstOrFail();
        $this->assertEquals(0.0, (float) $so->shipping_total);
        $this->assertEquals(0.0, (float) Order::query()->findOrFail($so->order_id)->shipping_total);
    }
}
