<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerFlag;
use App\Domain\Marketplace\Models\SellerOrder;
use App\Domain\Marketplace\SellerFlagSchema;
use App\Domain\Order\Models\Order;
use App\Models\Admin;

class AdminGraphqlWave305Test extends MarketplaceTestCase
{
    public function test_rest_marketplace_seller_flags_rest_and_storefront_report(): void
    {
        $this->seed();
        SellerFlagSchema::ensure();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave305 Seller',
            'email' => 'w305-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w305-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave305 Shop '.$suffix,
            'shop_slug' => 'w305-shop-'.$suffix,
            'profile_name' => 'Wave305 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0305',
            'email' => 'w305-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0305',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave305 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w305-shop-'.$suffix)->firstOrFail();

        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave305 Buyer',
            'email' => 'w305b-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $buyer = Customer::query()->where('email', 'w305b-'.$suffix.'@example.test')->firstOrFail();

        $order = Order::createTrusted([
            'number' => 'W305-'.$suffix,
            'customer_id' => $buyer->id,
            'email' => $buyer->email,
            'status' => 'completed',
            'currency_code' => 'USD',
            'subtotal' => 30,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'grand_total' => 30,
            'payment_status' => 'paid',
            'billing_address' => ['line1' => '1 Test'],
            'shipping_address' => ['line1' => '1 Test'],
        ]);
        SellerOrder::createTrusted([
            'order_id' => $order->id,
            'seller_id' => $seller->id,
            'number' => 'SO-W305-'.$suffix,
            'customer_email' => $buyer->email,
            'status' => 'completed',
            'payment_status' => 'paid',
            'currency_code' => 'USD',
            'subtotal' => 30,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'commission_total' => 3,
            'earning_total' => 27,
            'grand_total' => 30,
        ]);

        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($buyer, 'customer')->postJson(
            '/api/v1/catalog/seller/'.$seller->shop_slug.'/flag',
            ['reason' => 'Misleading listing', 'body' => 'Item not as described']
        )->assertCreated();

        $this->flushSession();
        auth('customer')->logout();

        $list = $this->actingAs($admin, 'admin')->getJson(
            '/api/v1/console/marketplace/flags?seller_id='.$seller->id.'&status=pending'
        );
        $list->assertOk();
        $this->assertGreaterThanOrEqual(1, (int) ($list->json('meta.total') ?? 0));
        $flagId = (int) ($list->json('data.0.id') ?? 0);
        $this->assertGreaterThan(0, $flagId);

        $mod = $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/flags/'.$flagId.'/moderate',
            ['action' => 'accept', 'note' => 'Valid report']
        );
        $mod->assertOk();
        $this->assertSame(SellerFlag::STATUS_ACCEPTED, $flagId ? SellerFlag::query()->find($flagId)?->status : null);

        $health = $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/account-health/refresh'
        );
        $health->assertOk();
        $this->assertTrue((bool) ($health->json('data.metrics.flags.enabled') ?? false));
        $this->assertGreaterThanOrEqual(1, (int) ($health->json('data.metrics.flags.count') ?? 0));
    }
}
