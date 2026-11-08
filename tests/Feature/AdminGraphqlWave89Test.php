<?php

namespace Tests\Feature;

use App\Domain\Promo\Models\DiscountRule;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave89Test extends TestCase
{
    use RefreshDatabase;

    public function test_copy_cart_rule_and_generate_coupons(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $source = DiscountRule::query()->create([
            'name' => 'Wave89 Source Cart',
            'apply_as' => 'cart',
            'action' => 'percent',
            'value' => 12,
            'priority' => 40,
            'min_cart' => 50,
            'is_enabled' => true,
        ]);

        $copy = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                copyAdminMarketingCartDiscountRule(id: $id) {
                    id name apply_as is_enabled value
                }
            }',
            'variables' => ['id' => (string) $source->id],
        ]);
        $copy->assertOk();
        $this->assertArrayNotHasKey('errors', $copy->json() ?? [], json_encode($copy->json()));
        $this->assertSame('Copy of Wave89 Source Cart', (string) $copy->json('data.copyAdminMarketingCartDiscountRule.name'));
        $this->assertSame('cart', (string) $copy->json('data.copyAdminMarketingCartDiscountRule.apply_as'));
        $this->assertFalse((bool) $copy->json('data.copyAdminMarketingCartDiscountRule.is_enabled'));
        $copyId = (int) $copy->json('data.copyAdminMarketingCartDiscountRule.id');
        $this->assertNotSame((int) $source->id, $copyId);
        $this->assertNotNull(DiscountRule::query()->find($copyId));

        $gen = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                createAdminMarketingCartDiscountRuleCouponGenerate(
                    discountRuleId: $id
                    length: 6
                    format: "alphanumeric"
                    prefix: "W89"
                    couponQty: 3
                ) {
                    ok updated_count codes coupons { id code apply_as value }
                    message
                }
            }',
            'variables' => ['id' => (string) $source->id],
        ]);
        $gen->assertOk();
        $this->assertArrayNotHasKey('errors', $gen->json() ?? [], json_encode($gen->json()));
        $this->assertSame(3, (int) $gen->json('data.createAdminMarketingCartDiscountRuleCouponGenerate.updated_count'));
        $codes = $gen->json('data.createAdminMarketingCartDiscountRuleCouponGenerate.codes') ?? [];
        $this->assertCount(3, $codes);
        foreach ($codes as $code) {
            $this->assertStringStartsWith('W89', (string) $code);
            $row = DiscountRule::query()->where('code', $code)->where('apply_as', 'coupon')->first();
            $this->assertNotNull($row);
            $this->assertEqualsWithDelta(12.0, (float) $row->value, 0.001);
        }
    }
}
