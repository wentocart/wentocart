<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Checkout\Models\Cart;
use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Promo\DiscountContext;
use App\Domain\Promo\DiscountEngine;
use App\Domain\Promo\Models\DiscountRule;
use App\Models\Admin;

class AdminGraphqlWave360Test extends MarketplaceTestCase
{
    public function test_store_cart_rules_skip_seller_lines_seller_deals_scoped(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave360 Seller',
            'email' => 'w360-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w360-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave360 Shop '.$suffix,
            'shop_slug' => 'w360-shop-'.$suffix,
            'profile_name' => 'Wave360 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0360',
            'email' => 'w360-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0360',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave360 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w360-shop-'.$suffix)->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $platform = Product::query()->create([
            'type' => 'simple',
            'sku' => 'PLAT360-'.$suffix,
            'price' => 40,
            'stock_qty' => 5,
            'is_enabled' => true,
            'seller_id' => null,
        ]);
        $sellerProduct = Product::query()->create([
            'type' => 'simple',
            'sku' => 'SELL360-'.$suffix,
            'price' => 60,
            'stock_qty' => 5,
            'is_enabled' => true,
            'seller_id' => $seller->id,
        ]);

        DiscountRule::query()->create([
            'name' => 'Store cart '.$suffix,
            'apply_as' => 'cart',
            'action' => 'percent',
            'value' => 50,
            'priority' => 1,
            'is_enabled' => true,
            'seller_id' => null,
        ]);
        DiscountRule::query()->create([
            'name' => 'Seller cart '.$suffix,
            'apply_as' => 'cart',
            'action' => 'percent',
            'value' => 10,
            'priority' => 1,
            'is_enabled' => true,
            'seller_id' => $seller->id,
        ]);

        $cart = Cart::query()->create([]);
        $engine = app(DiscountEngine::class);
        $engine->flush();
        $context = new DiscountContext(cartSubtotal: 100);
        $lines = [
            ['product' => $platform, 'qty' => 1, 'line_total' => 40.0],
            ['product' => $sellerProduct, 'qty' => 1, 'line_total' => 60.0],
        ];
        $result = $engine->cartReduction($cart, $context, $lines);
        // Store 50% of platform 40 = 20; seller 10% of 60 = 6; bestReduction picks one rule.
        // bestReduction returns the single best rule amount — verify seller lines aren't in store rule.
        $this->assertTrue($engine->applies(
            DiscountRule::query()->where('name', 'Store cart '.$suffix)->firstOrFail(),
            $context,
            [['product' => $platform, 'qty' => 1, 'line_total' => 40.0]]
        ));
        $this->assertFalse($engine->applies(
            DiscountRule::query()->where('name', 'Store cart '.$suffix)->firstOrFail(),
            $context,
            [['product' => $sellerProduct, 'qty' => 1, 'line_total' => 60.0]]
        ));
        $this->assertTrue($engine->applies(
            DiscountRule::query()->where('name', 'Seller cart '.$suffix)->firstOrFail(),
            $context,
            [['product' => $sellerProduct, 'qty' => 1, 'line_total' => 60.0]]
        ));
        $this->assertFalse($engine->applies(
            DiscountRule::query()->where('name', 'Seller cart '.$suffix)->firstOrFail(),
            $context,
            [['product' => $platform, 'qty' => 1, 'line_total' => 40.0]]
        ));
        $this->assertIsArray($result);
    }
}
