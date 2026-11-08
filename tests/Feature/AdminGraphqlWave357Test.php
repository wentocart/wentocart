<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\Models\MarketplaceDiscountJoin;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\SellerDiscountJoinSchema;
use App\Domain\Promo\DiscountEngine;
use App\Domain\Promo\Models\DiscountRule;
use App\Models\Admin;

class AdminGraphqlWave357Test extends MarketplaceTestCase
{
    public function test_joined_admin_catalog_discount_applies_to_seller_owned_product(): void
    {
        $this->seed();
        SellerDiscountJoinSchema::ensure();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        MarketplaceSettings::save([
            'enable_product_discount_join' => true,
            'auto_approve_discount_join' => true,
            'subscription_plans_enabled' => false,
        ]);

        $rule = DiscountRule::query()->create([
            'name' => 'Join Apply '.$suffix,
            'apply_as' => 'catalog',
            'action' => 'percent',
            'value' => 20,
            'priority' => 1,
            'is_enabled' => true,
            'allow_sellers_to_join' => true,
            'seller_id' => null,
        ]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave357 Seller',
            'email' => 'w357-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w357-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave357 Shop '.$suffix,
            'shop_slug' => 'w357-shop-'.$suffix,
            'profile_name' => 'Wave357 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0357',
            'email' => 'w357-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0357',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave357 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w357-shop-'.$suffix)->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $product = Product::query()->create([
            'type' => 'simple',
            'sku' => 'JOIN-'.$suffix,
            'price' => 100,
            'stock_qty' => 5,
            'is_enabled' => true,
            'seller_id' => $seller->id,
        ]);

        $engine = app(DiscountEngine::class);
        $engine->flush();
        $before = $engine->catalogUnitPrice($product);
        $this->assertSame(100.0, $before);

        MarketplaceDiscountJoin::query()->create([
            'seller_id' => $seller->id,
            'discount_rule_id' => $rule->id,
            'status' => MarketplaceDiscountJoin::STATUS_APPROVED,
        ]);
        $engine->flush();
        $after = $engine->catalogUnitPrice($product);
        $this->assertSame(80.0, $after);
    }
}
