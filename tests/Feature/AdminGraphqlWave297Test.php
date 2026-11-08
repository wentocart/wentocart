<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerEarning;
use App\Domain\Marketplace\Models\SellerWithdrawal;
use App\Models\Admin;

class AdminGraphqlWave297Test extends MarketplaceTestCase
{
    public function test_rest_admin_marketplace_payouts_and_earnings_rest(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave297 Seller',
            'email' => 'w297-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $customer = Customer::query()->where('email', 'w297-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($customer, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave297 Shop '.$suffix,
            'shop_slug' => 'w297-shop-'.$suffix,
            'profile_name' => 'Wave297 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0297',
            'email' => 'w297-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0297',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave297 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w297-shop-'.$suffix)->firstOrFail();

        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $earning = SellerEarning::query()->create([
            'seller_id' => $seller->id,
            'gross_amount' => 100,
            'commission_amount' => 10,
            'commission_rate' => 10,
            'net_amount' => 90,
            'status' => SellerEarning::STATUS_PENDING,
        ]);

        $earnList = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketplace/earnings?seller_id='.$seller->id);
        $earnList->assertOk();
        $this->assertNotEmpty($earnList->json('data'));

        $status = $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/earnings/'.$earning->id.'/status',
            ['status' => 'available']
        );
        $status->assertOk();
        $this->assertSame('available', (string) ($status->json('data.status') ?? ''));

        $payout = SellerWithdrawal::createTrusted([
            'seller_id' => $seller->id,
            'amount' => 25,
            'status' => SellerWithdrawal::STATUS_REQUESTED,
            'note' => 'Wave297 payout',
        ]);

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketplace/payouts?seller_id='.$seller->id);
        $list->assertOk();
        $this->assertNotEmpty($list->json('data'));

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketplace/payouts/'.$payout->id);
        $show->assertOk();
        $this->assertSame(25.0, (float) ($show->json('data.amount') ?? 0));

        $review = $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/payouts/'.$payout->id.'/process',
            ['action' => 'review']
        );
        $review->assertOk();
        $this->assertSame(SellerWithdrawal::STATUS_UNDER_REVIEW, $payout->fresh()->status);

        // Empty store path still validates seller_id
        $bad = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketplace/payouts', [
            'seller_id' => 999999,
            'amount' => 10,
        ]);
        $bad->assertStatus(422);
    }
}
