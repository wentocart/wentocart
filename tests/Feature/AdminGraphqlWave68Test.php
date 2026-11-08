<?php

namespace Tests\Feature;

use App\Domain\Settings\Models\DataTransferImport;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave68Test extends TestCase
{
    use RefreshDatabase;

    public function test_data_transfer_imports_rest_lifecycle(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);

        $csv = "sku,name,price\nW68-SKU,Wave68 Product,19.99\n";

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($csv: String!) {
                createAdminSettingsDataTransferImport(
                    code: "products"
                    action: "append"
                    csvContent: $csv
                    fileName: "wave68-products.csv"
                ) { id code action state fileName }
            }',
            'variables' => ['csv' => $csv],
        ]);
        $create->assertOk();
        $this->assertArrayNotHasKey('errors', $create->json() ?? [], json_encode($create->json()));
        $importId = (string) $create->json('data.createAdminSettingsDataTransferImport.id');
        $this->assertSame('pending', (string) $create->json('data.createAdminSettingsDataTransferImport.state'));

        $validate = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                validateAdminSettingsDataTransferImportValidate(id: $id, importId: $id) {
                    ok isValid errorsCount
                    import { id state }
                }
            }',
            'variables' => ['id' => $importId],
        ]);
        $validate->assertOk();
        $this->assertArrayNotHasKey('errors', $validate->json() ?? [], json_encode($validate->json()));
        $this->assertTrue((bool) $validate->json('data.validateAdminSettingsDataTransferImportValidate.isValid'));
        $this->assertSame('validated', (string) $validate->json('data.validateAdminSettingsDataTransferImportValidate.import.state'));

        $start = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                startAdminSettingsDataTransferImport(id: $id) {
                    ok state processedRowsCount
                }
            }',
            'variables' => ['id' => $importId],
        ]);
        $start->assertOk();
        $this->assertSame('processing', (string) $start->json('data.startAdminSettingsDataTransferImport.state'));
        $this->assertSame(1, (int) $start->json('data.startAdminSettingsDataTransferImport.processedRowsCount'));

        $link = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                linkAdminSettingsDataTransferImport(id: $id) { ok state }
            }',
            'variables' => ['id' => $importId],
        ]);
        $link->assertOk();
        $this->assertSame('linked', (string) $link->json('data.linkAdminSettingsDataTransferImport.state'));

        $index = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                indexAdminSettingsDataTransferImport(id: $id) { ok state }
            }',
            'variables' => ['id' => $importId],
        ]);
        $index->assertOk();
        $this->assertSame('completed', (string) $index->json('data.indexAdminSettingsDataTransferImport.state'));

        $list = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query {
                adminSettingsDataTransferImports(code: "products", state: "completed", limit: 10) {
                    id state processedRowsCount
                }
                adminSettingsDataTransferImport(id: "'.$importId.'") { id errors summary state }
                adminSettingsDataTransferImportStats(id: "'.$importId.'") { id state processedRowsCount }
            }',
        ]);
        $list->assertOk();
        $this->assertArrayNotHasKey('errors', $list->json() ?? [], json_encode($list->json()));
        $this->assertNotEmpty($list->json('data.adminSettingsDataTransferImports'));
        $this->assertSame('completed', (string) $list->json('data.adminSettingsDataTransferImport.state'));

        $pending = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($csv: String!) {
                createAdminSettingsDataTransferImport(code: "products", csvContent: $csv) { id state }
            }',
            'variables' => ['csv' => $csv],
        ]);
        $pendingId = (string) $pending->json('data.createAdminSettingsDataTransferImport.id');
        $cancel = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                cancelAdminSettingsDataTransferImport(id: $id) { ok state }
            }',
            'variables' => ['id' => $pendingId],
        ]);
        $cancel->assertOk();
        $this->assertSame('cancelled', (string) $cancel->json('data.cancelAdminSettingsDataTransferImport.state'));

        $del = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                deleteAdminSettingsDataTransferImport(id: $id) { ok id }
            }',
            'variables' => ['id' => $importId],
        ]);
        $del->assertOk();
        $this->assertTrue((bool) $del->json('data.deleteAdminSettingsDataTransferImport.ok'));
        $this->assertNull(DataTransferImport::query()->find((int) $importId));
    }
}
