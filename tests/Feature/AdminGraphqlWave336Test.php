<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerOrder;
use App\Domain\Marketplace\SellerPaymentRequestSchema;
use App\Domain\Marketplace\Services\SellerPaymentRequestService;
use App\Domain\Order\Models\Order;
use App\Models\Admin;

class AdminGraphqlWave336Test extends MarketplaceTestCase
{
    public function test_seller_payment_request_and_admin_pay(): void
    {
        $this->seed();
        SellerPaymentRequestSchema::ensure();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave336 Seller',
            'email' => 'w336-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w336-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave336 Shop '.$suffix,
            'shop_slug' => 'w336-shop-'.$suffix,
            'profile_name' => 'Wave336 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0336',
            'email' => 'w336-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0336',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave336 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w336-shop-'.$suffix)->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $core = Order::createTrusted([
            'number' => 'W336-'.$suffix,
            'email' => 'buyer336@example.com',
            'status' => 'completed',
            'payment_status' => 'paid',
            'currency_code' => 'USD',
            'subtotal' => 100,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'grand_total' => 100,
            'billing_address' => ['line1' => '1 Test St'],
            'shipping_address' => ['line1' => '1 Test St'],
        ]);
        $sellerOrder = SellerOrder::createTrusted([
            'seller_id' => $seller->id,
            'order_id' => $core->id,
            'number' => 'SO-W336-'.$suffix,
            'customer_email' => 'buyer336@example.com',
            'currency_code' => 'USD',
            'status' => 'completed',
            'payment_status' => 'paid',
            'subtotal' => 100,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'commission_total' => 10,
            'earning_total' => 90,
            'grand_total' => 100,
            'billing_address' => ['line1' => '1 Test St'],
            'shipping_address' => ['line1' => '1 Test St'],
        ]);

        $this->flushSession();
        auth('admin')->logout();
        $this->actingAs($owner, 'customer');
        $req = $this->postJson('/api/v1/account/seller/orders/'.$sellerOrder->id.'/request-payment');
        $req->assertOk();
        $this->assertSame(SellerPaymentRequestService::STATUS_REQUESTED, (string) ($req->json('data.sellerPayoutStatus') ?? ''));

        $this->flushSession();
        auth('customer')->logout();
        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketplace/payment-requests?status=requested');
        $list->assertOk();
        $this->assertGreaterThanOrEqual(1, (int) ($list->json('meta.payableOrders') ?? 0));

        $pay = $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/payment-requests/'.$sellerOrder->id.'/pay',
            ['comment' => 'Bank ref '.$suffix]
        );
        $pay->assertOk();
        $this->assertSame(SellerPaymentRequestService::STATUS_PAID, (string) ($pay->json('data.status') ?? ''));
        $this->assertSame(
            SellerPaymentRequestService::STATUS_PAID,
            SellerOrder::query()->find($sellerOrder->id)?->seller_payout_status
        );
    }
}
