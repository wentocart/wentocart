<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerOrder;
use App\Domain\Marketplace\Models\SellerProduct;
use App\Domain\Order\Models\Order;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Shipping\Models\Shipment;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Models\Admin;

/**
 * Phase 08 — seller fulfillment / independent shipments.
 */
class MarketplaceFulfillmentTest extends MarketplaceTestCase
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

    public function test_single_seller_fulfillment_lifecycle(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, $owner, $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug');

        $this->postJson('/api/v1/auth/logout');
        $buyer = $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $placed = $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();
        $number = $placed->json('order.number');
        $sellerOrder = SellerOrder::query()->where('seller_id', $seller->id)->firstOrFail();

        $this->loginAs($owner);
        $created = $this->postJson('/api/v1/account/seller/orders/'.$sellerOrder->id.'/shipments', [
            'carrier' => 'UPS',
            'tracking_number' => 'TRACK-1',
        ])->assertCreated();
        $shipmentId = (int) $created->json('shipment.id');
        $this->assertSame(Shipment::STATUS_PROCESSING, $created->json('shipment.status'));

        // Idempotent create
        $this->postJson('/api/v1/account/seller/orders/'.$sellerOrder->id.'/shipments', [
            'tracking_number' => 'TRACK-2',
        ])->assertCreated()->assertJsonPath('shipment.id', $shipmentId);
        $this->assertSame(1, Shipment::query()->count());

        $this->postJson('/api/v1/account/seller/shipments/'.$shipmentId.'/ship', [
            'tracking_number' => 'TRACK-1',
            'carrier' => 'UPS',
        ])->assertOk()->assertJsonPath('shipment.status', Shipment::STATUS_SHIPPED);

        $this->assertSame('shipped', $sellerOrder->fresh()->status);
        $this->assertSame('shipped', Order::query()->where('number', $number)->value('status'));

        $this->postJson('/api/v1/account/seller/shipments/'.$shipmentId.'/deliver')->assertOk()
            ->assertJsonPath('shipment.status', Shipment::STATUS_DELIVERED);
        $this->assertSame('completed', $sellerOrder->fresh()->status);
        $this->assertSame('completed', Order::query()->where('number', $number)->value('status'));

        $this->loginAs($buyer);
        $this->getJson('/api/v1/account/orders/'.$number)->assertOk()
            ->assertJsonPath('seller_orders.0.shipment.status', Shipment::STATUS_DELIVERED)
            ->assertJsonPath('seller_orders.0.shipment.tracking_number', 'TRACK-1');
    }

    public function test_two_sellers_fulfill_independently_without_false_parent_delivered(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$sellerA, $ownerA, $productA] = $this->sellerWithProduct('a@example.com', 'shop-a', 'A', 10);
        [$sellerB, $ownerB, $productB] = $this->sellerWithProduct('b@example.com', 'shop-b', 'B', 20);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $productA->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/cart', ['product_id' => $productB->id, 'qty' => 1])->assertOk();
        $placed = $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();
        $orderId = (int) $placed->json('order.id');
        $number = $placed->json('order.number');

        $soA = SellerOrder::query()->where('seller_id', $sellerA->id)->firstOrFail();
        $soB = SellerOrder::query()->where('seller_id', $sellerB->id)->firstOrFail();

        $this->loginAs($ownerA);
        $shipA = $this->postJson('/api/v1/account/seller/orders/'.$soA->id.'/shipments', [
            'tracking_number' => 'A-1',
        ])->assertCreated()->json('shipment.id');
        $this->postJson('/api/v1/account/seller/shipments/'.$shipA.'/ship', ['tracking_number' => 'A-1'])->assertOk();
        $this->postJson('/api/v1/account/seller/shipments/'.$shipA.'/deliver')->assertOk();

        $this->assertSame('completed', $soA->fresh()->status);
        $this->assertSame('pending', $soB->fresh()->status);
        // Mixed: parent must NOT be completed/shipped yet.
        $this->assertSame('processing', Order::query()->findOrFail($orderId)->status);

        $this->loginAs($ownerB);
        $shipB = $this->postJson('/api/v1/account/seller/orders/'.$soB->id.'/shipments', [
            'tracking_number' => 'B-1',
        ])->assertCreated()->json('shipment.id');
        $this->postJson('/api/v1/account/seller/shipments/'.$shipB.'/ship', ['tracking_number' => 'B-1'])->assertOk();
        $this->assertSame('shipped', Order::query()->findOrFail($orderId)->status);

        $this->postJson('/api/v1/account/seller/shipments/'.$shipB.'/deliver')->assertOk();
        $this->assertSame('completed', Order::query()->findOrFail($orderId)->status);

        $this->postJson('/api/v1/auth/logout');
        $this->actingAs(Customer::query()->where('email', 'buyer@example.com')->firstOrFail(), 'customer');
        $view = $this->getJson('/api/v1/account/orders/'.$number)->assertOk();
        $this->assertCount(2, $view->json('seller_orders'));
        $this->assertCount(2, $view->json('shipments'));
    }

    public function test_invalid_transitions_and_tracking_required(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [, $owner, $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug');

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();
        $sellerOrder = SellerOrder::query()->firstOrFail();

        $this->loginAs($owner);
        $shipmentId = $this->postJson('/api/v1/account/seller/orders/'.$sellerOrder->id.'/shipments')->assertCreated()->json('shipment.id');
        $this->postJson('/api/v1/account/seller/shipments/'.$shipmentId.'/ship')->assertStatus(422);
        $this->postJson('/api/v1/account/seller/shipments/'.$shipmentId.'/deliver')->assertStatus(422);

        $this->postJson('/api/v1/account/seller/shipments/'.$shipmentId.'/ship', [
            'tracking_number' => 'OK-1',
        ])->assertOk();
        $this->postJson('/api/v1/account/seller/shipments/'.$shipmentId.'/ship', [
            'tracking_number' => 'OK-1',
        ])->assertOk(); // idempotent same status
        $this->postJson('/api/v1/account/seller/shipments/'.$shipmentId.'/ready')->assertStatus(422);

        $this->patchJson('/api/v1/account/seller/orders/'.$sellerOrder->id, ['status' => 'shipped'])->assertStatus(422);
    }

    public function test_seller_cannot_fulfill_another_sellers_order(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$sellerA, , $productA] = $this->sellerWithProduct('a@example.com', 'shop-a', 'A');
        [, $ownerB] = $this->sellerWithProduct('b@example.com', 'shop-b', 'B');

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $productA->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();
        $soA = SellerOrder::query()->where('seller_id', $sellerA->id)->firstOrFail();

        $this->loginAs($ownerB);
        $this->postJson('/api/v1/account/seller/orders/'.$soA->id.'/shipments', [
            'tracking_number' => 'X',
        ])->assertNotFound();

        // Create as owner A then B tries to ship
        $this->postJson('/api/v1/auth/logout');
        $ownerA = Customer::query()->where('email', 'a@example.com')->firstOrFail();
        $this->loginAs($ownerA);
        $shipmentId = $this->postJson('/api/v1/account/seller/orders/'.$soA->id.'/shipments', [
            'tracking_number' => 'A-1',
        ])->assertCreated()->json('shipment.id');

        $this->loginAs($ownerB);
        $this->postJson('/api/v1/account/seller/shipments/'.$shipmentId.'/ship', [
            'tracking_number' => 'A-1',
        ])->assertNotFound();
        $this->patchJson('/api/v1/account/seller/shipments/'.$shipmentId, [
            'tracking_number' => 'HACK',
        ])->assertNotFound();
    }

    public function test_customer_cannot_see_another_customers_shipments(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [, $owner, $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug');

        $this->postJson('/api/v1/auth/logout');
        $buyerA = $this->registerCustomer('buyer-a@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $number = $this->postJson('/api/v1/checkout', $this->checkoutPayload('buyer-a@example.com'))->assertCreated()->json('order.number');
        $so = SellerOrder::query()->firstOrFail();

        $this->loginAs($owner);
        $this->postJson('/api/v1/account/seller/orders/'.$so->id.'/shipments', ['tracking_number' => 'PRIV'])->assertCreated();

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer-b@example.com');
        $this->getJson('/api/v1/account/orders/'.$number)->assertNotFound();

        $this->loginAs($buyerA);
        $this->getJson('/api/v1/account/orders/'.$number)->assertOk()
            ->assertJsonPath('seller_orders.0.shipment.tracking_number', 'PRIV');
    }

    public function test_cancelled_seller_order_cannot_create_shipment(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [, $owner, $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug');

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();
        $so = SellerOrder::query()->firstOrFail();

        $this->loginAs($owner);
        $this->patchJson('/api/v1/account/seller/orders/'.$so->id, ['status' => 'cancelled'])->assertOk();
        $this->postJson('/api/v1/account/seller/orders/'.$so->id.'/shipments')->assertStatus(422);
    }

    public function test_seller_can_update_order_status_from_pending_to_processing(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [, $owner, $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug');

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();
        $so = SellerOrder::query()->firstOrFail();
        $this->assertSame('pending', $so->status);

        $this->loginAs($owner);
        $this->patchJson('/api/v1/account/seller/orders/'.$so->id, ['status' => 'processing'])
            ->assertOk()
            ->assertJsonPath('order.status', 'processing');
        $this->assertSame('processing', $so->fresh()->status);
    }
}
