<?php

namespace Tests\Feature;

use App\Domain\Order\Models\ReturnRule;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave201Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_rma_rules_rest_rest_compat(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/rma/rules', [
            'name' => 'Wave201 Rule '.$suffix,
            'description' => 'Wave201 desc',
            'returnPeriod' => 45,
            'status' => 1,
            'default' => 0,
        ]);
        $create->assertCreated();
        $id = (int) $create->json('id');
        $this->assertGreaterThan(0, $id);
        $this->assertSame(45, (int) $create->json('returnPeriod'));
        $this->assertSame(0, (int) $create->json('default'));

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/rma/rules?q=Wave201%20Rule%20'.$suffix);
        $list->assertOk();
        $this->assertNotEmpty($list->json('data'));
        $this->assertArrayHasKey('currentPage', $list->json('meta'));

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/rma/rules/'.$id);
        $show->assertOk();
        $this->assertSame('Wave201 Rule '.$suffix, (string) $show->json('name'));

        $update = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/rma/rules/'.$id, [
            'returnPeriod' => 60,
            'status' => 0,
        ]);
        $update->assertOk();
        $this->assertSame(60, (int) $update->json('returnPeriod'));
        $this->assertSame(0, (int) $update->json('status'));

        $massStatus = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/rma/rules/bulk-update-status', [
            'ids' => [$id],
            'status' => 1,
        ]);
        $massStatus->assertOk();
        $this->assertTrue((bool) $massStatus->json('ok'));

        $extra = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/rma/rules', [
            'name' => 'Wave201 Extra '.$suffix,
            'returnPeriod' => 14,
        ]);
        $extra->assertCreated();
        $extraId = (int) $extra->json('id');

        $mass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/rma/rules/bulk-delete', [
            'ids' => [$extraId, 999999],
        ]);
        $mass->assertOk();
        $this->assertContains($extraId, $mass->json('deleted'));

        $del = $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/rma/rules/'.$id);
        $del->assertOk();
        $this->assertSame('Return rule deleted.', (string) $del->json('message'));
        $this->assertFalse(ReturnRule::query()->whereKey($id)->exists());

        $alias = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/settings/return-rules');
        $alias->assertOk();

        $gql = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ adminRmaRules(limit: 5) { id name returnPeriod } }',
        ]);
        $gql->assertOk();
        $this->assertArrayNotHasKey('errors', $gql->json() ?? [], json_encode($gql->json()));
    }
}
