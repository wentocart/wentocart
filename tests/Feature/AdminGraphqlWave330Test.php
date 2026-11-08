<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Checkout\Services\CartService;
use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\SellerMinimumOrderSchema;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Models\Admin;
use Illuminate\Validation\ValidationException;

class AdminGraphqlWave330Test extends MarketplaceTestCase
{
    public function test_seller_minimum_order_amount_blocks_checkout(): void
    {
        $this->seed();
        SellerMinimumOrderSchema::ensure();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        MarketplaceSettings::save([
            'enable_minimum_order_amount' => true,
            'auto_approve_products' => true,
        ]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave330 Seller',
            'email' => 'w330-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w330-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave330 Shop '.$suffix,
            'shop_slug' => 'w330-shop-'.$suffix,
            'profile_name' => 'Wave330 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0330',
            'email' => 'w330-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0330',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave330 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w330-shop-'.$suffix)->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $this->flushSession();
        auth('admin')->logout();
        $this->actingAs($owner, 'customer');
        $this->postJson('/api/v1/account/seller/products', [
            'name' => 'Cheap '.$suffix,
            'sku' => 'w330-'.$suffix,
            'price' => 5,
            'stock_qty' => 20,
            'type' => 'simple',
            'is_enabled' => true,
        ])->assertCreated();
        $product = Product::query()->where('seller_id', $seller->id)->firstOrFail();

        $seller->forceFill(['minimum_order_amount' => 50])->save();

        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $cart = $this->getJson('/api/v1/cart')->assertOk();
        $violations = $cart->json('cart.minimum_order_violations') ?? $cart->json('minimum_order_violations') ?? [];
        $this->assertNotEmpty($violations);

        $payment = PaymentMethod::query()->whereNull('seller_id')->firstOrFail();
        $shipping = ShippingMethod::query()->whereNull('seller_id')->firstOrFail();
        $blocked = $this->postJson('/api/v1/checkout', [
            'email' => 'buyer330@example.com',
            'payment_method_id' => $payment->id,
            'shipping_method_id' => $shipping->id,
            'billing_address' => ['first_name' => 'Pat', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Pat', 'line1' => '1 Main', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country_code' => 'US'],
        ]);
        $blocked->assertStatus(422);
        $this->assertStringContainsString('Minimum order amount', (string) ($blocked->json('message') ?? json_encode($blocked->json('errors') ?? [])));

        MarketplaceSettings::save(['enable_minimum_order_amount' => false]);
    }
}
