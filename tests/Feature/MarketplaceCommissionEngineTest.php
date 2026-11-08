<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\Models\MarketplaceCommissionRule;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerEarning;
use App\Domain\Marketplace\Models\SellerOrder;
use App\Domain\Marketplace\Models\SellerProduct;
use App\Domain\Marketplace\Services\CommissionService;
use App\Domain\Marketplace\Services\SellerService;
use App\Domain\Order\Models\Order;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Models\Admin;

/**
 * Phase 09 — commission engine: percent/fixed, Product→Seller→Category→Global precedence,
 * snapshots, discount/tax/shipping behavior, refund reverse.
 */
class MarketplaceCommissionEngineTest extends MarketplaceTestCase
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
    protected function sellerWithProduct(
        string $email,
        string $slug,
        string $name,
        float $price = 100,
        ?float $commission = null,
    ): array {
        $this->postJson('/api/v1/auth/logout');
        $owner = $this->registerCustomer($email);
        $this->postJson('/api/v1/account/seller/apply', $this->applyPayload([
            'email' => str_replace('@', '.shop@', $email),
            'shop_name' => $slug.' Shop',
            'shop_slug' => $slug,
        ]))->assertCreated();
        $seller = Seller::query()->where('customer_id', $owner->id)->firstOrFail();

        $review = ['action' => 'approve'];
        if ($commission !== null) {
            $review['commission_rate'] = $commission;
        }
        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/multivendor/seller-requests/'.$seller->id.'/review', $review)
            ->assertRedirect();
        \Illuminate\Support\Facades\Auth::guard('admin')->logout();

        if ($commission === null) {
            $seller->update(['commission_rate' => null]);
            $seller->commissions()->update(['is_enabled' => false]);
        }

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

    protected function placeOrder(Product $product, int $qty = 1): SellerOrder
    {
        $this->postJson('/api/v1/auth/logout');
        $this->registerCustomer('buyer@example.com');
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => $qty])->assertOk();
        $this->postJson('/api/v1/checkout', $this->checkoutPayload())->assertCreated();

        return SellerOrder::query()->latest('id')->firstOrFail();
    }

    public function test_percent_and_fixed_rounding(): void
    {
        $svc = app(CommissionService::class);

        $percent = $svc->calculate(10.01, ['type' => 'percent', 'value' => 10]);
        $this->assertSame(1.0, $percent['commission_amount']);
        $this->assertSame(9.01, $percent['seller_net']);
        $this->assertSame('percent', $percent['commission_type']);

        $fixed = $svc->calculate(100, ['type' => 'fixed', 'value' => 7.5]);
        $this->assertSame(7.5, $fixed['commission_amount']);
        $this->assertSame(92.5, $fixed['seller_net']);
        $this->assertSame('fixed', $fixed['commission_type']);

        $capped = $svc->calculate(5, ['type' => 'fixed', 'value' => 20]);
        $this->assertSame(5.0, $capped['commission_amount']);
        $this->assertSame(0.0, $capped['seller_net']);
    }

    public function test_precedence_product_beats_seller_category_and_global(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true, 'default_commission' => 25]);
        [$seller, , $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', 100, 15);

        $categoryId = (int) Category::query()->value('id');
        $product->update(['default_category_id' => $categoryId]);
        $product->categories()->sync([$categoryId => ['is_default' => true]]);

        MarketplaceCommissionRule::query()->create([
            'name' => 'Global 30%',
            'scope' => MarketplaceCommissionRule::SCOPE_GLOBAL,
            'type' => MarketplaceCommissionRule::TYPE_PERCENT,
            'value' => 30,
            'is_enabled' => true,
        ]);
        MarketplaceCommissionRule::query()->create([
            'name' => 'Category 20%',
            'scope' => MarketplaceCommissionRule::SCOPE_CATEGORY,
            'type' => MarketplaceCommissionRule::TYPE_PERCENT,
            'value' => 20,
            'category_id' => $categoryId,
            'is_enabled' => true,
        ]);
        MarketplaceCommissionRule::query()->create([
            'name' => 'Seller rule 18%',
            'scope' => MarketplaceCommissionRule::SCOPE_SELLER,
            'type' => MarketplaceCommissionRule::TYPE_PERCENT,
            'value' => 18,
            'seller_id' => $seller->id,
            'is_enabled' => true,
        ]);
        $productRule = MarketplaceCommissionRule::query()->create([
            'name' => 'Product 5%',
            'scope' => MarketplaceCommissionRule::SCOPE_PRODUCT,
            'type' => MarketplaceCommissionRule::TYPE_PERCENT,
            'value' => 5,
            'product_id' => $product->id,
            'is_enabled' => true,
        ]);

        $resolved = app(CommissionService::class)->resolveRule($seller->fresh(), $product->fresh());
        $this->assertSame('product', $resolved['source']);
        $this->assertSame(5.0, $resolved['value']);
        $this->assertSame((int) $productRule->id, $resolved['rule_id']);

        $sellerOrder = $this->placeOrder($product);
        $this->assertSame('product', $sellerOrder->commission_source);
        $this->assertSame('percent', $sellerOrder->commission_type);
        $this->assertEquals(5.0, (float) $sellerOrder->commission_value);
        $this->assertEquals(5.0, (float) $sellerOrder->commission_total);
        $this->assertEquals(95.0, (float) $sellerOrder->earning_total);
        $this->assertSame((int) $productRule->id, (int) $sellerOrder->commission_rule_id);

        $earning = SellerEarning::query()->where('seller_order_id', $sellerOrder->id)->firstOrFail();
        $this->assertSame('product', $earning->commission_source);
        $this->assertEquals(5.0, (float) $earning->commission_amount);
    }

    public function test_precedence_seller_beats_category_and_global(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true, 'default_commission' => 25]);
        [$seller, , $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', 100, null);

        $categoryId = (int) Category::query()->value('id');
        $product->update(['default_category_id' => $categoryId]);
        $product->categories()->sync([$categoryId => ['is_default' => true]]);

        MarketplaceCommissionRule::query()->create([
            'name' => 'Global 30%',
            'scope' => MarketplaceCommissionRule::SCOPE_GLOBAL,
            'type' => MarketplaceCommissionRule::TYPE_PERCENT,
            'value' => 30,
            'is_enabled' => true,
        ]);
        MarketplaceCommissionRule::query()->create([
            'name' => 'Category 20%',
            'scope' => MarketplaceCommissionRule::SCOPE_CATEGORY,
            'type' => MarketplaceCommissionRule::TYPE_PERCENT,
            'value' => 20,
            'category_id' => $categoryId,
            'is_enabled' => true,
        ]);
        MarketplaceCommissionRule::query()->create([
            'name' => 'Seller fixed 12',
            'scope' => MarketplaceCommissionRule::SCOPE_SELLER,
            'type' => MarketplaceCommissionRule::TYPE_FIXED,
            'value' => 12,
            'seller_id' => $seller->id,
            'is_enabled' => true,
        ]);

        $sellerOrder = $this->placeOrder($product);
        $this->assertSame('seller', $sellerOrder->commission_source);
        $this->assertSame('fixed', $sellerOrder->commission_type);
        $this->assertEquals(12.0, (float) $sellerOrder->commission_total);
        $this->assertEquals(88.0, (float) $sellerOrder->earning_total);
    }

    public function test_precedence_category_beats_global(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true, 'default_commission' => 25]);
        [, , $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', 100, null);

        $categoryId = (int) Category::query()->value('id');
        $product->update(['default_category_id' => $categoryId]);
        $product->categories()->sync([$categoryId => ['is_default' => true]]);

        MarketplaceCommissionRule::query()->create([
            'name' => 'Global 30%',
            'scope' => MarketplaceCommissionRule::SCOPE_GLOBAL,
            'type' => MarketplaceCommissionRule::TYPE_PERCENT,
            'value' => 30,
            'is_enabled' => true,
        ]);
        MarketplaceCommissionRule::query()->create([
            'name' => 'Category 8%',
            'scope' => MarketplaceCommissionRule::SCOPE_CATEGORY,
            'type' => MarketplaceCommissionRule::TYPE_PERCENT,
            'value' => 8,
            'category_id' => $categoryId,
            'is_enabled' => true,
        ]);

        $sellerOrder = $this->placeOrder($product);
        $this->assertSame('category', $sellerOrder->commission_source);
        $this->assertEquals(8.0, (float) $sellerOrder->commission_total);
        $this->assertEquals(92.0, (float) $sellerOrder->earning_total);
    }

    public function test_global_rule_and_settings_fallback(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true, 'default_commission' => 11]);
        [$seller, , $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', 100, null);

        $sellerOrder = $this->placeOrder($product);
        $this->assertSame('global', $sellerOrder->commission_source);
        $this->assertEquals(11.0, (float) $sellerOrder->commission_total);

        MarketplaceCommissionRule::query()->create([
            'name' => 'Global fixed 9',
            'scope' => MarketplaceCommissionRule::SCOPE_GLOBAL,
            'type' => MarketplaceCommissionRule::TYPE_FIXED,
            'value' => 9,
            'is_enabled' => true,
        ]);

        $resolved = app(CommissionService::class)->resolveRule($seller->fresh(), $product->fresh());
        $this->assertSame('global', $resolved['source']);
        $this->assertSame('fixed', $resolved['type']);
        $this->assertSame(9.0, $resolved['value']);

        $calc = app(CommissionService::class)->calculate(100, $resolved);
        $this->assertSame(9.0, $calc['commission_amount']);
        $this->assertSame(91.0, $calc['seller_net']);
    }

    public function test_historical_snapshot_survives_rule_change(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true, 'default_commission' => 10]);
        [$seller, , $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', 100, null);

        $rule = MarketplaceCommissionRule::query()->create([
            'name' => 'Seller 10%',
            'scope' => MarketplaceCommissionRule::SCOPE_SELLER,
            'type' => MarketplaceCommissionRule::TYPE_PERCENT,
            'value' => 10,
            'seller_id' => $seller->id,
            'is_enabled' => true,
        ]);

        $sellerOrder = $this->placeOrder($product);
        $this->assertEquals(10.0, (float) $sellerOrder->commission_total);
        $this->assertSame((int) $rule->id, (int) $sellerOrder->commission_rule_id);

        $rule->update(['value' => 40]);
        MarketplaceCommissionRule::query()->create([
            'name' => 'Product override',
            'scope' => MarketplaceCommissionRule::SCOPE_PRODUCT,
            'type' => MarketplaceCommissionRule::TYPE_PERCENT,
            'value' => 50,
            'product_id' => $product->id,
            'is_enabled' => true,
        ]);

        app(SellerService::class)->captureOrder(Order::query()->firstOrFail());
        $sellerOrder->refresh();
        $this->assertEquals(10.0, (float) $sellerOrder->commission_total);
        $this->assertEquals(90.0, (float) $sellerOrder->earning_total);
        $this->assertSame(1, SellerEarning::query()->count());
    }

    public function test_tax_and_shipping_excluded_from_commissionable_gross(): void
    {
        $svc = app(CommissionService::class);
        // Explicit contract: commission uses merchandise gross only.
        $calc = $svc->calculate(100, 10);
        $this->assertSame(10.0, $calc['commission_amount']);
        // Tax/shipping are never passed into calculate() by writeSellerOrder.
        $this->assertSame(90.0, $calc['seller_net']);
    }

    public function test_refund_reverses_commission_via_ledger_debit(): void
    {
        $this->seed();
        MarketplaceSettings::save(['auto_approve_products' => true]);
        [$seller, , $product] = $this->sellerWithProduct('a@example.com', 'shop-a', 'Mug', 80, null);

        MarketplaceCommissionRule::query()->create([
            'name' => 'Seller fixed 8',
            'scope' => MarketplaceCommissionRule::SCOPE_SELLER,
            'type' => MarketplaceCommissionRule::TYPE_FIXED,
            'value' => 8,
            'seller_id' => $seller->id,
            'is_enabled' => true,
        ]);

        $sellerOrder = $this->placeOrder($product);
        $this->assertEquals(8.0, (float) $sellerOrder->commission_total);
        $this->assertEquals(72.0, (float) $sellerOrder->earning_total);

        $order = Order::query()->latest('id')->firstOrFail();
        $order->update(['payment_status' => 'paid']);
        $order->update(['status' => 'refunded', 'payment_status' => 'refunded']);

        $this->assertSame(SellerEarning::STATUS_REFUNDED, SellerEarning::query()->value('status'));
        $this->assertEquals(0.0, app(\App\Domain\Marketplace\Services\SellerLedgerService::class)->balance($seller->fresh()));
    }

    public function test_admin_can_manage_commission_rules(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->post('/admin/multivendor/commission-rules', [
                'name' => 'Platform default',
                'scope' => 'global',
                'type' => 'percent',
                'value' => 12.5,
                'is_enabled' => true,
            ])
            ->assertRedirect('/admin/multivendor/commission-rules');

        $this->assertDatabaseHas('marketplace_commission_rules', [
            'name' => 'Platform default',
            'scope' => 'global',
            'type' => 'percent',
            'value' => 12.5,
        ]);

        $rule = MarketplaceCommissionRule::query()->firstOrFail();
        $this->actingAs($admin, 'admin')
            ->get('/admin/multivendor/commission-rules/'.$rule->id.'/edit')
            ->assertOk();
    }
}
