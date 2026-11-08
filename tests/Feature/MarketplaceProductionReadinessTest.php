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
use App\Domain\Marketplace\Models\SellerWithdrawal;
use App\Domain\Marketplace\Services\SellerCatalogService;
use App\Domain\Marketplace\Services\SellerLedgerService;
use App\Domain\Order\Models\Order;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Models\Admin;

/**
 * Phase 22 — final multi-seller production readiness scenario (master doc §39).
 */
class MarketplaceProductionReadinessTest extends MarketplaceTestCase
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
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertOk();

        return Customer::query()->where('email', $email)->firstOrFail();
    }

    /** @return array{0: Seller, 1: Customer, 2: Product} */
    protected function approvedSellerWithProduct(string $email, string $slug, float $price, float $commission = 10): array
    {
        $owner = $this->registerCustomer($email);
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload([
            'email' => str_replace('@', '.shop@', $email),
            'shop_name' => $slug.' Shop',
            'shop_slug' => $slug,
        ]))->assertCreated();

        $seller = Seller::query()->where('customer_id', $owner->id)->firstOrFail();
        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/multivendor/seller-requests/'.$seller->id.'/review', [
                'action' => 'approve',
                'commission_rate' => $commission,
            ])
            ->assertRedirect();
        \Illuminate\Support\Facades\Auth::guard('admin')->logout();

        $this->actingAs($owner, 'customer');
        $this->postJson('/api/v1/account/seller/products', [
            'name' => $slug.' Item',
            'sku' => 'sku-'.substr(md5($slug), 0, 8),
            'price' => $price,
            'stock_qty' => 20,
            'is_enabled' => true,
        ])->assertCreated();

        $product = Product::query()->where('seller_id', $seller->id)->latest('id')->firstOrFail();
        SellerProduct::query()->where('product_id', $product->id)->update([
            'approval_status' => SellerProduct::STATUS_APPROVED,
            'is_enabled' => true,
        ]);
        $product->update([
            'is_enabled' => true,
            'available_for_order' => true,
            'manage_stock' => true,
            'stock_qty' => 20,
        ]);

        return [$seller->fresh(), $owner, $product->fresh()];
    }

    /** @return array<string, mixed> */
    protected function checkoutPayload(string $email): array
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

    public function test_final_multi_seller_production_scenario(): void
    {
        $this->seed();
        MarketplaceSettings::save([
            'auto_approve_products' => true,
            'earning_release_mode' => 'on_payment',
        ]);

        [$sellerA, $ownerA, $productA] = $this->approvedSellerWithProduct('seller-a@example.com', 'shop-a', 100, 10);
        [$sellerB, $ownerB, $productB] = $this->approvedSellerWithProduct('seller-b@example.com', 'shop-b', 50, 10);
        $stockA = (int) $productA->stock_qty;
        $stockB = (int) $productB->stock_qty;

        // Public shops
        $this->postJson('/api/v1/auth/logout');
        $this->getJson('/api/v1/catalog/seller/'.$sellerA->shop_slug)->assertOk();
        $this->getJson('/api/v1/catalog/seller/'.$sellerB->shop_slug)->assertOk();
        $this->getJson('/api/v1/catalog/seller/missing-shop')->assertNotFound();

        // Mixed cart + checkout
        $buyer = $this->registerCustomer('buyer-final@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $productA->id, 'qty' => 1])->assertOk();
        $cart = $this->postJson('/api/v1/cart', ['product_id' => $productB->id, 'qty' => 1])->assertOk();
        $sellerIds = collect($cart->json('sellers'))->pluck('seller_id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains((int) $sellerA->id, $sellerIds);
        $this->assertContains((int) $sellerB->id, $sellerIds);

        $placed = $this->postJson('/api/v1/checkout', $this->checkoutPayload('buyer-final@example.com'))->assertCreated();
        $order = Order::query()->findOrFail($placed->json('order.id'));
        $order->update(['payment_status' => 'paid']);
        app(\App\Domain\Marketplace\Services\SellerEarningAvailabilityService::class)->handleOrderPaid($order->fresh());

        $this->assertSame(1, Order::query()->whereKey($order->id)->count());
        $this->assertSame(2, SellerOrder::query()->where('order_id', $order->id)->count());

        $soA = SellerOrder::query()->where('order_id', $order->id)->where('seller_id', $sellerA->id)->firstOrFail();
        $soB = SellerOrder::query()->where('order_id', $order->id)->where('seller_id', $sellerB->id)->firstOrFail();

        // Totals reconcile: seller merchandise + shipping allocation ≈ parent (within shipping split)
        $sellerSub = round((float) $soA->subtotal + (float) $soB->subtotal, 2);
        $sellerShip = round((float) $soA->shipping_total + (float) $soB->shipping_total, 2);
        $this->assertEqualsWithDelta((float) $order->subtotal, $sellerSub, 0.01);
        $this->assertEqualsWithDelta((float) $order->shipping_total, $sellerShip, 0.01);

        // Commission / earnings
        $earnA = SellerEarning::query()->where('seller_order_id', $soA->id)->get();
        $earnB = SellerEarning::query()->where('seller_order_id', $soB->id)->get();
        $this->assertNotEmpty($earnA);
        $this->assertNotEmpty($earnB);
        $this->assertEqualsWithDelta(90.0, (float) $earnA->sum('net_amount'), 0.01);
        $this->assertEqualsWithDelta(45.0, (float) $earnB->sum('net_amount'), 0.01);
        $this->assertEqualsWithDelta(10.0, (float) $earnA->sum('commission_amount'), 0.01);
        $this->assertEqualsWithDelta(5.0, (float) $earnB->sum('commission_amount'), 0.01);

        $ledger = app(SellerLedgerService::class);
        $this->assertEqualsWithDelta(90.0, $ledger->balance($sellerA->fresh()), 0.01);
        $this->assertEqualsWithDelta(45.0, $ledger->balance($sellerB->fresh()), 0.01);

        // Seller isolation
        $this->loginAs($ownerA);
        $this->getJson('/api/v1/account/seller/orders/'.$soA->id)->assertOk();
        $this->getJson('/api/v1/account/seller/orders/'.$soB->id)->assertNotFound();
        $this->getJson('/api/v1/account/seller/products/'.$productB->id)->assertNotFound();

        $this->loginAs($ownerB);
        $this->getJson('/api/v1/account/seller/orders/'.$soB->id)->assertOk();
        $this->getJson('/api/v1/account/seller/orders/'.$soA->id)->assertNotFound();

        // Customer isolation
        $other = $this->registerCustomer('other-buyer@example.com');
        $this->actingAs($other, 'customer');
        $this->getJson('/api/v1/account/orders/'.$order->number)->assertNotFound();
        $this->getJson('/api/v1/account/seller/dashboard')->assertForbidden();

        // Ship both slices
        $this->loginAs($ownerA);
        $shipA = $this->postJson('/api/v1/account/seller/orders/'.$soA->id.'/shipments', [
            'tracking_number' => 'TRACK-A',
        ])->assertCreated()->json('shipment.id');
        $this->postJson('/api/v1/account/seller/shipments/'.$shipA.'/ship', [
            'tracking_number' => 'TRACK-A',
        ])->assertOk();
        $this->postJson('/api/v1/account/seller/shipments/'.$shipA.'/deliver')->assertOk();

        $this->loginAs($ownerB);
        $shipB = $this->postJson('/api/v1/account/seller/orders/'.$soB->id.'/shipments', [
            'tracking_number' => 'TRACK-B',
        ])->assertCreated()->json('shipment.id');
        $this->postJson('/api/v1/account/seller/shipments/'.$shipB.'/ship', [
            'tracking_number' => 'TRACK-B',
        ])->assertOk();

        // Cross-seller shipment denied
        $this->loginAs($ownerA);
        $this->postJson('/api/v1/account/seller/shipments/'.$shipB.'/deliver')->assertNotFound();

        // Payout A + duplicate pay idempotent
        $this->loginAs($ownerA);
        $this->postJson('/api/v1/account/seller/payouts', ['amount' => 40])->assertCreated();
        $payout = SellerWithdrawal::query()->where('seller_id', $sellerA->id)->firstOrFail();
        $catalog = app(SellerCatalogService::class);
        $adminId = Admin::query()->first()->id;
        $catalog->processWithdrawal($payout, 'pay', $adminId);
        $catalog->processWithdrawal($payout->fresh(), 'pay', $adminId);
        $this->assertSame(1, SellerLedgerEntry::query()
            ->where('seller_id', $sellerA->id)
            ->where('entry_type', SellerLedgerEntry::TYPE_PAYOUT)
            ->count());
        $this->assertSame(SellerWithdrawal::STATUS_PAID, $payout->fresh()->status);
        $this->assertEqualsWithDelta(50.0, $ledger->balance($sellerA->fresh()), 0.01);

        // Partial return on B + commission reverse
        $this->loginAs($buyer);
        $itemB = $soB->items()->firstOrFail();
        $this->postJson('/api/v1/account/orders/'.$order->number.'/returns', [
            'seller_order_id' => $soB->id,
            'reason' => 'damaged',
            'items' => [
                ['seller_order_item_id' => $itemB->id, 'qty' => 1],
            ],
        ])->assertCreated();

        $returnId = \App\Domain\Marketplace\Models\SellerReturn::query()
            ->where('seller_order_id', $soB->id)
            ->value('id');
        $this->loginAs($ownerB);
        $this->postJson('/api/v1/account/seller/returns/'.$returnId.'/approve')->assertOk();
        $this->postJson('/api/v1/account/seller/returns/'.$returnId.'/receive')->assertOk();
        $this->postJson('/api/v1/account/seller/returns/'.$returnId.'/refund')->assertOk();

        $this->assertSame(
            SellerEarning::STATUS_REFUNDED,
            SellerEarning::query()->where('seller_order_id', $soB->id)->value('status')
        );
        $this->assertEqualsWithDelta(0.0, $ledger->balance($sellerB->fresh()), 0.01);
        $this->assertSame($stockB, (int) $productB->fresh()->stock_qty);

        // Seller A stock reduced by sale; B restored by refund
        $this->assertSame($stockA - 1, (int) $productA->fresh()->stock_qty);

        // Seller financial history cannot be deleted while ledger exists
        $this->expectException(\Illuminate\Database\QueryException::class);
        $sellerA->delete();
    }

    public function test_earnings_release_command_is_registered(): void
    {
        $this->artisan('wento:marketplace-release-earnings')
            ->assertSuccessful();
    }
}
