<?php

namespace Tests\Feature;

use App\Domain\Auth\Models\Role;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave63Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_settings_users_and_roles_rest_aliases(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $roles = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query {
                adminSettingsRoles(limit: 20) { id name permissionType slug }
            }',
        ]);
        $roles->assertOk();
        $this->assertArrayNotHasKey('errors', $roles->json() ?? [], json_encode($roles->json()));
        $this->assertNotEmpty($roles->json('data.adminSettingsRoles'));

        $roleCreate = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation {
                createAdminSettingsRole(
                    name: "Wave63 Catalog Desk"
                    permissionType: "custom"
                    permissions: ["catalog", "settings.manage"]
                ) {
                    id name permissionType permissions
                }
            }',
        ]);
        $roleCreate->assertOk();
        $this->assertArrayNotHasKey('errors', $roleCreate->json() ?? [], json_encode($roleCreate->json()));
        $roleId = (string) $roleCreate->json('data.createAdminSettingsRole.id');
        $this->assertSame('custom', (string) $roleCreate->json('data.createAdminSettingsRole.permissionType'));
        $this->assertContains('catalog', $roleCreate->json('data.createAdminSettingsRole.permissions') ?? []);

        $roleUpdate = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                updateAdminSettingsRole(id: $id, name: "Wave63 Catalog Desk Updated") {
                    id name
                }
            }',
            'variables' => ['id' => $roleId],
        ]);
        $roleUpdate->assertOk();
        $this->assertArrayNotHasKey('errors', $roleUpdate->json() ?? [], json_encode($roleUpdate->json()));
        $this->assertSame('Wave63 Catalog Desk Updated', (string) $roleUpdate->json('data.updateAdminSettingsRole.name'));

        $roleDetail = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($id: ID!) {
                adminSettingsRole(id: $id) { id name permissionType }
            }',
            'variables' => ['id' => $roleId],
        ]);
        $roleDetail->assertOk();
        $this->assertArrayNotHasKey('errors', $roleDetail->json() ?? [], json_encode($roleDetail->json()));
        $this->assertSame($roleId, (string) $roleDetail->json('data.adminSettingsRole.id'));

        $email = 'wave63-'.substr(uniqid(), -6).'@example.test';
        $userCreate = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($roleId: ID!, $email: String!) {
                createAdminSettingsUser(
                    name: "Wave63 Desk"
                    email: $email
                    password: "password123"
                    roleId: $roleId
                    status: 1
                ) {
                    id name email status roleId roleName is_enabled
                }
            }',
            'variables' => ['roleId' => $roleId, 'email' => $email],
        ]);
        $userCreate->assertOk();
        $this->assertArrayNotHasKey('errors', $userCreate->json() ?? [], json_encode($userCreate->json()));
        $userId = (string) $userCreate->json('data.createAdminSettingsUser.id');
        $this->assertSame($roleId, (string) $userCreate->json('data.createAdminSettingsUser.roleId'));
        $this->assertSame(1, (int) $userCreate->json('data.createAdminSettingsUser.status'));

        $users = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($email: String!, $roleId: ID!) {
                adminSettingsUsers(email: $email, role_id: $roleId, status: 1, limit: 10) {
                    id email roleId
                }
                adminSettingsUser(id: "'.$userId.'") { id roleName status }
            }',
            'variables' => ['email' => 'wave63-', 'roleId' => $roleId],
        ]);
        $users->assertOk();
        $this->assertArrayNotHasKey('errors', $users->json() ?? [], json_encode($users->json()));
        $this->assertNotEmpty($users->json('data.adminSettingsUsers'));
        $this->assertSame($userId, (string) $users->json('data.adminSettingsUser.id'));

        $userUpdate = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                updateAdminSettingsUser(id: $id, name: "Wave63 Desk Updated", status: 0) {
                    id name status is_enabled
                }
            }',
            'variables' => ['id' => $userId],
        ]);
        $userUpdate->assertOk();
        $this->assertArrayNotHasKey('errors', $userUpdate->json() ?? [], json_encode($userUpdate->json()));
        $this->assertSame('Wave63 Desk Updated', (string) $userUpdate->json('data.updateAdminSettingsUser.name'));
        $this->assertSame(0, (int) $userUpdate->json('data.updateAdminSettingsUser.status'));

        $delUser = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                deleteAdminSettingsUser(id: $id) { ok id }
            }',
            'variables' => ['id' => $userId],
        ]);
        $delUser->assertOk();
        $this->assertTrue((bool) $delUser->json('data.deleteAdminSettingsUser.ok'));
        $this->assertNull(Admin::query()->find((int) $userId));

        $delRole = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                deleteAdminSettingsRole(id: $id) { ok id }
            }',
            'variables' => ['id' => $roleId],
        ]);
        $delRole->assertOk();
        $this->assertArrayNotHasKey('errors', $delRole->json() ?? [], json_encode($delRole->json()));
        $this->assertTrue((bool) $delRole->json('data.deleteAdminSettingsRole.ok'));
        $this->assertNull(Role::query()->find((int) $roleId));
    }
}
