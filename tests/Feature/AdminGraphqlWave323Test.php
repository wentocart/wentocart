<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\MarketplaceSubscriptionPlanSchema;
use App\Domain\Marketplace\Models\MarketplaceSubscriptionPlan;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerOrder;
use App\Domain\Marketplace\Services\SellerSubscriptionGate;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Models\Admin;
use Illuminate\Validation\ValidationException;

class AdminGraphqlWave323Test extends MarketplaceTestCase
{
    public function test_plan_order_limit_blocks_further_seller_orders(): void
    {
        $this->seed();
        MarketplaceSubscriptionPlanSchema::ensure();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        MarketplaceSettings::save([
            'subscription_plans_enabled' => true,
            'auto_approve_products' => true,
        ]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave323 Seller',
            'email' => 'w323-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w323-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave323 Shop '.$suffix,
            'shop_slug' => 'w323-shop-'.$suffix,
            'profile_name' => 'Wave323 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0323',
            'email' => 'w323-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0323',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave323 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w323-shop-'.$suffix)->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $plan = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketplace/subscription-plans', [
            'name' => 'One Order '.$suffix,
            'code' => 'one-ord-'.$suffix,
            'price' => 0,
            'billing_period' => MarketplaceSubscriptionPlan::PERIOD_MONTH,
            'order_limit' => 1,
            'product_limit' => 10,
            'is_enabled' => true,
        ])->assertCreated();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/subscription-plans/'.(int) $plan->json('data.id').'/assign',
            ['seller_id' => $seller->id]
        )->assertCreated();

        $this->flushSession();
        auth('admin')->logout();
        $this->actingAs($owner, 'customer');
        $this->postJson('/api/v1/account/seller/products', [
            'name' => 'Limited '.$suffix,
            'sku' => 'w323-'.$suffix,
            'price' => 15,
            'stock_qty' => 10,
            'type' => 'simple',
            'is_enabled' => true,
        ])->assertCreated();
        $product = Product::query()->where('seller_id', $seller->id)->firstOrFail();

        $this->postJson('/api/v1/auth/logout')->assertOk();
        $payment = PaymentMethod::query()->whereNull('seller_id')->firstOrFail();
        $shipping = ShippingMethod::query()->whereNull('seller_id')->firstOrFail();
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/checkout', [
            'email' => 'buyer323@example.com',
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Pat', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Pat', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ])->assertCreated();
        $this->assertSame(1, SellerOrder::query()->where('seller_id', $seller->id)->count());

        $gate = app(SellerSubscriptionGate::class);
        try {
            $gate->assertCanAcceptOrder($seller->fresh());
            $this->fail('Expected order_limit ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('order_limit', $e->errors());
        }

        MarketplaceSettings::save(['subscription_plans_enabled' => false]);
    }
}
