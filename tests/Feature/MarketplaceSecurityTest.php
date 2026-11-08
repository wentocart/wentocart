<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerEarning;
use App\Domain\Marketplace\Models\SellerLedgerEntry;
use App\Domain\Marketplace\Models\SellerOrder;
use App\Domain\Marketplace\Models\SellerProduct;
use App\Domain\Marketplace\Models\SellerReturn;
use App\Domain\Marketplace\Models\SellerWithdrawal;
use App\Domain\Order\Models\Order;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Models\Admin;

/**
 * Phase 20 — API & security hardening regression matrix.
 */
class MarketplaceSecurityTest extends MarketplaceTestCase
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

    protected function registerCustomer(string $email): Customer
    {
        $this->postJson('/api/v1/auth/logout');
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Buyer',
            'email' => $email,
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();

        return Customer::query()->where('email', $email)->firstOrFail();
    }

    /** @return array{0: Seller, 1: Customer, 2: Product} */
    protected function approvedSellerWithProduct(string $email, string $slug): array
    {
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
            'name' => $slug.' Mug',
            'sku' => 'sku-'.substr(md5($slug), 0, 6),
            'price' => 25,
            'stock_qty' => 10,
            'is_enabled' => true,
        ])->assertCreated();

        $product = Product::query()->where('seller_id', $seller->id)->latest('id')->firstOrFail();
        SellerProduct::query()->where('product_id', $product->id)->update([
            'approval_status' => SellerProduct::STATUS_APPROVED,
            'is_enabled' => true,
        ]);
        $product->update(['is_enabled' => true, 'manage_stock' => true, 'stock_qty' => 10]);

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

    public function test_unauthenticated_customer_cannot_access_protected_account_apis(): void
    {
        $this->seed();
        $this->getJson('/api/v1/account/orders')->assertUnauthorized();
        $this->getJson('/api/v1/account/seller/dashboard')->assertUnauthorized();
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload())->assertUnauthorized();
    }

    public function test_plain_customer_cannot_access_seller_portal(): void
    {
        $this->seed();
        $customer = $this->registerCustomer('buyer-only@example.com');
        $this->actingAs($customer, 'customer');

        $this->getJson('/api/v1/account/seller/dashboard')->assertForbidden();
        $this->getJson('/api/v1/account/seller/orders')->assertForbidden();
        $this->postJson('/api/v1/account/seller/products', [
            'name' => 'Blocked',
            'price' => 10,
            'stock_qty' => 1,
        ])->assertForbidden();
    }

    public function test_pending_seller_cannot_access_seller_portal(): void
    {
        $this->seed();
        $customer = $this->registerCustomer('pending@example.com');
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload([
            'email' => 'pending.shop@example.com',
            'shop_slug' => 'pending-shop',
        ]))->assertCreated();

        $this->actingAs($customer, 'customer');
        $this->getJson('/api/v1/account/seller/dashboard')->assertForbidden();
    }

    public function test_customer_cannot_read_another_customers_order(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [, , $product] = $this->approvedSellerWithProduct('seller@example.com', 'sec-shop');

        $this->postJson('/api/v1/auth/logout');
        $buyerA = $this->registerCustomer('buyer-a@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $placed = $this->postJson('/api/v1/checkout', $this->checkoutPayload('buyer-a@example.com'))->assertCreated();
        $number = (string) $placed->json('order.number');

        $buyerB = $this->registerCustomer('buyer-b@example.com');
        $this->actingAs($buyerB, 'customer');
        $this->getJson('/api/v1/account/orders/'.$number)->assertNotFound();
    }

    public function test_seller_cannot_access_peer_orders_products_returns_or_shipments(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$sellerA, $ownerA, $productA] = $this->approvedSellerWithProduct('a@example.com', 'sec-a');
        [$sellerB, $ownerB, $productB] = $this->approvedSellerWithProduct('b@example.com', 'sec-b');

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $productA->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();
        Order::query()->latest('id')->firstOrFail()->update(['payment_status' => 'paid']);

        $orderA = SellerOrder::query()->where('seller_id', $sellerA->id)->firstOrFail();
        $this->loginAs($ownerA);
        $shipA = $this->postJson('/api/v1/account/seller/orders/'.$orderA->id.'/shipments', ['tracking_number' => 'TRK-A'])
            ->assertCreated()
            ->json('shipment.id');

        $returnA = SellerReturn::query()->create([
            'seller_id' => $sellerA->id,
            'seller_order_id' => $orderA->id,
            'order_id' => $orderA->order_id,
            'customer_id' => Order::query()->find($orderA->order_id)?->customer_id,
            'number' => 'RET-SEC-1',
            'status' => 'requested',
            'reason' => 'test',
        ]);

        $this->loginAs($ownerB);
        $this->getJson('/api/v1/account/seller/orders/'.$orderA->id)->assertNotFound();
        $this->patchJson('/api/v1/account/seller/orders/'.$orderA->id, ['status' => 'processing'])->assertNotFound();
        $this->getJson('/api/v1/account/seller/products/'.$productA->id)->assertNotFound();
        $this->patchJson('/api/v1/account/seller/products/'.$productA->id, [
            'name' => 'Hijack',
            'price' => 1,
            'stock_qty' => 1,
        ])->assertNotFound();
        $this->postJson('/api/v1/account/seller/products/'.$productA->id.'/inventory/adjust', [
            'delta' => -1,
        ])->assertForbidden();
        $this->patchJson('/api/v1/account/seller/shipments/'.$shipA, ['tracking_number' => 'evil'])->assertNotFound();
        $this->postJson('/api/v1/account/seller/returns/'.$returnA->id.'/approve')->assertNotFound();

        // Peer resources remain untouched.
        $this->assertSame((int) $sellerA->id, (int) $productA->fresh()->seller_id);
        $this->assertSame((int) $sellerB->id, (int) $productB->fresh()->seller_id);
        $this->assertSame('requested', $returnA->fresh()->status);
    }

    public function test_application_mass_assignment_cannot_set_commission_or_status(): void
    {
        $this->seed();
        $default = MarketplaceSettings::defaultCommission();
        $this->registerCustomer('mass@example.com');
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload([
            'email' => 'mass.shop@example.com',
            'shop_slug' => 'mass-shop',
            'status' => 'approved',
            'commission_rate' => 1,
            'reviewed_by' => 999,
        ]))->assertCreated();

        $seller = Seller::query()->where('shop_slug', 'mass-shop')->firstOrFail();
        $this->assertSame(Seller::STATUS_PENDING, $seller->status);
        $this->assertSame((float) $default, (float) $seller->commission_rate);
        $this->assertNull($seller->reviewed_by);
    }

    public function test_payout_request_ignores_client_status_and_cannot_overdraw(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, $owner] = array_slice($this->approvedSellerWithProduct('pay@example.com', 'pay-shop'), 0, 2);

        SellerEarning::query()->create([
            'seller_id' => $seller->id,
            'gross_amount' => 50,
            'commission_amount' => 5,
            'net_amount' => 45,
            'status' => SellerEarning::STATUS_AVAILABLE,
        ]);
        SellerLedgerEntry::query()->create([
            'seller_id' => $seller->id,
            'entry_type' => SellerLedgerEntry::TYPE_ORDER_EARNING,
            'direction' => SellerLedgerEntry::DIRECTION_CREDIT,
            'amount' => 45,
            'currency_code' => 'USD',
            'reference' => 'sec:test:'.$seller->id,
            'description' => 'Test credit',
        ]);

        $this->loginAs($owner);
        $this->postJson('/api/v1/account/seller/payouts', [
            'amount' => 45,
            'status' => 'paid',
            'payout_status' => 'paid',
        ])->assertCreated();

        $row = SellerWithdrawal::query()->firstOrFail();
        $this->assertSame(SellerWithdrawal::STATUS_REQUESTED, $row->status);

        $this->postJson('/api/v1/account/seller/payouts', [
            'amount' => 1,
            'status' => 'paid',
        ])->assertUnprocessable();
    }

    public function test_invalid_seller_order_status_transition_is_rejected(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [, $owner, $product] = $this->approvedSellerWithProduct('stat@example.com', 'stat-shop');

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();
        Order::query()->latest('id')->firstOrFail()->update(['payment_status' => 'paid']);

        $so = SellerOrder::query()->firstOrFail();
        $this->loginAs($owner);
        $ship = $this->postJson('/api/v1/account/seller/orders/'.$so->id.'/shipments', [
            'tracking_number' => 'T-SEC',
        ])->assertCreated()->json('shipment.id');
        $this->postJson('/api/v1/account/seller/shipments/'.$ship.'/ship', ['tracking_number' => 'T-SEC'])->assertOk();

        $this->patchJson('/api/v1/account/seller/orders/'.$so->id, ['status' => 'cancelled'])
            ->assertStatus(422);
        $this->patchJson('/api/v1/account/seller/orders/'.$so->id, ['status' => 'shipped'])
            ->assertStatus(422);
        $this->assertSame('shipped', $so->fresh()->status);
    }

    public function test_account_orders_pagination_cannot_request_unbounded_page_size(): void
    {
        $this->seed();
        $customer = $this->registerCustomer('pages@example.com');
        $this->actingAs($customer, 'customer');

        $response = $this->getJson('/api/v1/account/orders?per_page=1000000');
        $response->assertOk();
        $this->assertLessThanOrEqual(50, (int) $response->json('orders.per_page'));
    }
}
