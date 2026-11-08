<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave173Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dashboard_navigation_and_profile_rest_compat(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        // REST dashboard — API one-element array
        $restDash = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/dashboard/stats?type=overview');
        $restDash->assertOk();
        $this->assertIsArray($restDash->json());
        $this->assertSame('overview', (string) $restDash->json('0.type'));
        $this->assertNotEmpty($restDash->json('0.dateRange'));
        $this->assertIsArray($restDash->json('0.statistics'));
        $this->assertArrayHasKey('total_sales', $restDash->json('0.statistics'));
        $this->assertArrayHasKey('total_orders', $restDash->json('0.statistics'));
        $this->assertArrayHasKey('total_customers', $restDash->json('0.statistics'));
        $this->assertArrayHasKey('avg_sales', $restDash->json('0.statistics'));
        $this->assertArrayHasKey('total_unpaid_invoices', $restDash->json('0.statistics'));

        // Invalid type → 400
        $this->actingAs($admin, 'admin')
            ->getJson('/api/v1/console/dashboard/stats?type=nope')
            ->assertStatus(400);

        // Other dashboard sections
        foreach (['today', 'low_stock_products', 'sales_total', 'visitors_total', 'top_products', 'top-customers'] as $type) {
            $section = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/dashboard/stats?type='.$type);
            $section->assertOk();
            $this->assertSame($type, (string) $section->json('0.type'));
        }

        // REST menu / permissions / profile
        $menu = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/menu');
        $menu->assertOk();
        $this->assertSame('menu', (string) $menu->json('0.id'));
        $this->assertIsArray($menu->json('0.tree'));
        $this->assertNotEmpty($menu->json('0.tree'));

        $perms = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/permissions');
        $perms->assertOk();
        $this->assertSame('permissions', (string) $perms->json('0.id'));
        $this->assertContains($perms->json('0.permissionType'), ['all', 'custom', 'same_as_web']);
        $this->assertIsArray($perms->json('0.permissions'));
        $this->assertContains('*', $perms->json('0.permissions'));

        $profile = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/profile');
        $profile->assertOk();
        $this->assertSame((string) $admin->id, (string) $profile->json('id'));
        $this->assertSame((string) $admin->email, (string) $profile->json('email'));
        $this->assertSame('1', (string) $profile->json('status'));
        $this->assertTrue((bool) $profile->json('success'));

        // GraphQL
        $gql = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{
                statsAdminDashboard(type: "overview") { type dateRange statistics }
                adminMenu { id tree }
                adminPermissions { id permissionType permissions }
                readAdminProfile { id name email status success roleId roleName }
            }',
        ]);
        $gql->assertOk();
        $this->assertArrayNotHasKey('errors', $gql->json() ?? [], json_encode($gql->json()));
        $this->assertSame('overview', (string) $gql->json('data.statsAdminDashboard.type'));
        $this->assertNotEmpty($gql->json('data.statsAdminDashboard.dateRange'));
        $stats = json_decode((string) $gql->json('data.statsAdminDashboard.statistics'), true);
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_sales', $stats);

        $tree = json_decode((string) $gql->json('data.adminMenu.tree'), true);
        $this->assertIsArray($tree);
        $this->assertNotEmpty($tree);

        $permList = json_decode((string) $gql->json('data.adminPermissions.permissions'), true);
        $this->assertIsArray($permList);
        $this->assertContains('*', $permList);

        $this->assertSame((string) $admin->email, (string) $gql->json('data.readAdminProfile.email'));
        $this->assertSame('1', (string) $gql->json('data.readAdminProfile.status'));

        // Unauthenticated shapes (flush session/guard from actingAs)
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->withHeaders([])->getJson('/api/v1/console/dashboard/stats')
            ->assertStatus(401)
            ->assertJson(['message' => 'Unauthenticated.', 'error' => 'unauthenticated']);
        $this->getJson('/api/v1/console/menu')
            ->assertStatus(401)
            ->assertJson(['error' => 'unauthenticated']);
        $this->getJson('/api/v1/console/profile')
            ->assertStatus(401)
            ->assertJson(['error' => 'unauthenticated']);

    }
}
