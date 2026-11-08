<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerOrder;
use App\Domain\Marketplace\Models\SellerReview;
use App\Domain\Marketplace\Services\SellerAccountHealthService;
use App\Domain\Order\Models\Order;
use App\Models\Admin;

class AdminGraphqlWave304Test extends MarketplaceTestCase
{
    public function test_rest_admin_marketplace_account_health_rest(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave304 Seller',
            'email' => 'w304-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w304-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave304 Shop '.$suffix,
            'shop_slug' => 'w304-shop-'.$suffix,
            'profile_name' => 'Wave304 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0304',
            'email' => 'w304-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0304',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave304 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w304-shop-'.$suffix)->firstOrFail();

        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave304 Buyer',
            'email' => 'w304b-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $buyer = Customer::query()->where('email', 'w304b-'.$suffix.'@example.test')->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();

        for ($i = 0; $i < 4; $i++) {
            $order = Order::createTrusted([
                'number' => 'W304-'.$suffix.'-'.$i,
                'customer_id' => $buyer->id,
                'email' => $buyer->email,
                'status' => 'processing',
                'currency_code' => 'USD',
                'subtotal' => 20,
                'tax_total' => 0,
                'shipping_total' => 0,
                'discount_total' => 0,
                'grand_total' => 20,
                'payment_status' => 'paid',
                'billing_address' => ['line1' => '1 Test'],
                'shipping_address' => ['line1' => '1 Test'],
            ]);
            SellerOrder::createTrusted([
                'order_id' => $order->id,
                'seller_id' => $seller->id,
                'number' => 'SO-W304-'.$suffix.'-'.$i,
                'customer_email' => $buyer->email,
                'status' => 'completed',
                'payment_status' => 'paid',
                'currency_code' => 'USD',
                'subtotal' => 20,
                'tax_total' => 0,
                'shipping_total' => 0,
                'discount_total' => 0,
                'commission_total' => 2,
                'earning_total' => 18,
                'grand_total' => 20,
            ]);
        }

        SellerReview::query()->create([
            'seller_id' => $seller->id,
            'customer_id' => $buyer->id,
            'rating' => 5,
            'title' => 'Great',
            'body' => 'Loved it',
            'status' => SellerReview::STATUS_APPROVED,
            'is_verified_purchase' => true,
        ]);

        $refresh = $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/account-health/refresh'
        );
        $refresh->assertOk();
        $this->assertSame(SellerAccountHealthService::BAND_HEALTHY, (string) ($refresh->json('data.band') ?? ''));
        $this->assertGreaterThanOrEqual(67, (int) ($refresh->json('data.score') ?? 0));
        $this->assertSame(0.0, (float) ($refresh->json('data.metrics.cancellation.rate') ?? -1));
        $this->assertSame(5.0, (float) ($refresh->json('data.metrics.feedback.average') ?? 0));

        // Critical cancellation path (above 5%) must mark Unhealthy (worst-metric rule).
        $badOrder = Order::createTrusted([
            'number' => 'W304-bad-'.$suffix,
            'customer_id' => $buyer->id,
            'email' => $buyer->email,
            'status' => 'cancelled',
            'currency_code' => 'USD',
            'subtotal' => 20,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'grand_total' => 20,
            'payment_status' => 'pending',
            'billing_address' => ['line1' => '1 Test'],
            'shipping_address' => ['line1' => '1 Test'],
        ]);
        SellerOrder::createTrusted([
            'order_id' => $badOrder->id,
            'seller_id' => $seller->id,
            'number' => 'SO-W304-bad-'.$suffix,
            'customer_email' => $buyer->email,
            'status' => 'cancelled',
            'payment_status' => 'pending',
            'currency_code' => 'USD',
            'subtotal' => 20,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'commission_total' => 0,
            'earning_total' => 0,
            'grand_total' => 20,
        ]);
        // Make cancellation rate critical: wipe good orders conceptually by adding many cancels
        for ($i = 0; $i < 5; $i++) {
            $o = Order::createTrusted([
                'number' => 'W304-c-'.$suffix.'-'.$i,
                'customer_id' => $buyer->id,
                'email' => $buyer->email,
                'status' => 'cancelled',
                'currency_code' => 'USD',
                'subtotal' => 10,
                'tax_total' => 0,
                'shipping_total' => 0,
                'discount_total' => 0,
                'grand_total' => 10,
                'payment_status' => 'pending',
                'billing_address' => ['line1' => '1 Test'],
                'shipping_address' => ['line1' => '1 Test'],
            ]);
            SellerOrder::createTrusted([
                'order_id' => $o->id,
                'seller_id' => $seller->id,
                'number' => 'SO-W304-c-'.$suffix.'-'.$i,
                'customer_email' => $buyer->email,
                'status' => 'cancelled',
                'payment_status' => 'pending',
                'currency_code' => 'USD',
                'subtotal' => 10,
                'tax_total' => 0,
                'shipping_total' => 0,
                'discount_total' => 0,
                'commission_total' => 0,
                'earning_total' => 0,
                'grand_total' => 10,
            ]);
        }
        $bad = $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/account-health/refresh'
        );
        $bad->assertOk();
        $this->assertSame(SellerAccountHealthService::BAND_UNHEALTHY, (string) ($bad->json('data.band') ?? ''));

        $show = $this->actingAs($admin, 'admin')->getJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/account-health'
        );
        $show->assertOk();
        $this->assertTrue((bool) $show->json('data.cached'));

        $mass = $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/account-health/bulk-refresh',
            ['ids' => [$seller->id]]
        );
        $mass->assertOk();
        $this->assertSame(1, (int) ($mass->json('data.refreshed') ?? 0));
    }
}
