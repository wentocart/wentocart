<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave35Test extends TestCase
{
    use RefreshDatabase;

    public function test_create_warehouse_update_configuration_and_attribute_options(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $code = 'WH'.strtoupper(substr(uniqid(), -6));
        $warehouse = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($c: String!) {
                createWarehouse(
                    name: "GraphQL Depot"
                    code: $c
                    email: "depot@example.com"
                    address: "100 Warehouse Rd"
                    city: "Austin"
                    postcode: "78701"
                    country: "US"
                    state: "TX"
                    enabled: true
                ) { id code name city country is_enabled }
            }',
            'variables' => ['c' => $code],
        ]);
        $warehouse->assertOk();
        $this->assertArrayNotHasKey('errors', $warehouse->json() ?? []);
        $warehouse->assertJsonPath('data.createWarehouse.code', $code);
        $warehouse->assertJsonPath('data.createWarehouse.city', 'Austin');

        $cfg = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation {
                updateConfiguration(storeName: "Wento GraphQL Shop", storeEmail: "ops@wento.test", storePhone: "+15550001111") {
                    ok message configuration { store_name store_email store_phone }
                }
            }',
        ]);
        $cfg->assertOk();
        $this->assertArrayNotHasKey('errors', $cfg->json() ?? []);
        $this->assertTrue((bool) $cfg->json('data.updateConfiguration.ok'));
        $this->assertSame('Wento GraphQL Shop', $cfg->json('data.updateConfiguration.configuration.store_name'));
        $this->assertSame('ops@wento.test', $cfg->json('data.updateConfiguration.configuration.store_email'));

        $attr = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation { createAttribute(name: "Wave35 Size", type: "select") { id code type } }',
        ]);
        $attr->assertOk();
        $this->assertArrayNotHasKey('errors', $attr->json() ?? []);
        $attrId = (string) $attr->json('data.createAttribute.id');

        $opt = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                createAttributeOption(attributeId: $id, label: "Large", code: "lg") { id attribute_id label code }
            }',
            'variables' => ['id' => $attrId],
        ]);
        $opt->assertOk();
        $this->assertArrayNotHasKey('errors', $opt->json() ?? []);
        $opt->assertJsonPath('data.createAttributeOption.label', 'Large');
        $optId = (string) $opt->json('data.createAttributeOption.id');

        $del = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) { deleteAttributeOption(id: $id) { ok id } }',
            'variables' => ['id' => $optId],
        ]);
        $del->assertOk();
        $this->assertArrayNotHasKey('errors', $del->json() ?? []);
        $this->assertTrue((bool) $del->json('data.deleteAttributeOption.ok'));
    }
}
