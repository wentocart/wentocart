<?php

namespace Tests\Feature;

use App\Domain\Auth\Models\Profile;
use App\Domain\Auth\Models\Team;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminStaffTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_create_team_profile_and_employee(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->post('/admin/staff/profiles', [
            'name' => 'Warehouse picker',
            'description' => 'Catalog pages only.',
            'is_enabled' => true,
            'permissions' => ['catalog.manage'],
        ])->assertRedirect();

        $profile = Profile::query()->where('slug', 'warehouse-picker')->first();
        $this->assertNotNull($profile);

        $this->post('/admin/staff/teams', [
            'name' => 'Support',
            'color' => 'success',
            'is_enabled' => true,
            'default_profile_id' => $profile->id,
        ])->assertRedirect();

        $team = Team::query()->where('slug', 'support')->first();
        $this->assertNotNull($team);

        $this->post('/admin/staff/employees', [
            'name' => 'Sam Picker',
            'email' => 'sam@store.test',
            'password' => 'Password123',
            'is_enabled' => true,
            'is_super' => false,
            'team_id' => $team->id,
        ])->assertRedirect();

        $employee = Admin::query()->where('email', 'sam@store.test')->first();
        $this->assertNotNull($employee);
        $this->assertSame($profile->id, $employee->profile_id);
        $this->assertFalse($employee->isSuperAdmin());
        $this->assertTrue($employee->hasPermission('catalog'));
        $this->assertFalse($employee->hasPermission('customers.customers'));
    }

    public function test_catalog_profile_employee_cannot_open_customers_or_staff(): void
    {
        $this->seed();
        $profile = Profile::query()->where('slug', 'catalog_editor')->firstOrFail();
        $team = Team::query()->where('slug', 'operations')->firstOrFail();
        $employee = Admin::factory()->create([
            'name' => 'Catalog Sam',
            'email' => 'catalog-sam@store.test',
            'team_id' => $team->id,
        ]);
        $employee->applyProfile($profile);

        $this->actingAs($employee->fresh(['profile.permissions']), 'admin');

        $this->get('/admin/products')->assertOk();
        $this->get('/admin/categories')->assertOk();
        $this->get('/admin/customers')->assertForbidden();
        $this->get('/admin/staff/employees')->assertForbidden();
    }

    public function test_non_super_cannot_open_staff_urls(): void
    {
        $this->seed();
        $profile = Profile::query()->where('slug', 'order_desk')->firstOrFail();
        $staff = Admin::factory()->create(['email' => 'desk@store.test']);
        $staff->applyProfile($profile);

        $this->actingAs($staff->fresh(['profile.permissions']), 'admin');
        $this->get('/admin/staff/teams')->assertForbidden();
        $this->get('/admin/staff/profiles')->assertForbidden();
        $this->post('/admin/staff/employees', [
            'name' => 'Hacker',
            'email' => 'hacker@store.test',
            'password' => 'password123',
            'is_enabled' => true,
            'is_super' => true,
        ])->assertForbidden();
    }

    public function test_last_super_admin_cannot_be_disabled(): void
    {
        $this->seed();
        $super = Admin::query()->first();
        $this->actingAs($super, 'admin');

        $this->post("/admin/staff/employees/{$super->id}/toggle")->assertSessionHasErrors();
        $this->assertTrue($super->fresh()->is_enabled);
    }

    public function test_super_admin_can_open_staff_pages(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->get('/admin/staff/employees')->assertOk();
        $this->get('/admin/staff/teams')->assertOk();
        $this->get('/admin/staff/profiles')->assertOk();
        $this->get('/admin/roles')->assertRedirect('/admin/staff/profiles');
    }
}
