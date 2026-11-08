<?php

namespace Tests\Feature;

use App\Domain\Promo\Models\DiscountRule;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave183Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_marketing_cart_rules_rest_rest_compat(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketing/discount-rules/cart', [
            'name' => 'Wave183 Cart '.$suffix,
            'stores' => [1],
            'customer_groups' => [1],
            'coupon_type' => 0,
            'action_type' => 'by_percent',
            'discount_amount' => 15,
            'status' => 1,
            'sort_order' => 10,
        ]);
        $create->assertCreated();
        $id = (int) $create->json('id');
        $this->assertGreaterThan(0, $id);
        $this->assertSame('by_percent', (string) $create->json('action_type'));
        $this->assertEquals(15.0, (float) $create->json('discount_amount'));
        $this->assertSame(0, (int) $create->json('coupon_type'));

        $coupon = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketing/discount-rules/cart', [
            'name' => 'Wave183 Coupon '.$suffix,
            'stores' => [1],
            'customer_groups' => [1],
            'coupon_type' => 1,
            'use_auto_generation' => 0,
            'coupon_code' => 'W183-'.$suffix,
            'action_type' => 'by_fixed',
            'discount_amount' => 5,
            'status' => 1,
        ]);
        $coupon->assertCreated();
        $this->assertSame(1, (int) $coupon->json('coupon_type'));
        $this->assertSame('W183-'.strtoupper($suffix), (string) $coupon->json('coupon_code'));

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketing/discount-rules/cart?name=Wave183%20Cart%20'.$suffix);
        $list->assertOk();
        $this->assertNotEmpty($list->json('data'));
        $this->assertArrayHasKey('currentPage', $list->json('meta'));

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketing/discount-rules/cart/'.$id);
        $show->assertOk();
        $this->assertSame('Wave183 Cart '.$suffix, (string) $show->json('name'));

        $update = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/marketing/discount-rules/cart/'.$id, [
            'discount_amount' => 20,
            'status' => 0,
        ]);
        $update->assertOk();
        $this->assertEquals(20.0, (float) $update->json('discount_amount'));
        $this->assertSame(0, (int) $update->json('status'));

        $copy = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketing/discount-rules/cart/'.$id.'/copy');
        $copy->assertCreated();
        $copyId = (int) $copy->json('id');
        $this->assertNotSame($id, $copyId);
        $this->assertSame(0, (int) $copy->json('status'));
        $this->assertStringStartsWith('Copy of ', (string) $copy->json('name'));

        $bad = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketing/discount-rules/cart', [
            'name' => 'bad',
            'action_type' => 'by_percent',
            'discount_amount' => 150,
        ]);
        $bad->assertStatus(422);

        $mass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketing/discount-rules/cart/bulk-delete', [
            'ids' => [$copyId, 999999],
        ]);
        $mass->assertOk();
        $this->assertContains($copyId, $mass->json('deleted'));

        $del = $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/marketing/discount-rules/cart/'.$id);
        $del->assertOk();
        $this->assertFalse(DiscountRule::query()->whereKey($id)->exists());

        $gql = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation { createAdminMarketingCartDiscountRule(name: "W183 GQL '.$suffix.'", value: 5) { id apply_as } }',
        ]);
        $gql->assertOk();
        $this->assertArrayNotHasKey('errors', $gql->json() ?? [], json_encode($gql->json()));
    }
}
