<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Warehouse;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave40Test extends TestCase
{
    use RefreshDatabase;

    public function test_update_and_delete_warehouse(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $code = 'W40'.strtoupper(substr(uniqid(), -5));
        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($c: String!) {
                createWarehouse(
                    name: "Wave40 Depot"
                    code: $c
                    email: "w40@example.com"
                    address: "40 Depot Rd"
                    city: "Boise"
                    postcode: "83702"
                    country: "US"
                    state: "ID"
                    enabled: true
                    isDefault: false
                ) { id code city is_enabled }
            }',
            'variables' => ['c' => $code],
        ]);
        $create->assertOk();
        $this->assertArrayNotHasKey('errors', $create->json() ?? []);
        $id = (string) $create->json('data.createWarehouse.id');

        $update = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                updateWarehouse(id: $id, name: "Wave40 Renamed", city: "Meridian", enabled: true) {
                    id name city is_enabled
                }
            }',
            'variables' => ['id' => $id],
        ]);
        $update->assertOk();
        $this->assertArrayNotHasKey('errors', $update->json() ?? []);
        $update->assertJsonPath('data.updateWarehouse.city', 'Meridian');
        $this->assertStringContainsString('Renamed', (string) $update->json('data.updateWarehouse.name'));

        $del = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) { deleteWarehouse(id: $id) { ok id } }',
            'variables' => ['id' => $id],
        ]);
        $del->assertOk();
        $this->assertArrayNotHasKey('errors', $del->json() ?? []);
        $this->assertTrue((bool) $del->json('data.deleteWarehouse.ok'));
        $this->assertNull(Warehouse::query()->find((int) $id));
    }
}
