<?php

namespace Tests\Feature;

use App\Domain\Order\Models\ReturnStatus;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave200Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_rma_statuses_rest_rest_compat(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/rma/statuses', [
            'title' => 'Wave200 Status '.$suffix,
            'color' => '#FDB022',
            'status' => 1,
            'position' => 50,
        ]);
        $create->assertCreated();
        $id = (int) $create->json('id');
        $this->assertGreaterThan(0, $id);
        $this->assertSame(1, (int) $create->json('status'));
        $this->assertSame(0, (int) $create->json('default'));

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/rma/statuses?q=Wave200%20Status%20'.$suffix);
        $list->assertOk();
        $this->assertNotEmpty($list->json('data'));
        $this->assertArrayHasKey('currentPage', $list->json('meta'));

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/rma/statuses/'.$id);
        $show->assertOk();
        $this->assertSame('Wave200 Status '.$suffix, (string) $show->json('title'));

        $update = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/rma/statuses/'.$id, [
            'title' => 'Wave200 Renamed '.$suffix,
            'status' => 0,
        ]);
        $update->assertOk();
        $this->assertSame('Wave200 Renamed '.$suffix, (string) $update->json('title'));
        $this->assertSame(0, (int) $update->json('status'));

        $massStatus = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/rma/statuses/bulk-update-status', [
            'ids' => [$id],
            'status' => 1,
        ]);
        $massStatus->assertOk();
        $this->assertTrue((bool) $massStatus->json('ok'));

        $default = ReturnStatus::query()->where('is_default', true)->first();
        if ($default) {
            $deny = $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/rma/statuses/'.$default->id);
            $deny->assertStatus(422);
        }

        $extra = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/rma/statuses', [
            'title' => 'Wave200 Extra '.$suffix,
            'color' => '#111827',
        ]);
        $extra->assertCreated();
        $extraId = (int) $extra->json('id');

        $mass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/rma/statuses/bulk-delete', [
            'ids' => [$extraId, 999999],
        ]);
        $mass->assertOk();
        $this->assertContains($extraId, $mass->json('deleted'));

        $del = $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/rma/statuses/'.$id);
        $del->assertOk();
        $this->assertSame('Return status deleted.', (string) $del->json('message'));
        $this->assertFalse(ReturnStatus::query()->whereKey($id)->exists());

        $alias = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/settings/return-statuses');
        $alias->assertOk();

        $gql = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ adminRmaStatuses(limit: 5) { id title status } }',
        ]);
        $gql->assertOk();
        $this->assertArrayNotHasKey('errors', $gql->json() ?? [], json_encode($gql->json()));
    }
}
