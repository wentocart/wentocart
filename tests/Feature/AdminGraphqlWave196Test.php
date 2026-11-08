<?php

namespace Tests\Feature;

use App\Domain\Auth\Models\Role;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave196Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_settings_users_rest_rest_compat(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));
        $role = Role::query()->create([
            'name' => 'Wave196 Role '.$suffix,
            'slug' => 'wave196-role-'.$suffix,
        ]);

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/settings/users', [
            'name' => 'Wave196 Desk '.$suffix,
            'email' => 'wave196-'.$suffix.'@example.test',
            'password' => 'password123',
            'roleId' => $role->id,
            'status' => 1,
        ]);
        $create->assertCreated();
        $id = (int) $create->json('id');
        $this->assertGreaterThan(0, $id);
        $this->assertSame(1, (int) $create->json('status'));
        $this->assertSame((int) $role->id, (int) $create->json('roleId'));
        $this->assertArrayNotHasKey('password', $create->json() ?? []);

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/settings/users?email=wave196-'.$suffix);
        $list->assertOk();
        $this->assertNotEmpty($list->json('data'));
        $this->assertArrayHasKey('currentPage', $list->json('meta'));

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/settings/users/'.$id);
        $show->assertOk();
        $this->assertSame('wave196-'.$suffix.'@example.test', (string) $show->json('email'));

        $update = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/settings/users/'.$id, [
            'name' => 'Wave196 Updated '.$suffix,
            'status' => 0,
        ]);
        $update->assertOk();
        $this->assertSame('Wave196 Updated '.$suffix, (string) $update->json('name'));
        $this->assertSame(0, (int) $update->json('status'));

        $selfDeny = $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/settings/users/'.$admin->id);
        $selfDeny->assertStatus(422);

        $extra = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/settings/users', [
            'name' => 'Wave196 Extra '.$suffix,
            'email' => 'wave196-extra-'.$suffix.'@example.test',
            'password' => 'password123',
            'roleId' => $role->id,
        ]);
        $extra->assertCreated();
        $extraId = (int) $extra->json('id');

        $mass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/settings/users/bulk-delete', [
            'ids' => [$extraId, 999999],
        ]);
        $mass->assertOk();
        $this->assertContains($extraId, $mass->json('deleted'));

        $del = $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/settings/users/'.$id);
        $del->assertOk();
        $this->assertSame('Admin user deleted successfully.', (string) $del->json('message'));
        $this->assertFalse(Admin::query()->whereKey($id)->exists());

        $gql = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ adminSettingsUsers(limit: 5) { id name email status } }',
        ]);
        $gql->assertOk();
        $this->assertArrayNotHasKey('errors', $gql->json() ?? [], json_encode($gql->json()));
    }
}
