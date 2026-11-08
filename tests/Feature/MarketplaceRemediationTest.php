<?php

namespace Tests\Feature;

use App\Domain\Auth\Models\Role;
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
use App\Domain\Marketplace\Services\SellerService;
use App\Domain\Order\Models\Order;
use App\Domain\Payment\Contracts\PaymentGateway;
use App\Domain\Payment\PaymentCaptureResult;
use App\Domain\Payment\PaymentGatewayRegistry;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Models\Admin;
use Illuminate\Database\QueryException;

/**
 * Remediation coverage for MV-001 / MV-002 / MV-003 and medium audit issues.
 */
class MarketplaceRemediationTest extends MarketplaceTestCase
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

    protected function loginAs(Customer $customer): void
    {
        $this->postJson('/api/v1/auth/logout');
        $this->actingAs($customer, 'customer');
    }

    /** @return array<string, mixed> */
    protected function checkoutPayload(string $email = 'buyer@example.com'): array
    {
        $payment = PaymentMethod::query()->whereNull('seller_id')->where('is_enabled', true)->firstOrFail();
        $shipping = ShippingMethod::query()->where('code', 'flat')->firstOrFail();

        return [
            'email' => $email,
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Pat', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Pat', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ];
    }

    public function test_mv001_cancel_before_recognition_has_no_ledger_debit(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, $owner, $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', 100, 10);
        $stock = (int) $product->stock_qty;

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();

        $so = SellerOrder::query()->where('seller_id', $seller->id)->firstOrFail();
        $this->assertSame(SellerEarning::STATUS_PENDING, SellerEarning::query()->value('status'));
        $this->assertSame(0, SellerLedgerEntry::query()->count());

        $this->loginAs($owner);
        $this->patchJson('/api/v1/account/seller/orders/'.$so->id, ['status' => 'cancelled'])->assertOk();

        $this->assertSame('cancelled', $so->fresh()->status);
        $this->assertSame(SellerEarning::STATUS_REFUNDED, SellerEarning::query()->value('status'));
        $this->assertSame(0, SellerLedgerEntry::query()->count());
        $this->assertSame($stock, (int) $product->fresh()->stock_qty);
    }

    public function test_mv001_cancel_after_recognition_posts_cancel_adjustment(): void
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
        $this->assertEquals(90.0, $ledger->balance($seller->fresh()));

        $so = SellerOrder::query()->where('seller_id', $seller->id)->firstOrFail();
        $this->loginAs($owner);
        $this->patchJson('/api/v1/account/seller/orders/'.$so->id, ['status' => 'cancelled'])->assertOk();
        $this->patchJson('/api/v1/account/seller/orders/'.$so->id, ['status' => 'cancelled'])->assertOk(); // duplicate

        $this->assertSame(1, SellerLedgerEntry::query()
            ->where('entry_type', SellerLedgerEntry::TYPE_CANCEL_ADJUSTMENT)
            ->count());
        $this->assertEquals(0.0, $ledger->balance($seller->fresh()));
        $this->assertSame(SellerEarning::STATUS_REFUNDED, SellerEarning::query()->value('status'));
    }

    public function test_mv001_cancel_one_seller_leaves_other_intact(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$sellerA, $ownerA, $productA] = $this->sellerWithProduct('a@example.com', 'shop-a', 'A', 100, 10);
        [$sellerB, , $productB] = $this->sellerWithProduct('b@example.com', 'shop-b', 'B', 200, 10);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $productA->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/cart', ['product_id' => $productB->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();
        Order::query()->latest('id')->firstOrFail()->update(['payment_status' => 'paid']);

        $soA = SellerOrder::query()->where('seller_id', $sellerA->id)->firstOrFail();
        $this->loginAs($ownerA);
        $this->patchJson('/api/v1/account/seller/orders/'.$soA->id, ['status' => 'cancelled'])->assertOk();

        $ledger = app(SellerLedgerService::class);
        $this->assertEquals(0.0, $ledger->balance($sellerA->fresh()));
        $this->assertEquals(180.0, $ledger->balance($sellerB->fresh()));
        $this->assertSame('pending', SellerOrder::query()->where('seller_id', $sellerB->id)->value('status'));
    }

    public function test_mv001_cancel_from_shipped_is_rejected(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, $owner, $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', 40, 10);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();
        Order::query()->latest('id')->firstOrFail()->update(['payment_status' => 'paid']);

        $so = SellerOrder::query()->firstOrFail();
        $this->loginAs($owner);
        $ship = $this->postJson('/api/v1/account/seller/orders/'.$so->id.'/shipments', [
            'tracking_number' => 'T-1',
        ])->assertCreated()->json('shipment.id');
        $this->postJson('/api/v1/account/seller/shipments/'.$ship.'/ship', ['tracking_number' => 'T-1'])->assertOk();

        $before = app(SellerLedgerService::class)->balance($seller->fresh());
        $this->patchJson('/api/v1/account/seller/orders/'.$so->id, ['status' => 'cancelled'])->assertStatus(422);
        $this->assertEquals($before, app(SellerLedgerService::class)->balance($seller->fresh()));
        $this->assertSame('shipped', $so->fresh()->status);
    }

    public function test_mv002_admin_cannot_mark_earning_paid_without_payout(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, , $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', 50, 10);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();
        Order::query()->latest('id')->firstOrFail()->update(['payment_status' => 'paid']);

        $earning = SellerEarning::query()->firstOrFail();
        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/multivendor/earnings/'.$earning->id.'/status', ['status' => 'paid'])
            ->assertSessionHasErrors();

        $this->assertSame(SellerEarning::STATUS_AVAILABLE, $earning->fresh()->status);
        $this->assertSame(0, SellerLedgerEntry::query()->where('entry_type', SellerLedgerEntry::TYPE_PAYOUT)->count());
    }

    public function test_mv002_payout_pay_is_atomic_with_ledger_and_idempotent(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, $owner, $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', 100, 10);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();
        Order::query()->latest('id')->firstOrFail()->update(['payment_status' => 'paid']);

        $this->loginAs($owner);
        $this->postJson('/api/v1/account/seller/payouts', ['amount' => 40])->assertCreated();
        $payout = SellerWithdrawal::query()->firstOrFail();

        $catalog = app(SellerCatalogService::class);
        $catalog->processWithdrawal($payout, 'pay', Admin::query()->first()->id);
        $catalog->processWithdrawal($payout->fresh(), 'pay', Admin::query()->first()->id);

        $this->assertSame(1, SellerLedgerEntry::query()->where('entry_type', SellerLedgerEntry::TYPE_PAYOUT)->count());
        $this->assertEquals(50.0, app(SellerLedgerService::class)->balance($seller->fresh()));
        $this->assertSame(SellerWithdrawal::STATUS_PAID, $payout->fresh()->status);
    }

    public function test_mv002_payout_does_not_finalize_when_ledger_debit_fails(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, $owner, $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', 100, 10);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();
        Order::query()->latest('id')->firstOrFail()->update(['payment_status' => 'paid']);

        $this->loginAs($owner);
        $this->postJson('/api/v1/account/seller/payouts', ['amount' => 20])->assertCreated();
        $payout = SellerWithdrawal::query()->firstOrFail();

        $mock = \Mockery::mock(SellerLedgerService::class)->makePartial();
        $mock->shouldReceive('debitPayout')->once()->andThrow(new \RuntimeException('ledger down'));
        $this->app->instance(SellerLedgerService::class, $mock);

        try {
            app(SellerCatalogService::class)->processWithdrawal($payout, 'pay', Admin::query()->first()->id);
            $this->fail('Expected ledger failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('ledger down', $e->getMessage());
        }

        $this->assertSame(SellerWithdrawal::STATUS_PENDING, $payout->fresh()->status);
        $this->assertSame(SellerEarning::STATUS_AVAILABLE, SellerEarning::query()->value('status'));
    }

    public function test_mv003_seller_delete_is_restricted_while_ledger_exists(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, , $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', 80, 10);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();
        Order::query()->latest('id')->firstOrFail()->update(['payment_status' => 'paid']);

        $ledgerCount = SellerLedgerEntry::query()->count();
        $this->assertGreaterThan(0, $ledgerCount);

        $this->expectException(QueryException::class);
        $seller->delete();
    }

    public function test_mv003_seller_block_preserves_financial_history(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, , $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', 80, 10);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();
        Order::query()->latest('id')->firstOrFail()->update(['payment_status' => 'paid']);

        app(SellerService::class)->review($seller, 'block', 'suspended', Admin::query()->first()->id);

        $this->assertSame(Seller::STATUS_BLOCKED, $seller->fresh()->status);
        $this->assertSame(1, SellerLedgerEntry::query()->where('seller_id', $seller->id)->count());
        $this->assertEquals(72.0, app(SellerLedgerService::class)->balance($seller->fresh()));
    }

    public function test_shipping_allocation_assigns_parent_shipping_to_seller_slice(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [, , $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', 100, 10);
        ShippingMethod::query()->where('code', 'flat')->update([
            'type' => 'flat',
            'driver' => 'offline',
            'price' => 15,
        ]);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $placed = $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();

        $order = Order::query()->findOrFail($placed->json('order.id'));
        $this->assertEquals(15.0, (float) $order->shipping_total);
        $so = SellerOrder::query()->firstOrFail();
        $this->assertEquals(15.0, (float) $so->shipping_total);
        $this->assertSame((int) $order->shipping_method_id, (int) $so->shipping_method_id);
    }

    public function test_acl_negative_and_marketplace_manager(): void
    {
        $this->seed();

        $catalog = Admin::factory()->create(['email' => 'catalog-only@store.test']);
        $catalog->roles()->sync([Role::query()->where('slug', 'catalog_manager')->value('id')]);
        $this->actingAs($catalog->fresh(['roles.permissions']), 'admin');
        $this->get('/admin/multivendor/seller-requests')->assertForbidden();
        $this->get('/admin/multivendor/earnings')->assertForbidden();
        $this->get('/admin/multivendor/payouts')->assertForbidden();

        $orders = Admin::factory()->create(['email' => 'orders-only@store.test']);
        $orders->roles()->sync([Role::query()->where('slug', 'order_manager')->value('id')]);
        $this->actingAs($orders->fresh(['roles.permissions']), 'admin');
        $this->get('/admin/multivendor/orders')->assertForbidden();

        $market = Admin::factory()->create(['email' => 'market@store.test']);
        $market->roles()->sync([Role::query()->where('slug', 'marketplace_manager')->value('id')]);
        $this->actingAs($market->fresh(['roles.permissions']), 'admin');
        $this->get('/admin/multivendor/seller-requests')->assertOk();
        $this->get('/admin/multivendor/earnings')->assertOk();
        $this->get('/admin/multivendor/ledger')->assertOk();

        $this->actingAs(Admin::query()->first(), 'admin');
        $this->get('/admin/multivendor/settings')->assertOk();
    }

    public function test_external_gateway_captures_outside_transaction_and_failure_cancels(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [, , $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', 30, 10);
        $stock = (int) $product->stock_qty;

        $fake = new class implements PaymentGateway
        {
            public function driver(): string
            {
                return 'fake_external';
            }

            public function checkoutLifecycle(): \App\Domain\Payment\CheckoutLifecycle
            {
                return \App\Domain\Payment\CheckoutLifecycle::OrderFirst;
            }

            public function supportsTransactionalCapture(): bool
            {
                return false;
            }

            public function capture(Order $order, array $payload = []): PaymentCaptureResult
            {
                return PaymentCaptureResult::failed('Card declined');
            }
        };
        app(PaymentGatewayRegistry::class)->register($fake);

        $payment = PaymentMethod::query()->whereNull('seller_id')->where('is_enabled', true)->firstOrFail();
        $payment->update(['driver' => 'fake_external']);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertStatus(422);

        $this->assertSame(0, Order::query()->where('status', 'pending')->count());
        $this->assertTrue(
            Order::query()->where('status', 'cancelled')->exists()
            || Order::query()->count() === 0
            || SellerOrder::query()->where('status', 'cancelled')->exists()
        );
        // Stock restored via cancel path after deferred failure.
        $this->assertSame($stock, (int) $product->fresh()->stock_qty);
    }

    public function test_dual_seller_id_stays_synced_through_checkout_chain(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, $owner, $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', 55, 10);

        $listing = SellerProduct::query()->where('product_id', $product->id)->firstOrFail();
        $this->assertSame((int) $seller->id, (int) $product->seller_id);
        $this->assertSame((int) $seller->id, (int) $listing->seller_id);

        $this->loginAs($owner);
        $this->patchJson('/api/v1/account/seller/products/'.$product->id, [
            'name' => 'Mug Updated',
            'price' => 55,
            'stock_qty' => 20,
            'is_enabled' => true,
            'seller_id' => 999999,
        ])->assertOk();

        $product->refresh();
        $listing->refresh();
        $this->assertSame((int) $seller->id, (int) $product->seller_id);
        $this->assertSame((int) $seller->id, (int) $listing->seller_id);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $cart = $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $sellerIds = collect($cart->json('sellers'))->pluck('seller_id')->filter()->map(fn ($id) => (int) $id)->values()->all();
        $this->assertContains((int) $seller->id, $sellerIds);
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();
        Order::query()->latest('id')->firstOrFail()->update(['payment_status' => 'paid']);

        $so = SellerOrder::query()->firstOrFail();
        $this->assertSame((int) $seller->id, (int) $so->seller_id);
        $this->assertSame((int) $seller->id, (int) SellerEarning::query()->value('seller_id'));
        $this->assertSame((int) $seller->id, (int) SellerLedgerEntry::query()->value('seller_id'));
    }

    public function test_multivendor_disabled_blocks_seller_product_cart_and_checkout(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [, , $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', 40, 10);

        MarketplaceSettings::setEnabled(false);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertStatus(422);
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertStatus(422);
    }

    public function test_partial_return_then_full_refund_zeroes_withdrawable_balance(): void
    {
        $this->seed();
        MarketplaceSettings::save([
            'auto_approve_products' => true,
            'earning_release_mode' => \App\Domain\Marketplace\Services\SellerEarningAvailabilityService::MODE_ON_PAYMENT,
        ]);
        [$seller, $owner, $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', 100, 10);

        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 2])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();
        Order::query()->latest('id')->firstOrFail()->update(['payment_status' => 'paid']);

        $so = SellerOrder::query()->where('seller_id', $seller->id)->firstOrFail();
        $item = $so->items()->firstOrFail();
        $this->loginAs($owner);
        app(\App\Domain\Marketplace\Services\SellerReturnService::class)->cancelItems($seller, $so, [
            ['seller_order_item_id' => $item->id, 'qty' => 1],
        ]);

        $ledger = app(SellerLedgerService::class);
        $beforeAdminRefund = $ledger->balance($seller->fresh());
        $this->assertGreaterThan(0, $beforeAdminRefund);

        $order = Order::query()->latest('id')->firstOrFail();
        $order->update(['status' => 'refunded', 'payment_status' => 'refunded']);

        $this->assertEquals(0.0, $ledger->balance($seller->fresh()));
        $this->assertEquals(0.0, $ledger->availableBalance($seller->fresh()));
    }

    public function test_partial_payout_consumes_only_paid_portion_of_earning_row(): void
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

        $this->loginAs($owner);
        $this->postJson('/api/v1/account/seller/payouts', ['amount' => 40])->assertCreated();
        $payout = SellerWithdrawal::query()->firstOrFail();
        app(SellerCatalogService::class)->processWithdrawal($payout, 'pay', Admin::query()->first()->id);

        $earning = SellerEarning::query()->firstOrFail();
        $this->assertEquals(40.0, (float) $earning->paid_net);
        $this->assertSame(SellerEarning::STATUS_AVAILABLE, $earning->status);
        $this->assertEquals(50.0, $ledger->availableBalance($seller->fresh()));
        $this->assertEquals(50.0, $ledger->balance($seller->fresh()));
    }
}
