<?php

namespace Tests\Feature;

use App\Domain\Auth\Models\Role;
use App\Domain\Store\Models\Store;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminGraphqlWave85Test extends TestCase
{
    use RefreshDatabase;

    public function test_channels_crud_csv_and_role_user_mass_delete(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $channelsCsv = $this->actingAs($admin, 'admin')->get('/api/v1/console/stores/export');
        $channelsCsv->assertOk();
        $this->assertStringContainsString('text/csv', (string) $channelsCsv->headers->get('content-type'));
        $this->assertStringContainsString('code', $channelsCsv->streamedContent());

        $rolesCsv = $this->actingAs($admin, 'admin')->get('/api/v1/console/roles/export');
        $rolesCsv->assertOk();
        $this->assertStringContainsString('name', $rolesCsv->streamedContent());

        $usersCsv = $this->actingAs($admin, 'admin')->get('/api/v1/console/users/export');
        $usersCsv->assertOk();
        $this->assertStringContainsString((string) $admin->email, $usersCsv->streamedContent());

        $created = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation {
                createAdminSettingsStore(
                    name: "Wave85 Channel"
                    hostname: "w85.example.test"
                    email: "w85@example.com"
                    theme: "default"
                    enabled: true
                ) { id code name hostname is_default }
            }',
        ]);
        $created->assertOk();
        $this->assertArrayNotHasKey('errors', $created->json() ?? [], json_encode($created->json()));
        $storeId = (string) $created->json('data.createAdminSettingsStore.id');
        $this->assertNotSame('', $storeId);
        $this->assertSame('Wave85 Channel', (string) $created->json('data.createAdminSettingsStore.name'));

        $updated = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                updateAdminSettingsStore(id: $id, name: "Wave85 Channel Updated") { id name }
            }',
            'variables' => ['id' => $storeId],
        ]);
        $updated->assertOk();
        $this->assertArrayNotHasKey('errors', $updated->json() ?? [], json_encode($updated->json()));
        $this->assertSame('Wave85 Channel Updated', (string) $updated->json('data.updateAdminSettingsStore.name'));

        $deleted = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                deleteAdminSettingsStore(id: $id) { ok id }
            }',
            'variables' => ['id' => $storeId],
        ]);
        $deleted->assertOk();
        $this->assertArrayNotHasKey('errors', $deleted->json() ?? [], json_encode($deleted->json()));
        $this->assertTrue((bool) $deleted->json('data.deleteAdminSettingsStore.ok'));
        $this->assertNull(Store::query()->find((int) $storeId));

        $role = Role::query()->create([
            'name' => 'Wave85 Temp Role',
            'slug' => 'wave85-temp-'.substr(uniqid(), -6),
        ]);
        $roleMass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                bulkDeleteAdminSettingsRole(ids: [$id]) { ok updated_count deleted skipped }
            }',
            'variables' => ['id' => (string) $role->id],
        ]);
        $roleMass->assertOk();
        $this->assertArrayNotHasKey('errors', $roleMass->json() ?? [], json_encode($roleMass->json()));
        $this->assertSame(1, (int) $roleMass->json('data.bulkDeleteAdminSettingsRole.updated_count'));
        $this->assertNull(Role::query()->find($role->id));

        $temp = Admin::query()->create([
            'name' => 'Wave85 Temp User',
            'email' => 'wave85-temp@example.com',
            'password' => Hash::make('password123'),
            'is_enabled' => true,
        ]);
        $userMass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                bulkDeleteAdminSettingsUser(ids: [$id]) { ok updated_count deleted skipped }
            }',
            'variables' => ['id' => (string) $temp->id],
        ]);
        $userMass->assertOk();
        $this->assertArrayNotHasKey('errors', $userMass->json() ?? [], json_encode($userMass->json()));
        $this->assertSame(1, (int) $userMass->json('data.bulkDeleteAdminSettingsUser.updated_count'));
        $this->assertNull(Admin::query()->find($temp->id));

        Auth::guard('admin')->logout();
        $this->app['auth']->forgetGuards();
        $this->get('/api/v1/console/stores/export')->assertUnauthorized();
    }
}
