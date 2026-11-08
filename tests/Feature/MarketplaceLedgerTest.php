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
 * Phase 10 — seller earnings ledger: credits, refunds, payout consumption, reconciliation.
 */
class MarketplaceLedgerTest extends MarketplaceTestCase
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

    public function test_order_payment_credits_ledger_with_commission_meta(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, , $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', 100, 10);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();

        $order = Order::query()->latest('id')->firstOrFail();
        $this->assertSame(0, SellerLedgerEntry::query()->count());

        $order->update(['payment_status' => 'paid']);
        $entry = SellerLedgerEntry::query()->firstOrFail();
        $this->assertSame(SellerLedgerEntry::TYPE_ORDER_EARNING, $entry->entry_type);
        $this->assertSame(SellerLedgerEntry::DIRECTION_CREDIT, $entry->direction);
        $this->assertEquals(90.0, (float) $entry->amount);
        $this->assertEquals(10.0, (float) ($entry->meta['commission_total'] ?? 0));
        $this->assertStringStartsWith('order_earning:seller_order:', $entry->reference);

        $recon = app(SellerLedgerService::class)->reconcile($seller->fresh());
        $this->assertEquals(90.0, $recon['balance']);
        $this->assertEquals(90.0, $recon['available']);
        $this->assertEquals(90.0, $recon['credits']);
        $this->assertEquals(0.0, $recon['debits']);
    }

    public function test_duplicate_payment_events_do_not_duplicate_ledger_credits(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [, , $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', 50, 10);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();

        $order = Order::query()->latest('id')->firstOrFail();
        $order->update(['payment_status' => 'paid']);
        $order->update(['payment_status' => 'paid']);
        app(\App\Domain\Marketplace\Services\SellerService::class)->markOrderPaid($order->fresh());

        $this->assertSame(1, SellerLedgerEntry::query()->count());
        $this->assertSame(1, SellerEarning::query()->count());
    }

    public function test_refund_posts_debit_and_zeroes_balance(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, , $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', 80, 10);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();

        $order = Order::query()->latest('id')->firstOrFail();
        $order->update(['payment_status' => 'paid']);
        $order->update(['status' => 'refunded', 'payment_status' => 'refunded']);

        $recon = app(SellerLedgerService::class)->reconcile($seller->fresh());
        $this->assertEquals(0.0, $recon['balance']);
        $this->assertEquals(72.0, $recon['credits']);
        $this->assertEquals(72.0, $recon['debits']);
        $this->assertSame(1, SellerLedgerEntry::query()
            ->where('entry_type', SellerLedgerEntry::TYPE_REFUND_ADJUSTMENT)
            ->count());
    }

    public function test_payout_consumes_ledger_balance_idempotently(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, $owner, $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', 100, 10);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();
        Order::query()->latest('id')->firstOrFail()->update(['payment_status' => 'paid']);

        $ledger = app(SellerLedgerService::class);
        $this->assertEquals(90.0, $ledger->availableBalance($seller->fresh()));

        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'password123'])->assertOk();
        $this->postJson('/api/v1/account/seller/payouts', ['amount' => 40])->assertCreated();

        $this->assertEquals(50.0, $ledger->availableBalance($seller->fresh()));
        $this->assertEquals(90.0, $ledger->balance($seller->fresh()));

        $payout = SellerWithdrawal::query()->firstOrFail();
        $catalog = app(SellerCatalogService::class);
        $catalog->processWithdrawal($payout, 'approve', Admin::query()->first()->id);
        $catalog->processWithdrawal($payout->fresh(), 'pay', Admin::query()->first()->id);
        $catalog->processWithdrawal($payout->fresh(), 'pay', Admin::query()->first()->id); // idempotent

        $this->assertSame(1, SellerLedgerEntry::query()
            ->where('entry_type', SellerLedgerEntry::TYPE_PAYOUT)
            ->count());
        $this->assertEquals(50.0, $ledger->balance($seller->fresh()));
        $this->assertEquals(50.0, $ledger->availableBalance($seller->fresh()));

        $recon = $ledger->reconcile($seller->fresh());
        $this->assertEquals(90.0, $recon['credits']);
        $this->assertEquals(40.0, $recon['debits']);
        $this->assertEquals(0.0, $recon['held_payouts']);
    }

    public function test_rejected_payout_releases_hold_without_ledger_debit(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, $owner, $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', 100, 10);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();
        Order::query()->latest('id')->firstOrFail()->update(['payment_status' => 'paid']);

        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'password123'])->assertOk();
        $this->postJson('/api/v1/account/seller/payouts', ['amount' => 25])->assertCreated();

        $ledger = app(SellerLedgerService::class);
        $this->assertEquals(65.0, $ledger->availableBalance($seller->fresh()));

        $payout = SellerWithdrawal::query()->firstOrFail();
        app(SellerCatalogService::class)->processWithdrawal($payout, 'reject', Admin::query()->first()->id);

        $this->assertEquals(90.0, $ledger->availableBalance($seller->fresh()));
        $this->assertSame(0, SellerLedgerEntry::query()
            ->where('entry_type', SellerLedgerEntry::TYPE_PAYOUT)
            ->count());
    }

    public function test_ledger_entries_are_immutable_and_seller_scoped(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$sellerA, $ownerA, $productA] = $this->sellerWithProduct('a@example.com', 'shop-a', 'A', 100, 10);
        [, $ownerB] = $this->sellerWithProduct('b@example.com', 'shop-b', 'B', 100, 10);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $productA->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();
        Order::query()->latest('id')->firstOrFail()->update(['payment_status' => 'paid']);

        $entry = SellerLedgerEntry::query()->firstOrFail();
        $this->expectException(\RuntimeException::class);
        $entry->update(['amount' => 1]);
    }

    public function test_seller_cannot_view_other_seller_ledger(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$sellerA, $ownerA, $productA] = $this->sellerWithProduct('a@example.com', 'shop-a', 'A', 100, 10);
        [, $ownerB] = $this->sellerWithProduct('b@example.com', 'shop-b', 'B', 100, 10);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $productA->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();
        Order::query()->latest('id')->firstOrFail()->update(['payment_status' => 'paid']);

        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => $ownerB->email, 'password' => 'password123'])->assertOk();
        $this->getJson('/api/v1/account/seller/ledger')
            ->assertOk()
            ->assertJsonPath('balance', 0)
            ->assertJsonPath('available', 0);
        $this->assertSame(0, count($this->getJson('/api/v1/account/seller/ledger')->json('entries.data')));

        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => $ownerA->email, 'password' => 'password123'])->assertOk();
        $this->getJson('/api/v1/account/seller/ledger')
            ->assertOk()
            ->assertJsonPath('balance', 90)
            ->assertJsonPath('available', 90);
    }

    public function test_admin_can_view_marketplace_ledger(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [, , $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', 100, 10);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();
        Order::query()->latest('id')->firstOrFail()->update(['payment_status' => 'paid']);

        $this->actingAs(Admin::query()->first(), 'admin')
            ->get('/admin/multivendor/ledger')
            ->assertOk();
    }

    public function test_reconciliation_after_partial_payout_matches_seller_order_net(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, $owner, $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', 200, 10);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();
        Order::query()->latest('id')->firstOrFail()->update(['payment_status' => 'paid']);

        $sellerOrder = SellerOrder::query()->where('seller_id', $seller->id)->firstOrFail();
        $this->assertEquals(180.0, (float) $sellerOrder->earning_total);

        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'password123'])->assertOk();
        $this->postJson('/api/v1/account/seller/payouts', ['amount' => 50])->assertCreated();

        $payout = SellerWithdrawal::query()->firstOrFail();
        app(SellerCatalogService::class)->processWithdrawal($payout, 'pay', Admin::query()->first()->id);

        $recon = app(SellerLedgerService::class)->reconcile($seller->fresh());
        $this->assertEquals(180.0, $recon['credits']);
        $this->assertEquals(50.0, $recon['debits']);
        $this->assertEquals(130.0, $recon['balance']);
        $this->assertEquals(
            round((float) $sellerOrder->earning_total - 50, 2),
            $recon['balance']
        );
    }
}
