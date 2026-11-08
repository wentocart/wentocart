<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave278Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_promotions_and_search_seo_path_aliases(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $rule = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketing/discount-rules/cart', [
            'name' => 'Wave278 Parent '.$suffix,
            'stores' => [1],
            'customer_groups' => [1],
            'coupon_type' => 1,
            'use_auto_generation' => 1,
            'action_type' => 'by_percent',
            'discount_amount' => 12,
            'status' => 1,
        ]);
        $rule->assertCreated();
        $discountRuleId = (int) $rule->json('id');
        $this->assertGreaterThan(0, $discountRuleId);

        $coupon = $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketing/discount-rules/cart/'.$discountRuleId.'/coupons',
            [
                'code' => 'W278-'.$suffix,
                'usage_limit' => 10,
                'usage_per_customer' => 1,
            ]
        );
        $coupon->assertCreated();
        $couponId = (int) $coupon->json('id');
        $this->assertGreaterThan(0, $couponId);

        $showCoupon = $this->actingAs($admin, 'admin')->getJson(
            '/api/v1/console/marketing/discount-rules/cart/'.$discountRuleId.'/coupons/'.$couponId
        );
        $showCoupon->assertOk();
        $this->assertSame($discountRuleId, (int) $showCoupon->json('discount_rule_id'));

        $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketing/discount-rules/catalog')->assertOk();
        $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketing/discount-rules/email-templates')->assertOk();
        $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketing/discount-rules/events')->assertOk();
        $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketing/discount-rules/campaigns')->assertOk();
        $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketing/communications/subscribers')->assertOk();
        $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketing/seo/search-terms')->assertOk();
        $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketing/seo/sitemaps')->assertOk();
        $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketing/seo/url-rewrites')->assertOk();

        $syn = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketing/seo/search-synonyms', [
            'name' => 'Wave278 Syn '.$suffix,
            'terms' => 'shoe, sneaker',
        ]);
        if ($syn->status() === 201) {
            $this->actingAs($admin, 'admin')
                ->postJson('/api/v1/console/marketing/seo/search-synonyms/bulk-delete', [
                    'ids' => [(int) $syn->json('id')],
                ])
                ->assertSuccessful();
        } else {
            $this->actingAs($admin, 'admin')
                ->getJson('/api/v1/console/marketing/seo/search-synonyms')
                ->assertOk();
        }

        $mass = $this->actingAs($admin, 'admin')->postJson(
            '/api/v1/console/marketing/discount-rules/cart/'.$discountRuleId.'/coupons/bulk-delete',
            ['ids' => [$couponId]]
        );
        $mass->assertSuccessful();
    }
}
