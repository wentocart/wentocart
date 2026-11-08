<?php

namespace Tests\Feature;

use App\Domain\Promo\Models\DiscountRule;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave184Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_marketing_catalog_rules_rest_rest_compat(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketing/discount-rules/catalog', [
            'name' => 'Wave184 Catalog '.$suffix,
            'stores' => [1],
            'customer_groups' => [1],
            'action_type' => 'by_percent',
            'discount_amount' => 10,
            'status' => 1,
            'sort_order' => 5,
        ]);
        $create->assertCreated();
        $id = (int) $create->json('id');
        $this->assertGreaterThan(0, $id);
        $this->assertSame('catalog', (string) $create->json('apply_as'));
        $this->assertSame('by_percent', (string) $create->json('action_type'));
        $this->assertEquals(10.0, (float) $create->json('discount_amount'));

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketing/discount-rules/catalog?name=Wave184%20Catalog%20'.$suffix);
        $list->assertOk();
        $this->assertNotEmpty($list->json('data'));
        $this->assertArrayHasKey('currentPage', $list->json('meta'));

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketing/discount-rules/catalog/'.$id);
        $show->assertOk();
        $this->assertArrayHasKey('conditions', $show->json());

        $update = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/marketing/discount-rules/catalog/'.$id, [
            'action_type' => 'by_fixed',
            'discount_amount' => 3.5,
            'status' => 0,
        ]);
        $update->assertOk();
        $this->assertSame('by_fixed', (string) $update->json('action_type'));
        $this->assertEquals(3.5, (float) $update->json('discount_amount'));
        $this->assertSame(0, (int) $update->json('status'));

        $bad = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketing/discount-rules/catalog', [
            'name' => 'bad',
            'action_type' => 'by_percent',
            'discount_amount' => 101,
        ]);
        $bad->assertStatus(422);

        $extra = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketing/discount-rules/catalog', [
            'name' => 'Wave184 Extra '.$suffix,
            'action_type' => 'by_percent',
            'discount_amount' => 5,
            'stores' => [1],
            'customer_groups' => [1],
        ]);
        $extra->assertCreated();
        $extraId = (int) $extra->json('id');

        $mass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketing/discount-rules/catalog/bulk-delete', [
            'ids' => [$extraId, 999999],
        ]);
        $mass->assertOk();
        $this->assertContains($extraId, $mass->json('deleted'));

        $del = $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/marketing/discount-rules/catalog/'.$id);
        $del->assertOk();
        $this->assertFalse(DiscountRule::query()->whereKey($id)->exists());

        $gql = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation { createAdminMarketingCatalogDiscountRule(name: "W184 GQL '.$suffix.'", value: 7) { id apply_as } }',
        ]);
        $gql->assertOk();
        $this->assertArrayNotHasKey('errors', $gql->json() ?? [], json_encode($gql->json()));
    }
}
