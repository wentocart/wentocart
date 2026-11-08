<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerReview;
use App\Models\Admin;

class AdminGraphqlWave299Test extends MarketplaceTestCase
{
    public function test_rest_admin_marketplace_seller_reviews_rest(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave299 Seller',
            'email' => 'w299-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $owner = Customer::query()->where('email', 'w299-'.$suffix.'@example.test')->firstOrFail();
        $this->actingAs($owner, 'customer')->postJson('/api/v1/account/seller/apply', [
            'shop_name' => 'Wave299 Shop '.$suffix,
            'shop_slug' => 'w299-shop-'.$suffix,
            'profile_name' => 'Wave299 Profile '.$suffix,
            'first_name' => 'Wave',
            'last_name' => 'Seller',
            'phone' => '555-0299',
            'email' => 'w299-'.$suffix.'@example.test',
            'contact_name' => 'Wave Seller',
            'contact_phone' => '555-0299',
            'address_line1' => '1 Test St',
            'city' => 'Austin',
            'postal_code' => '78701',
            'country_code' => 'US',
            'description' => 'Wave299 seller',
        ])->assertSuccessful();
        $seller = Seller::query()->where('shop_slug', 'w299-shop-'.$suffix)->firstOrFail();

        $this->flushSession();
        auth('customer')->logout();
        $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/sellers/'.$seller->id.'/review',
            ['action' => 'approve', 'commission_rate' => 10]
        )->assertOk();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Wave299 Buyer',
            'email' => 'w299b-'.$suffix.'@example.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertOk();
        $buyer = Customer::query()->where('email', 'w299b-'.$suffix.'@example.test')->firstOrFail();

        $this->flushSession();
        auth('customer')->logout();

        $review = SellerReview::query()->create([
            'seller_id' => $seller->id,
            'customer_id' => $buyer->id,
            'rating' => 5,
            'title' => 'Great shop '.$suffix,
            'body' => 'Fast shipping and friendly.',
            'status' => SellerReview::STATUS_PENDING,
            'is_verified_purchase' => true,
        ]);
        $review2 = SellerReview::query()->create([
            'seller_id' => $seller->id,
            'customer_id' => $owner->id,
            'rating' => 2,
            'title' => 'Meh '.$suffix,
            'body' => 'Slow.',
            'status' => SellerReview::STATUS_PENDING,
            'is_verified_purchase' => false,
        ]);

        $list = $this->actingAs($admin, 'admin')->getJson(
            '/api/v1/console/marketplace/reviews?seller_id='.$seller->id.'&status=pending'
        );
        $list->assertOk();
        $this->assertGreaterThanOrEqual(2, (int) ($list->json('meta.total') ?? 0));

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketplace/reviews/'.$review->id);
        $show->assertOk();
        $this->assertSame(5, (int) ($show->json('data.rating') ?? 0));

        $approve = $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketplace/reviews/'.$review->id.'/moderate',
            ['action' => 'approve']
        );
        $approve->assertOk();
        $this->assertSame(SellerReview::STATUS_APPROVED, $review->fresh()->status);

        $mass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketplace/reviews/bulk-update', [
            'ids' => [$review2->id],
            'action' => 'reject',
        ]);
        $mass->assertOk();
        $this->assertSame(SellerReview::STATUS_REJECTED, $review2->fresh()->status);

        $del = $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/marketplace/reviews/'.$review2->id);
        $del->assertOk();
        $this->assertNull(SellerReview::query()->find($review2->id));
    }
}
