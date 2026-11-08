<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Warehouse;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave194Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_settings_warehouses_rest(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtoupper(substr(uniqid(), -4));
        $code = 'W194'.$suffix;

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/settings/warehouses', [
            'code' => $code,
            'name' => 'Wave194 Warehouse '.$suffix,
            'email' => 'w194-'.$suffix.'@example.test',
            'contact_number' => '555-0194',
            'street' => '100 Test Ave',
            'city' => 'Austin',
            'state' => 'TX',
            'postcode' => '78701',
            'country' => 'US',
            'status' => 1,
        ]);
        $create->assertCreated();
        $id = (int) $create->json('id');
        $this->assertGreaterThan(0, $id);
        $this->assertSame($code, (string) $create->json('code'));
        $this->assertSame('US', (string) $create->json('country'));

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/settings/warehouses?code='.$code);
        $list->assertOk();
        $this->assertNotEmpty($list->json('data'));
        $this->assertArrayHasKey('currentPage', $list->json('meta'));

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/settings/warehouses/'.$id);
        $show->assertOk();
        $this->assertSame('Austin', (string) $show->json('city'));

        $update = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/settings/warehouses/'.$id, [
            'name' => 'Wave194 Renamed '.$suffix,
            'status' => 0,
        ]);
        $update->assertOk();
        $this->assertSame('Wave194 Renamed '.$suffix, (string) $update->json('name'));
        $this->assertSame(0, (int) $update->json('status'));

        $default = Warehouse::query()->where('is_default', true)->first();
        if ($default) {
            $deny = $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/settings/warehouses/'.$default->id);
            $deny->assertStatus(422);
        }

        $del = $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/settings/warehouses/'.$id);
        $del->assertOk();
        $this->assertFalse(Warehouse::query()->whereKey($id)->exists());

        $gql = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ adminSettingsWarehouses(limit: 5) { id code name } }',
        ]);
        $gql->assertOk();
        $this->assertArrayNotHasKey('errors', $gql->json() ?? [], json_encode($gql->json()));
    }
}
