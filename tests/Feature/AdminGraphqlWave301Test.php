<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerOrder;
use App\Domain\Marketplace\Models\SellerReturn;
use App\Domain\Order\Models\Order;
use App\Models\Admin;

class AdminGraphqlWave301Test extends MarketplaceTestCase
{
    public function test_rest_admin_marketplace_returns_rest(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave301 Seller',
            'email' => 'w301-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w301-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave301 Shop '.$suffix,
            'shop_slug' => 'w301-shop-'.$suffix,
            'profile_name' => 'Wave301 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0301',
            'email' => 'w301-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0301',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave301 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w301-shop-'.$suffix)->firstOrFail();

        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $order = Order::createTrusted([
            'number' => 'W301-'.$suffix,
            'customer_id' => $owner->id,
            'email' => $owner->email,
            'status' => 'processing',
            'currency_code' => 'USD',
            'subtotal' => 40,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'grand_total' => 40,
            'payment_status' => 'paid',
            'billing_address' => ['line1' => '1 Test St'],
            'shipping_address' => ['line1' => '1 Test St'],
        ]);
        $sellerOrder = SellerOrder::createTrusted([
            'order_id' => $order->id,
            'seller_id' => $seller->id,
            'number' => 'SO-W301-'.$suffix,
            'customer_email' => $owner->email,
            'status' => 'completed',
            'payment_status' => 'paid',
            'currency_code' => 'USD',
            'subtotal' => 40,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'commission_total' => 4,
            'earning_total' => 36,
            'grand_total' => 40,
        ]);

        $retApprove = SellerReturn::query()->create([
            'seller_id' => $seller->id,
            'seller_order_id' => $sellerOrder->id,
            'order_id' => $order->id,
            'customer_id' => $owner->id,
            'number' => 'RET-W301A-'.$suffix,
            'status' => SellerReturn::STATUS_REQUESTED,
            'reason' => 'damaged',
            'refund_total' => 20,
            'requested_at' => now(),
        ]);
        $retReject = SellerReturn::query()->create([
            'seller_id' => $seller->id,
            'seller_order_id' => $sellerOrder->id,
            'order_id' => $order->id,
            'customer_id' => $owner->id,
            'number' => 'RET-W301B-'.$suffix,
            'status' => SellerReturn::STATUS_REQUESTED,
            'reason' => 'changed mind',
            'refund_total' => 10,
            'requested_at' => now(),
        ]);

        $list = $this->actingAs($admin, 'admin')->getJson(
            '/api/v1/console/marketplace/returns?seller_id='.$seller->id.'&status=requested'
        );
        $list->assertOk();
        $this->assertGreaterThanOrEqual(2, (int) ($list->json('meta.total') ?? 0));

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketplace/returns/'.$retApprove->id);
        $show->assertOk();
        $this->assertSame('RET-W301A-'.$suffix, (string) ($show->json('data.number') ?? ''));

        $approve = $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/returns/'.$retApprove->id.'/approve',
            ['note' => 'Looks valid']
        );
        $approve->assertOk();
        $this->assertSame(SellerReturn::STATUS_APPROVED, $retApprove->fresh()->status);

        $receive = $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/returns/'.$retApprove->id.'/receive'
        );
        $receive->assertOk();
        $this->assertSame(SellerReturn::STATUS_RECEIVED, $retApprove->fresh()->status);

        $reject = $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/returns/'.$retReject->id.'/reject',
            ['note' => 'Out of window']
        );
        $reject->assertOk();
        $this->assertSame(SellerReturn::STATUS_REJECTED, $retReject->fresh()->status);
    }
}
