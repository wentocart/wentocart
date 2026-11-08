<?php

namespace Tests\Feature;

use App\Domain\Auth\Models\Permission;
use App\Domain\Auth\Models\Profile;
use App\Domain\Auth\Models\Team;
use App\Domain\Auth\PageCatalog;
use App\Domain\Auth\StaffAcl;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffPageCatalogLeadTest extends TestCase
{
    use RefreshDatabase;

    public function test_warehouse_inventory_profile_can_open_stock_pages_but_not_customers_or_tax(): void
    {
        $this->seed();
        StaffAcl::ensure();

        $this->assertContains('catalog.inventory', PageCatalog::slugs());
        $this->assertTrue(PageCatalog::grants(['catalog.manage'], 'catalog.inventory'));
        $this->assertTrue(PageCatalog::grants(['catalog'], 'catalog.inventory'));

        $super = Admin::query()->firstOrFail();
        $this->actingAs($super, 'admin');

        $this->post('/admin/staff/profiles', [
            'name' => 'Warehouse desk',
            'description' => 'Warehouses + inventory only.',
            'is_enabled' => true,
            'permissions' => ['catalog.warehouses', 'catalog.inventory'],
        ])->assertRedirect();

        $profile = Profile::query()->where('slug', 'warehouse-desk')->first();
        $this->assertNotNull($profile);
        $profile->permissions()->sync(
            Permission::query()->whereIn('slug', ['catalog.warehouses', 'catalog.inventory'])->pluck('id')
        );

        $this->post('/admin/staff/teams', [
            'name' => 'Fulfillment',
            'color' => 'success',
            'is_enabled' => true,
            'default_profile_id' => $profile->id,
        ])->assertRedirect();

        $team = Team::query()->where('slug', 'fulfillment')->first();
        $this->assertNotNull($team);

        $this->post('/admin/staff/employees', [
            'name' => 'Pat Warehouse',
            'email' => 'pat-warehouse@store.test',
            'password' => 'Password123',
            'is_enabled' => true,
            'is_super' => false,
            'team_id' => $team->id,
        ])->assertRedirect();

        $employee = Admin::query()->where('email', 'pat-warehouse@store.test')->firstOrFail();
        $employee->applyProfile($profile->fresh());
        $employee = $employee->fresh(['profile.permissions', 'roles']);

        $this->assertTrue($employee->hasPermission('catalog.warehouses'));
        $this->assertTrue($employee->hasPermission('catalog.inventory'));
        $this->assertFalse($employee->hasPermission('catalog'));
        $this->assertFalse($employee->hasPermission('customers.customers'));
        $this->assertFalse($employee->hasPermission('taxes.manage'));

        $this->actingAs($employee, 'admin');
        $this->get('/admin/warehouses')->assertOk();
        $this->get('/admin/inventory')->assertOk();
        $this->get('/admin/inventory/journal')->assertOk();
        $this->get('/admin/customers')->assertForbidden();
        $this->get('/admin/tax')->assertForbidden();
        $this->get('/admin/products')->assertForbidden();
    }
}
