<?php

namespace Tests\Feature;

use App\Domain\Promo\Models\DiscountRule;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave185Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_marketing_cart_rule_coupons_rest_rest_compat(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $rule = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketing/discount-rules/cart', [
            'name' => 'Wave185 Parent '.$suffix,
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

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketing/discount-rules/cart/'.$discountRuleId.'/coupons', [
            'code' => 'W185-'.$suffix,
            'usage_limit' => 25,
            'usage_per_customer' => 2,
        ]);
        $create->assertCreated();
        $couponId = (int) $create->json('id');
        $this->assertSame('W185-'.strtoupper($suffix), (string) $create->json('code'));
        $this->assertSame($discountRuleId, (int) $create->json('discount_rule_id'));
        $this->assertFalse((bool) $create->json('is_primary'));
        $this->assertSame(25, (int) $create->json('usage_limit'));

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketing/discount-rules/cart/'.$discountRuleId.'/coupons');
        $list->assertOk();
        $this->assertNotEmpty($list->json('data'));
        $this->assertArrayHasKey('currentPage', $list->json('meta'));

        $gen = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketing/discount-rules/cart/'.$discountRuleId.'/coupons/generate', [
            'length' => 8,
            'format' => 'alphanumeric',
            'prefix' => 'G',
            'suffix' => 'X',
            'coupon_qty' => 3,
        ]);
        $gen->assertOk();
        $this->assertSame(3, (int) $gen->json('generated'));
        $this->assertCount(3, $gen->json('codes') ?? []);
        $this->assertCount(3, $gen->json('coupons') ?? []);

        $dup = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketing/discount-rules/cart/'.$discountRuleId.'/coupons', [
            'code' => 'W185-'.$suffix,
        ]);
        $dup->assertStatus(422);

        $extraIds = collect($gen->json('coupons'))->pluck('id')->map(fn ($id) => (int) $id)->take(2)->all();
        $mass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketing/discount-rules/cart/'.$discountRuleId.'/coupons/bulk-delete', [
            'ids' => array_merge($extraIds, [999999]),
        ]);
        $mass->assertOk();
        $this->assertGreaterThanOrEqual(2, (int) $mass->json('updated_count'));

        $del = $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/marketing/discount-rules/cart/'.$discountRuleId.'/coupons/'.$couponId);
        $del->assertOk();
        $this->assertFalse(DiscountRule::query()->whereKey($couponId)->exists());

        $gql = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ adminMarketingCartDiscountRuleCoupons(discountRuleId: "'.$discountRuleId.'", limit: 5) { id code discountRuleId } }',
        ]);
        $gql->assertOk();
        $this->assertArrayNotHasKey('errors', $gql->json() ?? [], json_encode($gql->json()));
    }
}
