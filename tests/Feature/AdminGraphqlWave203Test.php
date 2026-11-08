<?php

namespace Tests\Feature;

use App\Domain\Order\Models\ReturnReason;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave203Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_rma_reasons_rest_rest_compat(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/rma/reasons', [
            'name' => 'Wave203 Reason '.$suffix,
            'status' => 1,
            'position' => 40,
        ]);
        $create->assertCreated();
        $id = (int) $create->json('id');
        $this->assertGreaterThan(0, $id);
        $this->assertSame(1, (int) $create->json('status'));
        $this->assertNotSame('', (string) $create->json('code'));

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/rma/reasons?q=Wave203%20Reason%20'.$suffix);
        $list->assertOk();
        $this->assertNotEmpty($list->json('data'));
        $this->assertArrayHasKey('currentPage', $list->json('meta'));

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/rma/reasons/'.$id);
        $show->assertOk();
        $this->assertSame('Wave203 Reason '.$suffix, (string) $show->json('name'));

        $update = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/rma/reasons/'.$id, [
            'name' => 'Wave203 Renamed '.$suffix,
            'status' => 0,
        ]);
        $update->assertOk();
        $this->assertSame('Wave203 Renamed '.$suffix, (string) $update->json('name'));
        $this->assertSame(0, (int) $update->json('status'));

        $massStatus = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/rma/reasons/bulk-update-status', [
            'ids' => [$id],
            'status' => 1,
        ]);
        $massStatus->assertOk();
        $this->assertTrue((bool) $massStatus->json('ok'));

        $extra = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/rma/reasons', [
            'name' => 'Wave203 Extra '.$suffix,
        ]);
        $extra->assertCreated();
        $extraId = (int) $extra->json('id');

        $mass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/rma/reasons/bulk-delete', [
            'ids' => [$extraId, 999999],
        ]);
        $mass->assertOk();
        $this->assertContains($extraId, $mass->json('deleted'));

        $del = $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/rma/reasons/'.$id);
        $del->assertOk();
        $this->assertTrue((bool) $del->json('ok'));
        $this->assertFalse(ReturnReason::query()->whereKey($id)->exists());

        $alias = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/settings/return-reasons');
        $alias->assertOk();

        $gql = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ adminSettingsReturnReasons(limit: 5) { id name status } }',
        ]);
        $gql->assertOk();
        $this->assertArrayNotHasKey('errors', $gql->json() ?? [], json_encode($gql->json()));
    }
}
