<?php

namespace Tests\Feature;

use App\Domain\Promo\Models\DiscountRule;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave139Test extends TestCase
{
    use RefreshDatabase;

    public function test_create_list_delete_admin_marketing_cart_rule_coupon(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $rule = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation {
                createAdminMarketingCartDiscountRule(name: "W139 Parent Cart", value: 12) { id apply_as }
            }',
        ]);
        $rule->assertOk();
        $this->assertArrayNotHasKey('errors', $rule->json() ?? [], json_encode($rule->json()));
        $discountRuleId = (string) $rule->json('data.createAdminMarketingCartDiscountRule.id');
        $this->assertNotSame('', $discountRuleId);

        $code = 'W139-'.strtoupper(substr(uniqid(), -6));
        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($rid: ID!, $code: String!) {
                createAdminMarketingCartDiscountRuleCoupon(input: {
                    discountRuleId: $rid
                    code: $code
                    usageLimit: 25
                    usagePerCustomer: 2
                }) {
                    id
                    id
                    code
                    discountRuleId
                    isPrimary
                    type
                    usageLimit
                    usagePerCustomer
                    timesUsed
                }
            }',
            'variables' => ['rid' => $discountRuleId, 'code' => $code],
        ]);
        $create->assertOk();
        $this->assertArrayNotHasKey('errors', $create->json() ?? [], json_encode($create->json()));
        $this->assertSame($code, (string) $create->json('data.createAdminMarketingCartDiscountRuleCoupon.code'));
        $this->assertSame((int) $discountRuleId, (int) $create->json('data.createAdminMarketingCartDiscountRuleCoupon.discountRuleId'));
        $this->assertFalse((bool) $create->json('data.createAdminMarketingCartDiscountRuleCoupon.isPrimary'));
        $this->assertSame(1, (int) $create->json('data.createAdminMarketingCartDiscountRuleCoupon.type'));
        $this->assertSame(25, (int) $create->json('data.createAdminMarketingCartDiscountRuleCoupon.usageLimit'));
        $this->assertSame(2, (int) $create->json('data.createAdminMarketingCartDiscountRuleCoupon.usagePerCustomer'));
        $couponId = (string) $create->json('data.createAdminMarketingCartDiscountRuleCoupon.id');

        $row = DiscountRule::query()->find((int) $couponId);
        $this->assertNotNull($row);
        $this->assertSame('coupon', (string) $row->apply_as);
        $this->assertSame((int) $discountRuleId, (int) $row->parent_id);

        $list = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($rid: ID!) {
                adminMarketingCartDiscountRuleCoupons(discountRuleId: $rid, first: 10) {
                    id code discountRuleId
                }
            }',
            'variables' => ['rid' => $discountRuleId],
        ]);
        $list->assertOk();
        $this->assertArrayNotHasKey('errors', $list->json() ?? [], json_encode($list->json()));
        $codes = collect($list->json('data.adminMarketingCartDiscountRuleCoupons') ?? [])
            ->pluck('code')
            ->all();
        $this->assertContains($code, $codes);

        $del = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                deleteAdminMarketingCartDiscountRuleCoupon(id: $id) { ok id }
            }',
            'variables' => ['id' => $couponId],
        ]);
        $del->assertOk();
        $this->assertArrayNotHasKey('errors', $del->json() ?? [], json_encode($del->json()));
        $this->assertTrue((bool) $del->json('data.deleteAdminMarketingCartDiscountRuleCoupon.ok'));
        $this->assertNull(DiscountRule::query()->find((int) $couponId));
    }
}
