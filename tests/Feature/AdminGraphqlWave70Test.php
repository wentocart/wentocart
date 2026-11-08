<?php

namespace Tests\Feature;

use App\Domain\Order\Models\ReturnCustomField;
use App\Domain\Settings\ConfigurationCatalog;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave70Test extends TestCase
{
    use RefreshDatabase;

    public function test_rma_custom_fields_and_configuration_slug_rest_aliases(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $field = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($opts: String!) {
                createAdminRmaCustomField(
                    label: "Wave70 Preferred resolution"
                    type: "select"
                    isRequired: 1
                    status: 1
                    position: 5
                    optionsJson: $opts
                ) { id code label type isRequired status options { id name value } }
            }',
            'variables' => [
                'opts' => json_encode([
                    ['id' => '1', 'name' => 'Refund', 'value' => 'refund'],
                    ['id' => '2', 'name' => 'Exchange', 'value' => 'exchange'],
                ]),
            ],
        ]);
        $field->assertOk();
        $this->assertArrayNotHasKey('errors', $field->json() ?? [], json_encode($field->json()));
        $fieldId = (string) $field->json('data.createAdminRmaCustomField.id');
        $this->assertSame('select', (string) $field->json('data.createAdminRmaCustomField.type'));
        $this->assertSame(1, (int) $field->json('data.createAdminRmaCustomField.isRequired'));
        $this->assertCount(2, $field->json('data.createAdminRmaCustomField.options') ?? []);

        $update = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                updateAdminRmaCustomField(id: $id, label: "Wave70 Resolution", status: 0) {
                    id label status
                }
            }',
            'variables' => ['id' => $fieldId],
        ]);
        $update->assertOk();
        $this->assertSame('Wave70 Resolution', (string) $update->json('data.updateAdminRmaCustomField.label'));
        $this->assertSame(0, (int) $update->json('data.updateAdminRmaCustomField.status'));

        $mass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($ids: [ID!]!) {
                createAdminRmaCustomFieldBulkUpdateStatus(ids: $ids, status: 1) { ok updated_count }
            }',
            'variables' => ['ids' => [$fieldId]],
        ]);
        $mass->assertOk();
        $this->assertTrue((bool) $mass->json('data.createAdminRmaCustomFieldBulkUpdateStatus.ok'));
        $this->assertTrue((bool) ReturnCustomField::query()->find((int) $fieldId)?->is_enabled);

        $list = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query {
                adminRmaCustomFields(q: "Wave70", status: 1, limit: 10) { id label }
                adminRmaCustomField(id: "'.$fieldId.'") { id code options { value } }
            }',
        ]);
        $list->assertOk();
        $this->assertArrayNotHasKey('errors', $list->json() ?? [], json_encode($list->json()));
        $this->assertNotEmpty($list->json('data.adminRmaCustomFields'));

        $slugs = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query { listAdminConfigurationSlug }',
        ]);
        $slugs->assertOk();
        $this->assertArrayNotHasKey('errors', $slugs->json() ?? [], json_encode($slugs->json()));
        $slugList = $slugs->json('data.listAdminConfigurationSlug') ?? [];
        $this->assertContains('orders', $slugList);
        $this->assertSame(ConfigurationCatalog::slugs(), $slugList);

        $menu = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query {
                menuAdminConfigurationMenu(slug: "orders", includeValues: true) {
                    slug fields { code type title } valuesJson
                }
            }',
        ]);
        $menu->assertOk();
        $this->assertArrayNotHasKey('errors', $menu->json() ?? [], json_encode($menu->json()));
        $this->assertSame('orders', (string) $menu->json('data.menuAdminConfigurationMenu.slug'));
        $this->assertNotEmpty($menu->json('data.menuAdminConfigurationMenu.fields'));
        $this->assertNotEmpty($menu->json('data.menuAdminConfigurationMenu.valuesJson'));

        $valuesJson = json_encode([
            'orders.admin_reorder_enabled' => '0',
            'checkout.guest_checkout' => '1',
        ]);
        $updateCfg = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($slug: String!, $values: String!) {
                createAdminConfigurationUpdate(slug: $slug, valuesJson: $values) {
                    ok slug valuesJson message
                }
            }',
            'variables' => [
                'slug' => 'orders',
                'values' => $valuesJson,
            ],
        ]);
        $updateCfg->assertOk();
        $this->assertArrayNotHasKey('errors', $updateCfg->json() ?? [], json_encode($updateCfg->json()));
        $this->assertTrue((bool) $updateCfg->json('data.createAdminConfigurationUpdate.ok'));
        $decoded = json_decode((string) $updateCfg->json('data.createAdminConfigurationUpdate.valuesJson'), true);
        $this->assertIsArray($decoded);
        $this->assertSame('0', (string) ($decoded['orders.admin_reorder_enabled'] ?? ''));

        $read = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query {
                valuesAdminConfigurationValues(slug: "orders") { slug valuesJson }
            }',
        ]);
        $read->assertOk();
        $this->assertArrayNotHasKey('errors', $read->json() ?? [], json_encode($read->json()));
        $readValues = json_decode((string) $read->json('data.valuesAdminConfigurationValues.valuesJson'), true);
        $this->assertSame('0', (string) ($readValues['orders.admin_reorder_enabled'] ?? ''));

        // Out-of-scope key must be rejected (API hard rule).
        $bad = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation {
                createAdminConfigurationUpdate(
                    slug: "orders"
                    valuesJson: "{\\"catalog_per_page\\":\\"12\\"}"
                ) { ok }
            }',
        ]);
        $bad->assertOk();
        $this->assertNotEmpty($bad->json('errors'));

        $del = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) { deleteAdminRmaCustomField(id: $id) { id message } }',
            'variables' => ['id' => $fieldId],
        ]);
        $del->assertOk();
        $this->assertSame($fieldId, (string) $del->json('data.deleteAdminRmaCustomField.id'));
        $this->assertNull(ReturnCustomField::query()->find((int) $fieldId));
    }
}
