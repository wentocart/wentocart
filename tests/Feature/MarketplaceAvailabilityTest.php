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
use App\Domain\Marketplace\Services\SellerEarningAvailabilityService;
use App\Domain\Marketplace\Services\SellerLedgerService;
use App\Domain\Marketplace\Services\SellerService;
use App\Domain\Order\Models\Order;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Models\Admin;
use Illuminate\Support\Carbon;

/**
 * Phase 11 — pending / available / paid earning balances and release rules.
 */
class MarketplaceAvailabilityTest extends MarketplaceTestCase
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
    protected function sellerWithProduct(string $email, string $slug, string $name, float $price = 100): array
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

    /** @return array{0: SellerOrder, 1: Order} */
    protected function paidSellerOrder(float $price = 100): array
    {
        [$seller, , $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', $price);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();

        $order = Order::query()->latest('id')->firstOrFail();
        $order->update(['payment_status' => 'paid']);
        app(SellerService::class)->markOrderPaid($order->fresh());
        $sellerOrder = SellerOrder::query()->where('seller_id', $seller->id)->firstOrFail();

        return [$sellerOrder->fresh(), $order->fresh()];
    }

    public function test_on_payment_mode_releases_immediately(): void
    {
        $this->seed();
        MarketplaceSettings::save([
            'auto_approve_products' => true,
            'earning_release_mode' => SellerEarningAvailabilityService::MODE_ON_PAYMENT,
        ]);

        [$sellerOrder] = $this->paidSellerOrder(50);

        $this->assertSame(SellerEarning::STATUS_AVAILABLE, SellerEarning::query()->value('status'));
        $this->assertSame(1, SellerLedgerEntry::query()->count());
        $wallet = app(SellerEarningAvailabilityService::class)->walletBalances($sellerOrder->seller);
        $this->assertEquals(0.0, $wallet['pending']);
        $this->assertEquals(45.0, $wallet['available']);
        $this->assertEquals(45.0, $wallet['available_for_payout']);
    }

    public function test_after_return_window_keeps_pending_until_window_passes(): void
    {
        $this->seed();
        MarketplaceSettings::save([
            'auto_approve_products' => true,
            'earning_release_mode' => SellerEarningAvailabilityService::MODE_AFTER_RETURN_WINDOW,
            'return_window_days' => 7,
        ]);

        [$sellerOrder] = $this->paidSellerOrder(100);
        $owner = Customer::query()->where('email', 'a@example.com')->firstOrFail();

        $this->assertSame('paid', $sellerOrder->fresh()->payment_status);
        $this->assertSame(SellerEarningAvailabilityService::MODE_AFTER_RETURN_WINDOW, MarketplaceSettings::earningReleaseMode());
        $this->assertSame(SellerEarning::STATUS_PENDING, SellerEarning::query()->value('status'));
        $this->assertSame(0, SellerLedgerEntry::query()->count());

        $this->loginAs($owner);
        $shipmentId = $this->postJson('/api/v1/account/seller/orders/'.$sellerOrder->id.'/shipments', [
            'tracking_number' => 'TRK-1',
        ])->assertCreated()->json('shipment.id');
        $this->postJson('/api/v1/account/seller/shipments/'.$shipmentId.'/ship', ['tracking_number' => 'TRK-1'])->assertOk();
        $this->postJson('/api/v1/account/seller/shipments/'.$shipmentId.'/deliver')->assertOk();

        $sellerOrder->refresh();
        $this->assertSame('completed', $sellerOrder->status);
        $this->assertSame('paid', $sellerOrder->payment_status);

        $earning = SellerEarning::query()->firstOrFail();
        $this->assertSame(SellerEarning::STATUS_PENDING, $earning->status);
        $this->assertNotNull($earning->available_at);
        $this->assertSame(0, SellerLedgerEntry::query()->count());

        Carbon::setTestNow($earning->available_at->copy()->addMinute());
        $released = app(SellerEarningAvailabilityService::class)->releaseDueEarnings();
        $this->assertSame(1, $released);
        $this->assertSame(SellerEarning::STATUS_AVAILABLE, $earning->fresh()->status);
        $this->assertSame(1, SellerLedgerEntry::query()->count());
        $this->assertEquals(90.0, app(SellerLedgerService::class)->balance($sellerOrder->seller));

        app(SellerEarningAvailabilityService::class)->releaseDueEarnings();
        $this->assertSame(1, SellerLedgerEntry::query()->count());

        Carbon::setTestNow();
    }

    public function test_on_delivery_releases_when_marked_delivered(): void
    {
        $this->seed();
        MarketplaceSettings::save([
            'auto_approve_products' => true,
            'earning_release_mode' => SellerEarningAvailabilityService::MODE_ON_DELIVERY,
        ]);

        [$sellerOrder] = $this->paidSellerOrder(80);
        $owner = Customer::query()->where('email', 'a@example.com')->firstOrFail();

        $this->loginAs($owner);
        $shipmentId = $this->postJson('/api/v1/account/seller/orders/'.$sellerOrder->id.'/shipments', [
            'tracking_number' => 'TRK-2',
        ])->assertCreated()->json('shipment.id');
        $this->postJson('/api/v1/account/seller/shipments/'.$shipmentId.'/ship', ['tracking_number' => 'TRK-2'])->assertOk();
        $this->postJson('/api/v1/account/seller/shipments/'.$shipmentId.'/deliver')->assertOk();

        $this->assertSame(SellerEarning::STATUS_AVAILABLE, SellerEarning::query()->value('status'));
        $this->assertSame(1, SellerLedgerEntry::query()->count());
    }

    public function test_cancel_before_release_never_credits_ledger(): void
    {
        $this->seed();
        MarketplaceSettings::save([
            'auto_approve_products' => true,
            'earning_release_mode' => SellerEarningAvailabilityService::MODE_AFTER_RETURN_WINDOW,
        ]);

        [$sellerOrder] = $this->paidSellerOrder(60);
        $owner = Customer::query()->where('email', 'a@example.com')->firstOrFail();

        $this->loginAs($owner);
        $this->patchJson('/api/v1/account/seller/orders/'.$sellerOrder->id, ['status' => 'cancelled'])->assertOk();

        $this->assertSame(SellerEarning::STATUS_REFUNDED, SellerEarning::query()->value('status'));
        $this->assertSame(0, SellerLedgerEntry::query()->count());
        app(SellerEarningAvailabilityService::class)->releaseDueEarnings();
        $this->assertSame(0, SellerLedgerEntry::query()->count());
    }

    public function test_refund_after_release_posts_compensating_debit(): void
    {
        $this->seed();
        MarketplaceSettings::save([
            'auto_approve_products' => true,
            'earning_release_mode' => SellerEarningAvailabilityService::MODE_ON_PAYMENT,
        ]);

        [, $order] = $this->paidSellerOrder(50);
        $this->assertSame(1, SellerLedgerEntry::query()->where('direction', 'credit')->count());

        $order->update(['status' => 'refunded', 'payment_status' => 'refunded']);

        $this->assertSame(SellerEarning::STATUS_REFUNDED, SellerEarning::query()->value('status'));
        $this->assertSame(1, SellerLedgerEntry::query()->where('direction', 'debit')->count());
        $this->assertEquals(0.0, app(SellerLedgerService::class)->balance(Seller::query()->first()));
    }

    public function test_refund_before_release_never_credits_or_debits(): void
    {
        $this->seed();
        MarketplaceSettings::save([
            'auto_approve_products' => true,
            'earning_release_mode' => SellerEarningAvailabilityService::MODE_AFTER_RETURN_WINDOW,
        ]);

        [, $order] = $this->paidSellerOrder(70);
        $order->update(['status' => 'refunded', 'payment_status' => 'refunded']);

        $this->assertSame(SellerEarning::STATUS_REFUNDED, SellerEarning::query()->value('status'));
        $this->assertSame(0, SellerLedgerEntry::query()->count());
    }

    public function test_seller_wallet_api_exposes_pending_available_paid(): void
    {
        $this->seed();
        MarketplaceSettings::save([
            'auto_approve_products' => true,
            'earning_release_mode' => SellerEarningAvailabilityService::MODE_AFTER_RETURN_WINDOW,
        ]);

        $this->paidSellerOrder(100);
        $ownerA = Customer::query()->where('email', 'a@example.com')->firstOrFail();

        $this->loginAs($ownerA);
        $this->getJson('/api/v1/account/seller/earnings')->assertOk()
            ->assertJsonPath('totals.pending', 90)
            ->assertJsonPath('totals.available', 0)
            ->assertJsonPath('totals.paid', 0);

        $this->getJson('/api/v1/account/seller/ledger')->assertOk()
            ->assertJsonPath('pending', 90)
            ->assertJsonPath('available', 0)
            ->assertJsonPath('paid', 0)
            ->assertJsonPath('balance', 0);
    }

    public function test_seller_cannot_read_other_sellers_wallet(): void
    {
        $this->seed();
        MarketplaceSettings::save([
            'auto_approve_products' => true,
            'earning_release_mode' => SellerEarningAvailabilityService::MODE_ON_PAYMENT,
        ]);

        $this->paidSellerOrder(50);
        [, $ownerB] = $this->sellerWithProduct('b@example.com', 'shop-b', 'Other');

        $this->loginAs($ownerB);
        $this->getJson('/api/v1/account/seller/earnings')->assertOk()
            ->assertJsonPath('totals.available', 0)
            ->assertJsonPath('totals.pending', 0);
    }
}
