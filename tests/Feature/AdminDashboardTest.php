<?php

namespace Tests\Feature;

use App\Domain\Auth\Models\Role;
use App\Domain\Dashboard\Models\Dashboard;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_renders_the_default_store_board(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->get('/admin')->assertRedirect('/admin/dashboards');
        $this->get('/admin/dashboards')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboards/Index', false)
                ->where('dashboards.total', 5)
                ->has('dashboards.data', 5));

        $this->assertEqualsCanonicalizing(
            ['store-overview', 'sales', 'catalog', 'customers', 'operations'],
            Dashboard::query()->orderBy('slug')->pluck('slug')->all(),
        );
        $this->assertTrue((bool) Dashboard::query()->where('slug', 'store-overview')->value('is_default'));
    }

    public function test_dashboard_landing_filters_by_status_cards(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->get('/admin/dashboards?status=active')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.status', 'active')
                ->where('dashboards.total', 5)
                ->where('counts.inactive', 0));

        Dashboard::query()->where('slug', 'operations')->update(['is_enabled' => false]);

        $this->get('/admin/dashboards?status=inactive')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.status', 'inactive')
                ->where('dashboards.total', 1));
    }

    public function test_super_admin_can_create_a_dashboard_with_widgets(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->post('/admin/dashboards', [
            'name' => 'Weekend sales',
            'description' => 'Peak-hour board',
            'visibility' => 'assigned',
            'is_default' => false,
            'is_enabled' => true,
            'admin_ids' => [],
            'widgets' => [
                [
                    'title' => 'Revenue',
                    'chart_type' => 'area',
                    'metric' => 'revenue_by_day',
                    'range_days' => 7,
                    'col_span' => 12,
                    'palette' => 'violet',
                ],
            ],
        ])->assertRedirect();

        $this->assertDatabaseHas('dashboards', ['name' => 'Weekend sales', 'visibility' => 'assigned']);
        $this->assertDatabaseHas('dashboard_widgets', ['title' => 'Revenue', 'chart_type' => 'area']);
    }

    public function test_widget_accepts_hex_color_custom_range_and_layout_patch(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->post('/admin/dashboards', [
            'name' => 'Color board',
            'visibility' => 'all_admins',
            'is_default' => false,
            'is_enabled' => true,
            'admin_ids' => [],
            'widgets' => [
                [
                    'title' => 'Revenue',
                    'chart_type' => 'area',
                    'metric' => 'revenue_by_day',
                    'range_days' => 45,
                    'col_span' => 5,
                    'row_span' => 3,
                    'palette' => '#FF5500',
                ],
            ],
        ])->assertRedirect();

        $board = Dashboard::query()->where('name', 'Color board')->first();
        $this->assertNotNull($board);
        $widget = $board->widgets()->first();
        $this->assertSame(45, (int) $widget->range_days);
        $this->assertSame(5, (int) $widget->col_span);
        $this->assertSame('#FF5500', $widget->option('palette'));
        $this->assertSame(3, (int) $widget->option('row_span'));

        $this->patch("/admin/dashboards/{$board->id}/layout", [
            'widgets' => [
                [
                    'id' => $widget->id,
                    'position' => 0,
                    'col_span' => 12,
                    'row_span' => 2,
                    'grid_x' => 0,
                    'grid_y' => 4,
                ],
            ],
        ])->assertRedirect();

        $widget->refresh();
        $this->assertSame(12, (int) $widget->col_span);
        $this->assertSame(2, (int) $widget->option('row_span'));
        $this->assertSame(0, (int) $widget->option('grid_x'));
        $this->assertSame(4, (int) $widget->option('grid_y'));
    }

    public function test_unassigned_admin_cannot_open_a_private_dashboard(): void
    {
        $this->seed();
        $super = Admin::query()->first();
        $staff = Admin::factory()->create(['name' => 'Picker', 'email' => 'picker@example.com']);
        $staff->roles()->sync([Role::query()->where('slug', 'catalog_manager')->value('id')]);

        $board = Dashboard::query()->create([
            'name' => 'Secret board',
            'slug' => 'secret-board',
            'visibility' => 'assigned',
            'is_default' => false,
            'is_enabled' => true,
            'created_by_admin_id' => $super->id,
        ]);
        $board->widgets()->create([
            'title' => 'Orders',
            'chart_type' => 'kpi',
            'metric' => 'orders',
            'range_days' => 30,
            'col_span' => 6,
            'position' => 0,
            'options' => ['palette' => 'sky'],
        ]);

        $this->actingAs($staff, 'admin');
        $this->get("/admin/dashboards/{$board->id}")->assertForbidden();

        $board->assignees()->sync([$staff->id]);
        $this->get("/admin/dashboards/{$board->id}")->assertOk()->assertSee('Secret board');
    }

    public function test_employee_with_assigned_profile_can_open_dashboard(): void
    {
        $this->seed();
        $super = Admin::query()->first();
        $profile = \App\Domain\Auth\Models\Profile::query()->where('is_enabled', true)->first()
            ?? \App\Domain\Auth\Models\Profile::query()->create([
                'name' => 'Sales desk',
                'slug' => 'sales-desk',
                'description' => 'Sales',
                'is_enabled' => true,
            ]);

        $staff = Admin::factory()->create([
            'name' => 'Profile Staff',
            'email' => 'profile-staff@example.com',
            'profile_id' => $profile->id,
        ]);
        $staff->roles()->sync([Role::query()->where('slug', 'catalog_manager')->value('id')]);

        $board = Dashboard::query()->create([
            'name' => 'Profile board',
            'slug' => 'profile-board',
            'visibility' => 'assigned',
            'is_default' => false,
            'is_enabled' => true,
            'created_by_admin_id' => $super->id,
        ]);
        $board->widgets()->create([
            'title' => 'Orders',
            'chart_type' => 'kpi',
            'metric' => 'orders',
            'range_days' => 30,
            'col_span' => 6,
            'position' => 0,
            'options' => ['palette' => 'sky'],
        ]);
        $board->assignedProfiles()->sync([$profile->id]);

        $this->actingAs($staff, 'admin');
        $this->get("/admin/dashboards/{$board->id}")->assertOk()->assertSee('Profile board');
        $this->get('/admin/dashboards')->assertOk()->assertSee('Profile board');
    }

    public function test_super_admin_sees_all_dashboards_even_when_unassigned(): void
    {
        $this->seed();
        $super = Admin::query()->first();

        $board = Dashboard::query()->create([
            'name' => 'Hidden from staff',
            'slug' => 'hidden-from-staff',
            'visibility' => 'assigned',
            'is_default' => false,
            'is_enabled' => true,
            'created_by_admin_id' => $super->id,
        ]);
        $board->widgets()->create([
            'title' => 'Orders',
            'chart_type' => 'kpi',
            'metric' => 'orders',
            'range_days' => 30,
            'col_span' => 6,
            'position' => 0,
            'options' => ['palette' => 'sky'],
        ]);

        $this->actingAs($super, 'admin');
        $this->get("/admin/dashboards/{$board->id}")->assertOk()->assertSee('Hidden from staff');
        $this->get('/admin/dashboards')->assertOk()->assertSee('Hidden from staff');
    }
}
