<?php

namespace Tests\Feature;

use App\Domain\Auth\Models\Role;
use App\Domain\Auth\PageCatalog;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave195Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_settings_roles_rest_rest_compat(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));
        $slugs = PageCatalog::slugs();
        $this->assertNotEmpty($slugs);
        $perm = $slugs[0];

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/settings/roles', [
            'name' => 'Wave195 Role '.$suffix,
            'permission_type' => 'custom',
            'permissions' => [$perm],
        ]);
        $create->assertCreated();
        $id = (int) $create->json('id');
        $this->assertGreaterThan(0, $id);
        $this->assertSame('custom', (string) $create->json('permission_type'));
        $this->assertContains($perm, $create->json('permissions') ?? []);

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/settings/roles?name=Wave195%20Role%20'.$suffix);
        $list->assertOk();
        $this->assertNotEmpty($list->json('data'));
        $this->assertArrayHasKey('currentPage', $list->json('meta'));

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/settings/roles/'.$id);
        $show->assertOk();
        $this->assertSame('Wave195 Role '.$suffix, (string) $show->json('name'));

        $update = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/settings/roles/'.$id, [
            'name' => 'Wave195 Renamed '.$suffix,
        ]);
        $update->assertOk();
        $this->assertSame('Wave195 Renamed '.$suffix, (string) $update->json('name'));

        $system = Role::query()->where('slug', 'super_admin')->first();
        if ($system) {
            $deny = $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/settings/roles/'.$system->id);
            $deny->assertStatus(422);
        }

        $extra = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/settings/roles', [
            'name' => 'Wave195 Extra '.$suffix,
            'permission_type' => 'custom',
            'permissions' => [$perm],
        ]);
        $extra->assertCreated();
        $extraId = (int) $extra->json('id');

        $mass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/settings/roles/bulk-delete', [
            'ids' => [$extraId, 999999],
        ]);
        $mass->assertOk();
        $this->assertContains($extraId, $mass->json('deleted'));

        $del = $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/settings/roles/'.$id);
        $del->assertOk();
        $this->assertFalse(Role::query()->whereKey($id)->exists());

        $gql = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ adminSettingsRoles(limit: 5) { id name permissionType } }',
        ]);
        $gql->assertOk();
        $this->assertArrayNotHasKey('errors', $gql->json() ?? [], json_encode($gql->json()));
    }
}
