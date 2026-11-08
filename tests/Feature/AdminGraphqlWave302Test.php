<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\Models\MarketplaceCommissionRule;
use App\Domain\Marketplace\Models\Seller;
use App\Models\Admin;

class AdminGraphqlWave302Test extends MarketplaceTestCase
{
    public function test_rest_admin_marketplace_commission_rules_rest(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave302 Seller',
            'email' => 'w302-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w302-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave302 Shop '.$suffix,
            'shop_slug' => 'w302-shop-'.$suffix,
            'profile_name' => 'Wave302 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0302',
            'email' => 'w302-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0302',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave302 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w302-shop-'.$suffix)->firstOrFail();

        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketplace/commission-rules', [
            'name' => 'Global '.$suffix,
            'scope' => MarketplaceCommissionRule::SCOPE_GLOBAL,
            'type' => MarketplaceCommissionRule::TYPE_PERCENT,
            'value' => 12.5,
            'is_enabled' => true,
        ]);
        $create->assertCreated();
        $ruleId = (int) $create->json('data.id');

        $sellerRule = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketplace/commission-rules', [
            'name' => 'Seller '.$suffix,
            'scope' => MarketplaceCommissionRule::SCOPE_SELLER,
            'type' => MarketplaceCommissionRule::TYPE_PERCENT,
            'value' => 8,
            'seller_id' => $seller->id,
        ]);
        $sellerRule->assertCreated();

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketplace/commission-rules?scope=global');
        $list->assertOk();
        $this->assertGreaterThanOrEqual(1, (int) ($list->json('meta.total') ?? 0));

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketplace/commission-rules/'.$ruleId);
        $show->assertOk();
        $this->assertSame(12.5, (float) ($show->json('data.value') ?? 0));

        $update = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/marketplace/commission-rules/'.$ruleId, [
            'name' => 'Global '.$suffix.' v2',
            'scope' => MarketplaceCommissionRule::SCOPE_GLOBAL,
            'type' => MarketplaceCommissionRule::TYPE_PERCENT,
            'value' => 15,
            'is_enabled' => false,
        ]);
        $update->assertOk();
        $this->assertSame(0, (int) ($update->json('data.isEnabled') ?? 1));

        $bad = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketplace/commission-rules', [
            'name' => 'Bad',
            'scope' => MarketplaceCommissionRule::SCOPE_SELLER,
            'type' => MarketplaceCommissionRule::TYPE_PERCENT,
            'value' => 5,
        ]);
        $bad->assertStatus(422);

        $del = $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/marketplace/commission-rules/'.$ruleId);
        $del->assertOk();
        $this->assertNull(MarketplaceCommissionRule::query()->find($ruleId));
    }
}
