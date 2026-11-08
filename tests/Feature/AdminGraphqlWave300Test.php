<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerMessage;
use App\Models\Admin;

class AdminGraphqlWave300Test extends MarketplaceTestCase
{
    public function test_rest_admin_marketplace_communications_rest(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave300 Seller',
            'email' => 'w300-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w300-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave300 Shop '.$suffix,
            'shop_slug' => 'w300-shop-'.$suffix,
            'profile_name' => 'Wave300 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0300',
            'email' => 'w300-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0300',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave300 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w300-shop-'.$suffix)->firstOrFail();

        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $msg = SellerMessage::query()->create([
            'seller_id' => $seller->id,
            'customer_id' => $owner->id,
            'name' => 'Buyer '.$suffix,
            'email' => 'buyer-'.$suffix.'@example.test',
            'body' => 'When will my order ship?',
            'is_read' => false,
        ]);
        $msg2 = SellerMessage::query()->create([
            'seller_id' => $seller->id,
            'name' => 'Other '.$suffix,
            'email' => 'other-'.$suffix.'@example.test',
            'body' => 'Do you stock blue?',
            'is_read' => false,
        ]);

        $list = $this->actingAs($admin, 'admin')->getJson(
            '/api/v1/console/marketplace/communications?seller_id='.$seller->id.'&status=open'
        );
        $list->assertOk();
        $this->assertGreaterThanOrEqual(2, (int) ($list->json('meta.total') ?? 0));

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketplace/communications/'.$msg->id);
        $show->assertOk();
        $this->assertSame(1, (int) ($show->json('data.isRead') ?? 0));
        $this->assertStringContainsString('ship', (string) ($show->json('data.body') ?? ''));

        $reply = $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/communications/'.$msg->id.'/reply',
            ['reply_body' => 'Ships tomorrow morning.']
        );
        $reply->assertOk();
        $this->assertSame('replied', (string) ($reply->json('data.status') ?? ''));
        $this->assertNotNull($msg->fresh()->replied_at);

        $del = $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/marketplace/communications/'.$msg->id);
        $del->assertOk();
        $this->assertNull(SellerMessage::query()->find($msg->id));

        $clear = $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/communications/clear-history',
            ['seller_id' => $seller->id]
        );
        $clear->assertOk();
        $this->assertSame(0, SellerMessage::query()->where('seller_id', $seller->id)->count());
        $this->assertNull(SellerMessage::query()->find($msg2->id));
    }
}
