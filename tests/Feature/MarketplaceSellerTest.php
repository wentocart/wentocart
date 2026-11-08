<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerCommission;
use App\Domain\Marketplace\Models\SellerEarning;
use App\Domain\Marketplace\Models\SellerLedgerEntry;
use App\Domain\Marketplace\Models\SellerOrder;
use App\Domain\Marketplace\Models\SellerPaymentMethod;
use App\Domain\Marketplace\Models\SellerProduct;
use App\Domain\Marketplace\Models\SellerReview;
use App\Domain\Marketplace\Models\SellerWithdrawal;
use App\Domain\Marketplace\Services\SellerCatalogService;
use App\Domain\Marketplace\Services\SellerLedgerService;
use App\Domain\Marketplace\Services\SellerService;
use App\Domain\Order\Models\Order;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Promo\Models\DiscountRule;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Models\Admin;

class MarketplaceSellerTest extends MarketplaceTestCase
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

    protected function registerSellerCustomer(string $email = 'ada@example.com'): Customer
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada Seller',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertOk();

        return Customer::query()->where('email', $email)->firstOrFail();
    }

    protected function approveSeller(Seller $seller, array $payload = ['action' => 'approve']): void
    {
        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/sellers/'.$seller->id.'/review', $payload)
            ->assertRedirect();
    }

    public function test_customer_can_request_to_become_a_seller(): void
    {
        $this->seed();
        $this->registerSellerCustomer();

        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload())
            ->assertCreated()
            ->assertJsonPath('seller.status', 'pending')
            ->assertJsonPath('seller.shop_slug', 'ada-market');

        $this->assertDatabaseHas('sellers', [
            'shop_slug' => 'ada-market',
            'status' => Seller::STATUS_PENDING,
        ]);

        $this->getJson('/api/v1/account/seller/products')->assertForbidden();
    }

    public function test_admin_can_accept_reject_and_block_seller_requests(): void
    {
        $this->seed();
        $customer = $this->registerSellerCustomer();
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload())->assertCreated();
        $seller = Seller::query()->firstOrFail();

        $this->actingAs(Admin::query()->first(), 'admin');
        $this->get('/admin/sellers')->assertOk()->assertSee('Seller Request');
        $this->get('/admin/multivendor/seller-requests')->assertOk()->assertSee('Seller Request');
        $this->get('/admin/sellers/'.$seller->id)->assertOk()->assertSee('Ada Market');

        $this->post('/admin/sellers/'.$seller->id.'/review', [
            'action' => 'reject',
            'note' => 'Need a clearer shop name.',
        ])->assertRedirect();
        $this->assertSame(Seller::STATUS_REJECTED, $seller->fresh()->status);

        $this->actingAs($customer, 'customer');
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload([
            'shop_name' => 'Ada Market Two',
        ]))->assertOk()->assertJsonPath('seller.status', 'pending');

        $this->approveSeller($seller, ['action' => 'approve', 'commission_rate' => 12]);
        $this->assertSame(Seller::STATUS_APPROVED, $seller->fresh()->status);
        $this->assertEquals(12, (float) $seller->fresh()->commission_rate);

        $this->actingAs($customer, 'customer');
        $this->getJson('/api/v1/account/seller/dashboard')
            ->assertOk()
            ->assertJsonPath('seller.is_approved', true);

        $this->approveSeller($seller, ['action' => 'block', 'note' => 'Policy violation.']);
        $this->assertSame(Seller::STATUS_BLOCKED, $seller->fresh()->status);
        $this->actingAs($customer, 'customer');
        $this->getJson('/api/v1/account/seller/products')->assertForbidden();
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload(['shop_slug' => 'ada-blocked']))
            ->assertForbidden();

        $this->approveSeller($seller, ['action' => 'reactivate']);
        $this->assertSame(Seller::STATUS_APPROVED, $seller->fresh()->status);
        $this->actingAs($customer, 'customer');
        $this->getJson('/api/v1/account/seller/dashboard')->assertOk();
    }

    public function test_approved_seller_dual_writes_products_and_cannot_see_another_sellers_catalog(): void
    {
        $this->seed();
        $customer = $this->registerSellerCustomer();
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload())->assertCreated();
        $seller = Seller::query()->firstOrFail();
        $this->approveSeller($seller);

        $this->actingAs($customer, 'customer');
        $category = Category::query()->first();
        $this->postJson('/api/v1/account/seller/products', [
            'name' => 'Clay mug',
            'sku' => 'mug-1',
            'price' => 18.5,
            'stock_qty' => 7,
            'category_id' => $category?->id,
            'is_enabled' => true,
        ])->assertCreated();

        $product = Product::query()->where('seller_id', $seller->id)->firstOrFail();
        $this->assertNotNull($product->seller_id);
        $this->assertDatabaseHas('seller_products', [
            'seller_id' => $seller->id,
            'product_id' => $product->id,
            'name' => 'Clay mug',
        ]);
        $this->assertSame(7, (int) SellerProduct::query()->where('product_id', $product->id)->value('stock_qty'));
        $this->assertSame('pending', SellerProduct::query()->where('product_id', $product->id)->value('approval_status'));
        $this->assertFalse((bool) $product->fresh()->is_enabled);
        $this->assertFalse((bool) $product->fresh()->available_for_order);

        $this->postJson('/api/v1/auth/logout')->assertOk();
        $other = $this->registerSellerCustomer('other@example.com');
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload([
            'email' => 'other.seller@example.com',
            'shop_name' => 'Other Shop',
            'shop_slug' => 'other-shop',
        ]))->assertCreated();
        $otherSeller = Seller::query()->where('shop_slug', 'other-shop')->firstOrFail();
        $this->approveSeller($otherSeller);

        $this->actingAs($other, 'customer');
        $this->getJson('/api/v1/account/seller/products/'.$product->id)->assertNotFound();
        $this->patchJson('/api/v1/account/seller/products/'.$product->id, [
            'name' => 'Hijacked',
            'price' => 1,
        ])->assertNotFound();
    }

    public function test_checkout_copies_seller_order_and_earnings(): void
    {
        $this->seed();
        $customer = $this->registerSellerCustomer();
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload())->assertCreated();
        $seller = Seller::query()->firstOrFail();
        $this->approveSeller($seller);

        MarketplaceSettings::save(['auto_approve_products' => true]);

        $this->actingAs($customer, 'customer');
        $this->postJson('/api/v1/account/seller/products', [
            'name' => 'Clay mug',
            'sku' => 'mug-2',
            'price' => 20,
            'stock_qty' => 5,
            'is_enabled' => true,
        ])->assertCreated();
        $product = Product::query()->where('seller_id', $seller->id)->firstOrFail();

        $this->postJson('/api/v1/auth/logout')->assertOk();
        $payment = PaymentMethod::query()->whereNull('seller_id')->firstOrFail();
        $shipping = ShippingMethod::query()->whereNull('seller_id')->firstOrFail();
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', [
            'email' => 'buyer@example.com',
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Pat', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Pat', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ])->assertCreated();

        $this->assertDatabaseHas('seller_orders', [
            'seller_id' => $seller->id,
            'customer_email' => 'buyer@example.com',
        ]);
        $this->assertSame(1, SellerOrder::query()->where('seller_id', $seller->id)->count());
        $this->assertDatabaseHas('seller_earnings', ['seller_id' => $seller->id]);
        $this->assertDatabaseHas('seller_revenues', ['seller_id' => $seller->id]);
    }

    public function test_seller_shipping_and_discount_dual_write_to_core_tables(): void
    {
        $this->seed();
        $customer = $this->registerSellerCustomer();
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload())->assertCreated();
        $seller = Seller::query()->firstOrFail();
        $this->approveSeller($seller);

        $this->actingAs($customer, 'customer');
        $this->postJson('/api/v1/account/seller/shipping', [
            'name' => 'Ada Parcel',
            'type' => 'flat',
            'price' => 4.5,
            'is_enabled' => true,
        ])->assertCreated();
        $this->assertDatabaseHas('shipping_methods', [
            'seller_id' => $seller->id,
            'name' => 'Ada Parcel',
        ]);

        $this->postJson('/api/v1/account/seller/discounts', [
            'name' => 'Ada ten',
            'code' => 'ADA10',
            'apply_as' => 'coupon',
            'action' => 'percent',
            'value' => 10,
            'is_enabled' => true,
        ])->assertCreated();
        $rule = DiscountRule::query()->where('seller_id', $seller->id)->firstOrFail();
        $this->assertSame('ADA10', $rule->code);
        $this->assertTrue($rule->conditions()->where('type', 'seller')->exists());

        $this->postJson('/api/v1/account/seller/payments', [
            'type' => 'bank',
            'name' => 'Ada Bank',
            'account_name' => 'Ada Seller',
            'account_number' => '123456',
            'bank_name' => 'Test Bank',
            'is_default' => true,
        ])->assertCreated();
        $this->assertDatabaseHas('payment_methods', [
            'seller_id' => $seller->id,
            'name' => 'Ada Bank',
            'is_enabled' => false,
        ]);
    }

    public function test_public_shop_contact_review_and_manual_product_approval(): void
    {
        $this->seed();
        $customer = $this->registerSellerCustomer();
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload())->assertCreated();
        $seller = Seller::query()->firstOrFail();
        $this->approveSeller($seller);

        $this->actingAs(Admin::query()->first(), 'admin')
            ->put('/admin/multivendor/settings', [
                'default_commission' => 10,
                'auto_approve_products' => false,
            ])
            ->assertRedirect();
        $this->assertFalse(MarketplaceSettings::autoApproveProducts());

        $this->actingAs($customer, 'customer');
        $this->postJson('/api/v1/account/seller/products', [
            'name' => 'Hidden mug',
            'sku' => 'mug-hide',
            'price' => 9,
            'stock_qty' => 3,
            'is_enabled' => true,
        ])->assertCreated();
        $product = Product::query()->where('seller_id', $seller->id)->firstOrFail();
        $copy = SellerProduct::query()->where('product_id', $product->id)->firstOrFail();
        $this->assertSame('pending', $copy->approval_status);
        $this->getJson('/api/v1/catalog/seller/ada-market')
            ->assertOk()
            ->assertJsonPath('shop.shop_name', 'Ada Market')
            ->assertJsonPath('products.meta.total', 0);

        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/multivendor/products/'.$copy->id.'/review', ['action' => 'approve'])
            ->assertRedirect();

        $this->actingAs($customer, 'customer');
        $this->getJson('/api/v1/catalog/seller/ada-market')
            ->assertOk()
            ->assertJsonPath('products.meta.total', 1);

        $this->postJson('/api/v1/catalog/seller/ada-market/contact', [
            'name' => 'Pat Buyer',
            'email' => 'pat@example.com',
            'body' => 'Do you ship to Austin this week?',
        ])->assertCreated();
        $this->assertDatabaseHas('seller_messages', [
            'seller_id' => $seller->id,
            'email' => 'pat@example.com',
        ]);

        $this->postJson('/api/v1/account/seller/categories', [
            'name' => 'Clay',
            'is_enabled' => true,
        ])->assertCreated();
        $this->assertDatabaseHas('seller_categories', [
            'seller_id' => $seller->id,
            'name' => 'Clay',
        ]);

        $this->postJson('/api/v1/auth/logout')->assertOk();
        $buyer = $this->registerSellerCustomer('pat@example.com');
        $payment = PaymentMethod::query()->whereNull('seller_id')->firstOrFail();
        $shipping = ShippingMethod::query()->whereNull('seller_id')->firstOrFail();
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', [
            'email' => 'pat@example.com',
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Pat', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Pat', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ])->assertCreated();

        $sellerOrder = \App\Domain\Marketplace\Models\SellerOrder::query()->where('seller_id', $seller->id)->firstOrFail();
        $this->postJson('/api/v1/auth/logout');
        $this->actingAs($customer, 'customer');
        $shipmentId = $this->postJson('/api/v1/account/seller/orders/'.$sellerOrder->id.'/shipments', [
            'tracking_number' => 'REV-ADA-1',
        ])->assertCreated()->json('shipment.id');
        $this->postJson('/api/v1/account/seller/shipments/'.$shipmentId.'/ship', [
            'tracking_number' => 'REV-ADA-1',
        ])->assertOk();
        $this->postJson('/api/v1/account/seller/shipments/'.$shipmentId.'/deliver')->assertOk();

        $this->postJson('/api/v1/auth/logout');
        $this->actingAs($buyer, 'customer');
        $this->postJson('/api/v1/account/seller-reviews', [
            'seller_id' => $seller->id,
            'rating' => 5,
            'title' => 'Great mug',
            'body' => 'Arrived quickly.',
        ])->assertCreated();
        $review = SellerReview::query()->firstOrFail();
        $this->assertSame('pending', $review->status);

        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/multivendor/reviews/'.$review->id.'/moderate', ['action' => 'approve'])
            ->assertRedirect();
        $this->postJson('/api/v1/auth/logout');
        $this->getJson('/api/v1/catalog/seller/ada-market')
            ->assertOk()
            ->assertJsonPath('shop.products_count', 1)
            ->assertJsonPath('products.meta.per_page', 12)
            ->assertJsonPath('products.meta.tabs.all', 1)
            ->assertJsonPath('shop.sold_count', 1)
            ->assertJsonPath('reviews.data.0.title', 'Great mug');

        $this->getJson('/api/v1/catalog/seller/ada-market?q=missing-product-name')
            ->assertOk()
            ->assertJsonPath('products.meta.total', 0)
            ->assertJsonPath('products.meta.tabs.all', 0);

        $this->getJson('/api/v1/catalog/seller/ada-market?sort=price_asc')
            ->assertOk()
            ->assertJsonPath('products.data.0.id', $product->id);
    }

    public function test_new_seller_products_stay_hidden_until_admin_approval(): void
    {
        $this->seed();
        $this->assertFalse(MarketplaceSettings::autoApproveProducts());
        $customer = $this->registerSellerCustomer();
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload())->assertCreated();
        $seller = Seller::query()->firstOrFail();
        $this->approveSeller($seller);

        $this->actingAs($customer, 'customer');
        $this->postJson('/api/v1/account/seller/products', [
            'name' => 'Pending mug',
            'sku' => 'mug-pending',
            'price' => 11,
            'stock_qty' => 2,
            'is_enabled' => true,
        ])->assertCreated();
        $product = Product::query()->where('seller_id', $seller->id)->firstOrFail();
        $this->assertSame('pending', SellerProduct::query()->where('product_id', $product->id)->value('approval_status'));
        $this->getJson('/api/v1/catalog/seller/ada-market')
            ->assertOk()
            ->assertJsonPath('products.meta.total', 0);

        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertNotFound();
    }

    public function test_vacation_mode_blocks_checkout_of_items_already_in_the_cart(): void
    {
        $this->seed();
        $customer = $this->registerSellerCustomer();
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload())->assertCreated();
        $seller = Seller::query()->firstOrFail();
        $this->approveSeller($seller);
        MarketplaceSettings::save(['auto_approve_products' => true]);

        $this->actingAs($customer, 'customer');
        $this->postJson('/api/v1/account/seller/products', [
            'name' => 'Clay mug',
            'sku' => 'mug-vac',
            'price' => 20,
            'stock_qty' => 5,
            'is_enabled' => true,
        ])->assertCreated();
        $product = Product::query()->where('seller_id', $seller->id)->firstOrFail();

        $this->postJson('/api/v1/auth/logout')->assertOk();
        $payment = PaymentMethod::query()->whereNull('seller_id')->firstOrFail();
        $shipping = ShippingMethod::query()->whereNull('seller_id')->firstOrFail();
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();

        $seller->update(['vacation_enabled' => true]);
        $this->postJson('/api/v1/checkout', [
            'email' => 'buyer@example.com',
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Pat', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Pat', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ])->assertStatus(422);
    }

    public function test_payouts_cannot_overdraw_held_balance_or_use_another_sellers_method(): void
    {
        $this->seed();
        $customer = $this->registerSellerCustomer();
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload())->assertCreated();
        $seller = Seller::query()->firstOrFail();
        $this->approveSeller($seller);

        $this->actingAs($customer, 'customer');
        $this->postJson('/api/v1/account/seller/payments', [
            'type' => 'bank',
            'name' => 'Ada Bank',
            'account_name' => 'Ada Seller',
            'account_number' => '123456',
            'bank_name' => 'Test Bank',
            'is_default' => true,
        ])->assertCreated();
        $methodId = (int) SellerPaymentMethod::query()->where('seller_id', $seller->id)->value('id');

        $otherSeller = Seller::query()->create([
            'customer_id' => Customer::factory()->create()->id,
            'status' => Seller::STATUS_APPROVED,
            'first_name' => 'Other',
            'last_name' => 'Seller',
            'shop_name' => 'Other Shop',
            'shop_slug' => 'other-shop',
            'profile_name' => 'Other Shop',
            'email' => 'other.seller@example.com',
            'phone' => '+1 555 0199',
            'contact_name' => 'Other Seller',
            'contact_phone' => '+1 555 0199',
            'address_line1' => '90 West Street',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'commission_rate' => 10,
        ]);
        $foreignMethodId = (int) SellerPaymentMethod::query()->create([
            'seller_id' => $otherSeller->id,
            'type' => 'bank',
            'name' => 'Other Bank',
            'account_name' => 'Other Seller',
            'account_number' => '999',
            'bank_name' => 'Other Bank',
            'is_enabled' => true,
        ])->id;

        SellerEarning::query()->create([
            'seller_id' => $seller->id,
            'gross_amount' => 100,
            'commission_amount' => 10,
            'net_amount' => 90,
            'status' => SellerEarning::STATUS_AVAILABLE,
        ]);
        SellerLedgerEntry::query()->create([
            'seller_id' => $seller->id,
            'entry_type' => SellerLedgerEntry::TYPE_ORDER_EARNING,
            'direction' => SellerLedgerEntry::DIRECTION_CREDIT,
            'amount' => 90,
            'currency_code' => 'USD',
            'reference' => 'test:earning:'.$seller->id.':90',
            'description' => 'Test ledger credit',
        ]);

        $this->getJson('/api/v1/account/seller/payouts')
            ->assertOk()
            ->assertJsonPath('available', 90);

        $this->postJson('/api/v1/account/seller/payouts', [
            'amount' => 60,
            'seller_payment_method_id' => $methodId,
        ])->assertCreated();
        $this->getJson('/api/v1/account/seller/payouts')
            ->assertOk()
            ->assertJsonPath('available', 30);
        $this->postJson('/api/v1/account/seller/payouts', [
            'amount' => 60,
            'seller_payment_method_id' => $methodId,
        ])->assertUnprocessable();
        $this->postJson('/api/v1/account/seller/payouts', [
            'amount' => 30,
            'seller_payment_method_id' => $foreignMethodId,
        ])->assertUnprocessable();
        $this->postJson('/api/v1/account/seller/payouts', [
            'amount' => 30,
            'seller_payment_method_id' => $methodId,
        ])->assertCreated();
        $this->assertSame(2, SellerWithdrawal::query()->where('seller_id', $seller->id)->count());
    }

    public function test_paying_a_payout_does_not_mark_larger_earning_rows_paid(): void
    {
        $this->seed();
        $customer = $this->registerSellerCustomer();
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload())->assertCreated();
        $seller = Seller::query()->firstOrFail();
        $this->approveSeller($seller);

        $first = SellerEarning::query()->create([
            'seller_id' => $seller->id,
            'gross_amount' => 30,
            'commission_amount' => 0,
            'net_amount' => 30,
            'status' => SellerEarning::STATUS_AVAILABLE,
        ]);
        $second = SellerEarning::query()->create([
            'seller_id' => $seller->id,
            'gross_amount' => 30,
            'commission_amount' => 0,
            'net_amount' => 30,
            'status' => SellerEarning::STATUS_AVAILABLE,
        ]);
        SellerLedgerEntry::query()->create([
            'seller_id' => $seller->id,
            'entry_type' => SellerLedgerEntry::TYPE_ORDER_EARNING,
            'direction' => SellerLedgerEntry::DIRECTION_CREDIT,
            'amount' => 60,
            'currency_code' => 'USD',
            'reference' => 'test:earning:'.$seller->id.':60',
            'description' => 'Test ledger credit',
        ]);

        $payout = app(SellerCatalogService::class)->requestWithdrawal($seller, ['amount' => 50]);
        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/multivendor/payouts/'.$payout->id.'/process', ['action' => 'pay'])
            ->assertRedirect();

        $this->assertSame(SellerWithdrawal::STATUS_PAID, $payout->fresh()->status);
        $this->assertSame(SellerEarning::STATUS_PAID, $first->fresh()->status);
        $this->assertSame(SellerEarning::STATUS_AVAILABLE, $second->fresh()->status);
        $this->assertEquals(10.0, app(SellerLedgerService::class)->balance($seller->fresh()));
        $this->assertSame(1, SellerLedgerEntry::query()
            ->where('entry_type', SellerLedgerEntry::TYPE_PAYOUT)
            ->where('seller_withdrawal_id', $payout->id)
            ->count());
    }

    public function test_marking_core_order_paid_unlocks_seller_earnings(): void
    {
        $this->seed();
        $customer = $this->registerSellerCustomer();
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload())->assertCreated();
        $seller = Seller::query()->firstOrFail();
        $this->approveSeller($seller);
        MarketplaceSettings::save(['auto_approve_products' => true]);

        $this->actingAs($customer, 'customer');
        $this->postJson('/api/v1/account/seller/products', [
            'name' => 'Paid mug',
            'sku' => 'mug-paid',
            'price' => 40,
            'stock_qty' => 3,
            'is_enabled' => true,
        ])->assertCreated();
        $product = Product::query()->where('seller_id', $seller->id)->firstOrFail();

        $this->postJson('/api/v1/auth/logout')->assertOk();
        $payment = PaymentMethod::query()->whereNull('seller_id')->firstOrFail();
        $shipping = ShippingMethod::query()->whereNull('seller_id')->firstOrFail();
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', [
            'email' => 'buyer@example.com',
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Pat', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Pat', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ])->assertCreated();

        $order = Order::query()->latest('id')->firstOrFail();
        $this->assertSame(SellerEarning::STATUS_PENDING, SellerEarning::query()->where('order_id', $order->id)->value('status'));

        $order->update(['payment_status' => 'paid']);

        $this->assertSame(SellerEarning::STATUS_AVAILABLE, SellerEarning::query()->where('order_id', $order->id)->value('status'));
        $this->assertSame('paid', SellerOrder::query()->where('order_id', $order->id)->value('payment_status'));
    }

    public function test_refunding_core_order_holds_seller_earnings(): void
    {
        $this->seed();
        $customer = $this->registerSellerCustomer();
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload())->assertCreated();
        $seller = Seller::query()->firstOrFail();
        $this->approveSeller($seller);
        MarketplaceSettings::save(['auto_approve_products' => true]);

        $this->actingAs($customer, 'customer');
        $this->postJson('/api/v1/account/seller/products', [
            'name' => 'Refund mug',
            'sku' => 'mug-refund',
            'price' => 25,
            'stock_qty' => 3,
            'is_enabled' => true,
        ])->assertCreated();
        $product = Product::query()->where('seller_id', $seller->id)->firstOrFail();

        $this->postJson('/api/v1/auth/logout')->assertOk();
        $payment = PaymentMethod::query()->whereNull('seller_id')->firstOrFail();
        $shipping = ShippingMethod::query()->whereNull('seller_id')->firstOrFail();
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', [
            'email' => 'buyer@example.com',
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Pat', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Pat', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ])->assertCreated();

        $order = Order::query()->latest('id')->firstOrFail();
        $order->update(['payment_status' => 'paid']);
        $this->assertSame(SellerEarning::STATUS_AVAILABLE, SellerEarning::query()->where('order_id', $order->id)->value('status'));

        $order->update(['status' => 'refunded', 'payment_status' => 'refunded']);

        $this->assertSame(SellerEarning::STATUS_REFUNDED, SellerEarning::query()->where('order_id', $order->id)->value('status'));
        $this->assertSame('refunded', SellerOrder::query()->where('order_id', $order->id)->value('payment_status'));
        $this->assertSame(0.0, (float) app(SellerCatalogService::class)->availablePayoutAmount($seller->fresh()));
    }

    public function test_saving_commission_always_updates_live_seller_rate(): void
    {
        $this->seed();
        $customer = $this->registerSellerCustomer();
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload())->assertCreated();
        $seller = Seller::query()->firstOrFail();
        $this->approveSeller($seller, ['action' => 'approve', 'commission_rate' => 10]);

        $row = SellerCommission::query()->create([
            'seller_id' => $seller->id,
            'name' => 'Alt rate',
            'rate' => 5,
            'is_default' => false,
            'is_enabled' => true,
        ]);

        app(SellerService::class)->setCommission($seller, 18, 'Updated', 'Alt rate', $row);

        $this->assertEquals(18, (float) $seller->fresh()->commission_rate);
        $this->assertTrue((bool) $row->fresh()->is_default);
        $this->assertEquals(18, (float) $row->fresh()->rate);
    }
}
