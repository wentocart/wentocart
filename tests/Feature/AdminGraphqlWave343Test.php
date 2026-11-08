<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerOrder;
use App\Domain\Marketplace\SellerPaymentRequestSchema;
use App\Domain\Marketplace\Services\SellerPaymentRequestService;
use App\Domain\Order\Models\Order;
use App\Models\Admin;

class AdminGraphqlWave343Test extends MarketplaceTestCase
{
    public function test_admin_marketplace_transactions_lists_paid_requests(): void
    {
        $this->seed();
        SellerPaymentRequestSchema::ensure();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave343 Seller',
            'email' => 'w343-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w343-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave343 Shop '.$suffix,
            'shop_slug' => 'w343-shop-'.$suffix,
            'profile_name' => 'Wave343 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0343',
            'email' => 'w343-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0343',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave343 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w343-shop-'.$suffix)->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $core = Order::createTrusted([
            'number' => 'W343-'.$suffix,
            'email' => 'buyer343@example.com',
            'status' => 'completed',
            'payment_status' => 'paid',
            'currency_code' => 'USD',
            'subtotal' => 40,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'grand_total' => 40,
            'billing_address' => ['line1' => '1 Test St'],
            'shipping_address' => ['line1' => '1 Test St'],
        ]);
        $sellerOrder = SellerOrder::createTrusted([
            'seller_id' => $seller->id,
            'order_id' => $core->id,
            'number' => 'SO-W343-'.$suffix,
            'customer_email' => 'buyer343@example.com',
            'currency_code' => 'USD',
            'status' => 'completed',
            'payment_status' => 'paid',
            'subtotal' => 40,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'commission_total' => 4,
            'earning_total' => 36,
            'grand_total' => 40,
            'billing_address' => ['line1' => '1 Test St'],
            'shipping_address' => ['line1' => '1 Test St'],
            'seller_payout_status' => SellerPaymentRequestService::STATUS_PAID,
            'seller_payout_paid_at' => now(),
            'seller_payout_comment' => 'Bank '.$suffix,
        ]);

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketplace/transactions');
        $list->assertOk();
        $ids = collect($list->json('data') ?? [])->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains((int) $sellerOrder->id, $ids);
    }
}
