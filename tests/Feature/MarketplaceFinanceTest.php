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
use App\Domain\Marketplace\Services\CommissionService;
use App\Domain\Marketplace\Services\SellerLedgerService;
use App\Domain\Marketplace\Services\SellerService;
use App\Domain\Order\Models\Order;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Models\Admin;

/**
 * Phase 07 — commission snapshots, earnings, and immutable seller ledger.
 */
class MarketplaceFinanceTest extends MarketplaceTestCase
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
    protected function sellerWithProduct(string $email, string $slug, string $name, float $price = 100, float $commission = 10): array
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
            ->post('/admin/multivendor/seller-requests/'.$seller->id.'/review', [
                'action' => 'approve',
                'commission_rate' => $commission,
            ])
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

    public function test_commission_service_rounding_and_bounds(): void
    {
        $svc = app(CommissionService::class);
        $this->assertSame(0.0, $svc->clampRate(-5));
        $this->assertSame(100.0, $svc->clampRate(150));

        $zero = $svc->calculate(100, 0);
        $this->assertSame(0.0, $zero['commission_amount']);
        $this->assertSame(100.0, $zero['seller_net']);

        $full = $svc->calculate(100, 100);
        $this->assertSame(100.0, $full['commission_amount']);
        $this->assertSame(0.0, $full['seller_net']);

        $odd = $svc->calculate(10.01, 10);
        $this->assertSame(1.0, $odd['commission_amount']);
        $this->assertSame(9.01, $odd['seller_net']);
    }

    public function test_checkout_snapshots_commission_and_creates_pending_earnings_without_ledger(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true, 'default_commission' => 10]);
        [$seller, , $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', 100, 10);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();

        $sellerOrder = SellerOrder::query()->where('seller_id', $seller->id)->firstOrFail();
        $this->assertEquals(10.0, (float) $sellerOrder->commission_rate);
        $this->assertSame('seller', $sellerOrder->commission_source);
        $this->assertEquals(100.0, (float) $sellerOrder->subtotal);
        $this->assertEquals(10.0, (float) $sellerOrder->commission_total);
        $this->assertEquals(90.0, (float) $sellerOrder->earning_total);

        $earning = SellerEarning::query()->where('seller_order_id', $sellerOrder->id)->firstOrFail();
        $this->assertSame(SellerEarning::STATUS_PENDING, $earning->status);
        $this->assertEquals(10.0, (float) $earning->commission_rate);
        $this->assertSame(0, SellerLedgerEntry::query()->count());
    }

    public function test_payment_paid_credits_ledger_idempotently(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, , $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', 50, 10);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();

        $order = Order::query()->latest('id')->firstOrFail();
        $order->update(['payment_status' => 'paid']);
        $order->update(['payment_status' => 'paid']); // duplicate observer path

        $this->assertSame(1, SellerLedgerEntry::query()->count());
        $entry = SellerLedgerEntry::query()->firstOrFail();
        $this->assertSame(SellerLedgerEntry::TYPE_ORDER_EARNING, $entry->entry_type);
        $this->assertSame(SellerLedgerEntry::DIRECTION_CREDIT, $entry->direction);
        $this->assertEquals(45.0, (float) $entry->amount);
        $this->assertEquals(45.0, app(SellerLedgerService::class)->balance($seller->fresh()));

        app(SellerService::class)->markOrderPaid($order->fresh());
        $this->assertSame(1, SellerLedgerEntry::query()->count());
    }

    public function test_historical_commission_survives_rate_change(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, , $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', 100, 10);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();

        $sellerOrder = SellerOrder::query()->firstOrFail();
        $this->assertEquals(10.0, (float) $sellerOrder->commission_rate);
        $this->assertEquals(10.0, (float) $sellerOrder->commission_total);

        app(SellerService::class)->setCommission($seller, 15, 'Raised');
        $this->assertEquals(15.0, (float) $seller->fresh()->commission_rate);

        app(SellerService::class)->captureOrder(Order::query()->firstOrFail());

        $sellerOrder->refresh();
        $this->assertEquals(10.0, (float) $sellerOrder->commission_rate);
        $this->assertEquals(10.0, (float) $sellerOrder->commission_total);
        $this->assertEquals(90.0, (float) $sellerOrder->earning_total);
        $this->assertSame(1, SellerEarning::query()->count());
    }

    public function test_mixed_sellers_have_independent_financials(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$sellerA, , $productA] = $this->sellerWithProduct('a@example.com', 'shop-a', 'A', 100, 10);
        [$sellerB, , $productB] = $this->sellerWithProduct('b@example.com', 'shop-b', 'B', 200, 20);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $productA->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/cart', ['product_id' => $productB->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();

        $orderA = SellerOrder::query()->where('seller_id', $sellerA->id)->firstOrFail();
        $orderB = SellerOrder::query()->where('seller_id', $sellerB->id)->firstOrFail();
        $this->assertEquals(10.0, (float) $orderA->commission_total);
        $this->assertEquals(90.0, (float) $orderA->earning_total);
        $this->assertEquals(40.0, (float) $orderB->commission_total);
        $this->assertEquals(160.0, (float) $orderB->earning_total);

        Order::query()->latest('id')->firstOrFail()->update(['payment_status' => 'paid']);
        $this->assertEquals(90.0, app(SellerLedgerService::class)->balance($sellerA->fresh()));
        $this->assertEquals(160.0, app(SellerLedgerService::class)->balance($sellerB->fresh()));
    }

    public function test_refund_posts_compensating_ledger_debit(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [, , $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', 80, 10);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();

        $order = Order::query()->latest('id')->firstOrFail();
        $order->update(['payment_status' => 'paid']);
        $this->assertSame(1, SellerLedgerEntry::query()->where('direction', 'credit')->count());

        $order->update(['status' => 'refunded', 'payment_status' => 'refunded']);
        $this->assertSame(1, SellerLedgerEntry::query()->where('direction', 'debit')->count());
        $this->assertSame(SellerEarning::STATUS_REFUNDED, SellerEarning::query()->value('status'));

        $seller = Seller::query()->firstOrFail();
        $this->assertEquals(0.0, app(SellerLedgerService::class)->balance($seller));
    }

    public function test_ledger_entries_are_immutable(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [, , $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', 40, 10);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();
        Order::query()->latest('id')->firstOrFail()->update(['payment_status' => 'paid']);

        $entry = SellerLedgerEntry::query()->firstOrFail();
        $this->expectException(\RuntimeException::class);
        $entry->update(['amount' => 1]);
    }

    public function test_seller_cannot_access_other_sellers_ledger_or_financials(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$sellerA, $ownerA, $productA] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug A', 50, 10);
        [, $ownerB] = $this->sellerWithProduct('b@example.com', 'shop-b', 'Mug B', 50, 10);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $productA->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();
        Order::query()->latest('id')->firstOrFail()->update(['payment_status' => 'paid']);

        $sellerOrderA = SellerOrder::query()->where('seller_id', $sellerA->id)->firstOrFail();

        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => $ownerB->email, 'password' => 'password123'])->assertOk();
        $this->getJson('/api/v1/account/seller/ledger')->assertOk()
            ->assertJsonPath('balance', 0);
        $this->assertSame(0, count($this->getJson('/api/v1/account/seller/ledger')->json('entries.data')));
        $this->getJson('/api/v1/account/seller/orders/'.$sellerOrderA->id.'/financials')->assertNotFound();
        $this->getJson('/api/v1/account/seller/earnings')->assertOk();

        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => $ownerA->email, 'password' => 'password123'])->assertOk();
        $this->getJson('/api/v1/account/seller/orders/'.$sellerOrderA->id.'/financials')->assertOk()
            ->assertJsonPath('order.commission_rate', 10);
        $this->getJson('/api/v1/account/seller/ledger')->assertOk()
            ->assertJsonPath('balance', 45);
    }

    public function test_customer_cannot_access_seller_financial_apis(): void
    {
        $this->seed();
        $this->registerCustomer('plain@example.com');
        $this->getJson('/api/v1/account/seller/ledger')->assertForbidden();
        $this->getJson('/api/v1/account/seller/earnings')->assertForbidden();
    }

    public function test_zero_commission_order(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [, , $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', 25, 0);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();

        $sellerOrder = SellerOrder::query()->firstOrFail();
        $this->assertEquals(0.0, (float) $sellerOrder->commission_total);
        $this->assertEquals(25.0, (float) $sellerOrder->earning_total);
    }
}
