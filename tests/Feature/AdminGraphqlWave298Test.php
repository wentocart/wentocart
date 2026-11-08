<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerOrder;
use App\Domain\Marketplace\Models\SellerProduct;
use App\Domain\Order\Models\Order;
use App\Models\Admin;

class AdminGraphqlWave298Test extends MarketplaceTestCase
{
    public function test_rest_admin_marketplace_products_and_orders_rest(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave298 Seller',
            'email' => 'w298-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $customer = Customer::query()->where('email', 'w298-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($customer, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave298 Shop '.$suffix,
            'shop_slug' => 'w298-shop-'.$suffix,
            'profile_name' => 'Wave298 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0298',
            'email' => 'w298-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0298',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave298 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w298-shop-'.$suffix)->firstOrFail();

        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 12]
        )->assertOk();

        $catalog = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/products', [
            'sku' => 'w298-cat-'.$suffix,
            'name' => 'Wave298 Catalog '.$suffix,
            'price' => 19.5,
            'type' => 'simple',
            'stock_qty' => 4,
            'status' => 1,
        ]);
        $catalog->assertCreated();
        $catalogId = (int) $catalog->json('data.id');
        Product::query()->whereKey($catalogId)->update(['seller_id' => $seller->id]);

        $product = SellerProduct::query()->create([
            'seller_id' => $seller->id,
            'product_id' => $catalogId,
            'sku' => 'w298-'.$suffix,
            'name' => 'Wave298 Product '.$suffix,
            'price' => 19.5,
            'stock_qty' => 4,
            'is_enabled' => true,
            'approval_status' => SellerProduct::STATUS_PENDING,
        ]);

        $plist = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketplace/products?seller_id='.$seller->id);
        $plist->assertOk();
        $this->assertNotEmpty($plist->json('data'));

        $pshow = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketplace/products/'.$product->id);
        $pshow->assertOk();
        $this->assertSame($catalogId, (int) ($pshow->json('data.productId') ?? 0));

        $approve = $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/products/'.$product->id.'/review',
            ['action' => 'approve']
        );
        $approve->assertOk();
        $this->assertSame(SellerProduct::STATUS_APPROVED, $product->fresh()->approval_status);

        $order = Order::createTrusted([
            'number' => 'W298-'.$suffix,
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'pending',
            'currency_code' => 'USD',
            'subtotal' => 19.5,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'grand_total' => 19.5,
            'payment_status' => 'pending',
            'billing_address' => ['line1' => '1 Test St'],
            'shipping_address' => ['line1' => '1 Test St'],
        ]);

        $sellerOrder = SellerOrder::createTrusted([
            'order_id' => $order->id,
            'seller_id' => $seller->id,
            'number' => 'SO-W298-'.$suffix,
            'customer_email' => 'buyer-'.$suffix.'@example.test',
            'status' => 'pending',
            'payment_status' => 'pending',
            'currency_code' => 'USD',
            'subtotal' => 19.5,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'commission_total' => 2.34,
            'earning_total' => 17.16,
            'grand_total' => 19.5,
        ]);

        $olist = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketplace/orders?seller_id='.$seller->id);
        $olist->assertOk();
        $this->assertNotEmpty($olist->json('data'));

        $oshow = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketplace/orders/'.$sellerOrder->id);
        $oshow->assertOk();
        $this->assertSame('SO-W298-'.$suffix, (string) ($oshow->json('data.number') ?? ''));
    }
}
