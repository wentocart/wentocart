<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerMessage;
use App\Domain\Marketplace\SellerCommunicationsSchema;
use App\Models\Admin;

class AdminGraphqlWave351Test extends MarketplaceTestCase
{
    public function test_admin_can_block_and_unblock_seller_communications(): void
    {
        $this->seed();
        SellerCommunicationsSchema::ensure();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave351 Seller',
            'email' => 'w351-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w351-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave351 Shop '.$suffix,
            'shop_slug' => 'w351-shop-'.$suffix,
            'profile_name' => 'Wave351 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0351',
            'email' => 'w351-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0351',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave351 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w351-shop-'.$suffix)->firstOrFail();
        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $msg = SellerMessage::query()->create([
            'seller_id' => $seller->id,
            'name' => 'Buyer',
            'email' => 'buyer351@example.test',
            'body' => 'Hello seller '.$suffix,
        ]);

        $block = $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/communications/sellers/'.$seller->id.'/block'
        );
        $block->assertOk();
        $this->assertTrue((bool) $block->json('data.communicationsBlocked'));

        $reply = $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/communications/'.$msg->id.'/reply',
            ['reply_body' => 'Should fail while blocked']
        );
        $reply->assertStatus(422);
        $this->assertSame('communications_blocked', (string) ($reply->json('error') ?? ''));

        $unblock = $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/communications/sellers/'.$seller->id.'/unblock'
        );
        $unblock->assertOk();

        $ok = $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/communications/'.$msg->id.'/reply',
            ['reply_body' => 'Unblocked reply']
        );
        $ok->assertOk();
    }
}
