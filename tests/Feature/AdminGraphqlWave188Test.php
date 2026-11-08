<?php

namespace Tests\Feature;

use App\Domain\Store\Models\Store;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave188Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_settings_channels_rest_rest_compat(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));
        $code = 'w188'.$suffix;

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/settings/stores', [
            'code' => $code,
            'name' => 'Wave188 Channel '.$suffix,
            'hostname' => 'w188-'.$suffix.'.example.test',
            'locales' => [1],
            'currencies' => [1],
            'warehouse_ids' => [1],
            'status' => 1,
        ]);
        $create->assertCreated();
        $id = (int) $create->json('id');
        $this->assertGreaterThan(0, $id);
        $this->assertSame('Wave188 Channel '.$suffix, (string) $create->json('name'));
        $this->assertArrayHasKey('locales', $create->json());

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/settings/stores?code='.$code);
        $list->assertOk();
        $this->assertNotEmpty($list->json('data'));
        $this->assertArrayHasKey('currentPage', $list->json('meta'));

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/settings/stores/'.$id);
        $show->assertOk();
        $this->assertArrayHasKey('warehouses', $show->json());

        $update = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/settings/stores/'.$id, [
            'name' => 'Wave188 Renamed '.$suffix,
            'status' => 0,
        ]);
        $update->assertOk();
        $this->assertSame('Wave188 Renamed '.$suffix, (string) $update->json('name'));
        $this->assertSame(0, (int) $update->json('status'));

        $default = Store::query()->where('is_default', true)->first();
        if ($default) {
            $denyDefault = $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/settings/stores/'.$default->id);
            $denyDefault->assertStatus(422);
        }

        $del = $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/settings/stores/'.$id);
        $del->assertOk();
        $this->assertFalse(Store::query()->whereKey($id)->exists());

        $gql = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ adminSettingsStores(limit: 5) { id code name } }',
        ]);
        $gql->assertOk();
        $this->assertArrayNotHasKey('errors', $gql->json() ?? [], json_encode($gql->json()));
    }
}
