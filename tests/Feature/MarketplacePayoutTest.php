<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerEarning;
use App\Domain\Marketplace\Models\SellerLedgerEntry;
use App\Domain\Marketplace\Models\SellerProduct;
use App\Domain\Marketplace\Models\SellerWithdrawal;
use App\Domain\Marketplace\Services\SellerLedgerService;
use App\Domain\Marketplace\Services\SellerPayoutService;
use App\Domain\Order\Models\Order;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Models\Admin;
use Illuminate\Validation\ValidationException;

/**
 * Phase 12 — seller payout lifecycle.
 */
class MarketplacePayoutTest extends MarketplaceTestCase
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

    protected function loginAs(Customer $customer): void
    {
        $this->postJson('/api/v1/auth/logout');
        $this->actingAs($customer, 'customer');
    }

    /** @return array{0: Seller, 1: Customer, 2: float} */
    protected function fundedSeller(float $price = 100, float $commission = 10): array
    {
        [$seller, $owner, $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', $price, $commission);
        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();
        Order::query()->latest('id')->firstOrFail()->update(['payment_status' => 'paid']);
        $net = round($price * (1 - $commission / 100), 2);

        return [$seller->fresh(), $owner, $net];
    }

    public function test_full_payout_lifecycle_happy_path(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, $owner, $net] = $this->fundedSeller();
        $payouts = app(SellerPayoutService::class);
        $adminId = Admin::query()->first()->id;

        $this->loginAs($owner);
        $this->postJson('/api/v1/account/seller/payouts', ['amount' => 40])->assertCreated()
            ->assertJsonPath('payout.status', SellerWithdrawal::STATUS_REQUESTED);

        $payout = SellerWithdrawal::query()->firstOrFail();
        $this->assertEquals(round($net - 40, 2), app(SellerLedgerService::class)->availableBalance($seller->fresh()));

        $payouts->process($payout, 'review', $adminId);
        $this->assertSame(SellerWithdrawal::STATUS_UNDER_REVIEW, $payout->fresh()->status);

        $payouts->process($payout->fresh(), 'process', $adminId);
        $this->assertSame(SellerWithdrawal::STATUS_PROCESSING, $payout->fresh()->status);
        $this->assertEquals(round($net - 40, 2), app(SellerLedgerService::class)->availableBalance($seller->fresh()));

        $payouts->process($payout->fresh(), 'pay', $adminId);
        $this->assertSame(SellerWithdrawal::STATUS_PAID, $payout->fresh()->status);

        $entry = SellerLedgerEntry::query()->where('entry_type', SellerLedgerEntry::TYPE_PAYOUT)->firstOrFail();
        $this->assertSame((int) $payout->id, (int) $entry->seller_withdrawal_id);
        $this->assertSame('payout:withdrawal:'.$payout->id, $entry->reference);
        $this->assertIsArray($entry->meta['earning_ids'] ?? null);
        $this->assertEquals(round($net - 40, 2), app(SellerLedgerService::class)->balance($seller->fresh()));
    }

    public function test_cannot_request_more_than_available(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [, $owner] = $this->fundedSeller();

        $this->loginAs($owner);
        $this->postJson('/api/v1/account/seller/payouts', ['amount' => 9999])
            ->assertStatus(422);
    }

    public function test_concurrent_requests_cannot_double_spend(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, $owner, $net] = $this->fundedSeller();
        $payouts = app(SellerPayoutService::class);

        $this->loginAs($owner);
        $first = $payouts->request($seller, ['amount' => round($net - 10, 2)]);
        $this->assertSame(SellerWithdrawal::STATUS_REQUESTED, $first->status);

        try {
            $payouts->request($seller->fresh(), ['amount' => 20]);
            $this->fail('Second payout should have been rejected');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }

        $this->assertSame(1, SellerWithdrawal::query()->count());
        $this->assertEquals(10.0, app(SellerLedgerService::class)->availableBalance($seller->fresh()));
    }

    public function test_failed_payout_restores_available_balance(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, $owner, $net] = $this->fundedSeller();
        $payouts = app(SellerPayoutService::class);
        $adminId = Admin::query()->first()->id;

        $this->loginAs($owner);
        $payout = $payouts->request($seller, ['amount' => 30]);
        $payouts->process($payout, 'review', $adminId);
        $payouts->process($payout->fresh(), 'process', $adminId);
        $this->assertEquals(round($net - 30, 2), app(SellerLedgerService::class)->availableBalance($seller->fresh()));

        $payouts->process($payout->fresh(), 'fail', $adminId);
        $this->assertSame(SellerWithdrawal::STATUS_FAILED, $payout->fresh()->status);
        $this->assertEquals($net, app(SellerLedgerService::class)->availableBalance($seller->fresh()));
        $this->assertSame(0, SellerLedgerEntry::query()->where('entry_type', SellerLedgerEntry::TYPE_PAYOUT)->count());

        // Retry re-holds when balance allows.
        $payouts->process($payout->fresh(), 'retry', $adminId);
        $this->assertSame(SellerWithdrawal::STATUS_UNDER_REVIEW, $payout->fresh()->status);
        $this->assertEquals(round($net - 30, 2), app(SellerLedgerService::class)->availableBalance($seller->fresh()));
    }

    public function test_invalid_transitions_are_rejected(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, $owner] = $this->fundedSeller();
        $payouts = app(SellerPayoutService::class);
        $adminId = Admin::query()->first()->id;

        $this->loginAs($owner);
        $payout = $payouts->request($seller, ['amount' => 10]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $payouts->process($payout, 'fail', $adminId);
    }

    public function test_reject_releases_hold_without_ledger_debit(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, $owner, $net] = $this->fundedSeller();
        $payouts = app(SellerPayoutService::class);

        $this->loginAs($owner);
        $payout = $payouts->request($seller, ['amount' => 25]);
        $payouts->process($payout, 'reject', Admin::query()->first()->id);

        $this->assertSame(SellerWithdrawal::STATUS_REJECTED, $payout->fresh()->status);
        $this->assertEquals($net, app(SellerLedgerService::class)->availableBalance($seller->fresh()));
        $this->assertSame(0, SellerLedgerEntry::query()->where('entry_type', SellerLedgerEntry::TYPE_PAYOUT)->count());
    }

    public function test_pay_is_idempotent_and_references_earnings(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, $owner] = $this->fundedSeller();
        $payouts = app(SellerPayoutService::class);
        $adminId = Admin::query()->first()->id;

        $this->loginAs($owner);
        $payout = $payouts->request($seller, ['amount' => 90]);
        $payouts->process($payout, 'pay', $adminId);
        $payouts->process($payout->fresh(), 'pay', $adminId);

        $this->assertSame(1, SellerLedgerEntry::query()->where('entry_type', SellerLedgerEntry::TYPE_PAYOUT)->count());
        $entry = SellerLedgerEntry::query()->where('entry_type', SellerLedgerEntry::TYPE_PAYOUT)->firstOrFail();
        $this->assertNotEmpty($entry->meta['earning_ids'] ?? []);
        $this->assertSame(SellerEarning::STATUS_PAID, SellerEarning::query()->value('status'));
    }

    public function test_seller_b_cannot_see_seller_a_payouts(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [, $ownerA] = $this->fundedSeller();
        [, $ownerB] = $this->sellerWithProduct('b@example.com', 'shop-b', 'Other');

        $this->loginAs($ownerA);
        $this->postJson('/api/v1/account/seller/payouts', ['amount' => 10])->assertCreated();

        $this->loginAs($ownerB);
        $this->getJson('/api/v1/account/seller/payouts')->assertOk()
            ->assertJsonPath('available', 0);
        $this->assertSame(0, count($this->getJson('/api/v1/account/seller/payouts')->json('payouts.data')));
    }

    public function test_admin_process_endpoint_accepts_lifecycle_actions(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, $owner] = $this->fundedSeller();
        $this->loginAs($owner);
        $payout = app(SellerPayoutService::class)->request($seller, ['amount' => 15]);

        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin')
            ->post('/admin/multivendor/payouts/'.$payout->id.'/process', ['action' => 'review'])
            ->assertRedirect();
        $this->assertSame(SellerWithdrawal::STATUS_UNDER_REVIEW, $payout->fresh()->status);

        $this->actingAs($admin, 'admin')
            ->post('/admin/multivendor/payouts/'.$payout->id.'/process', ['action' => 'process'])
            ->assertRedirect();
        $this->assertSame(SellerWithdrawal::STATUS_PROCESSING, $payout->fresh()->status);

        $this->actingAs($admin, 'admin')
            ->post('/admin/multivendor/payouts/'.$payout->id.'/process', ['action' => 'pay'])
            ->assertRedirect();
        $this->assertSame(SellerWithdrawal::STATUS_PAID, $payout->fresh()->status);
    }
}
