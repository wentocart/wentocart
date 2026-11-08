<?php

namespace Tests\Feature;

use App\Domain\Settings\Models\DataTransferImport;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave199Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_settings_data_transfer_imports_rest_rest_compat(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $csv = "sku,name\nWAVE199-SKU,Wave199 Product\n";

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/settings/data-transfer/imports', [
            'code' => 'products',
            'action' => 'append',
            'csvContent' => $csv,
            'fileName' => 'wave199.csv',
        ]);
        $create->assertCreated();
        $id = (int) $create->json('id');
        $this->assertGreaterThan(0, $id);
        $this->assertSame('pending', (string) $create->json('state'));
        $this->assertSame('products', (string) $create->json('code'));

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/settings/data-transfer/imports?code=products');
        $list->assertOk();
        $this->assertNotEmpty($list->json('data'));
        $this->assertArrayHasKey('currentPage', $list->json('meta'));
        $this->assertNull($list->json('data.0.errors'));

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/settings/data-transfer/imports/'.$id);
        $show->assertOk();
        $this->assertSame('wave199.csv', (string) $show->json('fileName'));

        $validate = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/settings/data-transfer/imports/'.$id.'/validate');
        $validate->assertOk();
        $this->assertTrue((bool) $validate->json('isValid'));
        $this->assertSame('validated', (string) $validate->json('import.state'));

        $start = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/settings/data-transfer/imports/'.$id.'/start');
        $start->assertOk();
        $this->assertSame('processing', (string) $start->json('state'));

        $link = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/settings/data-transfer/imports/'.$id.'/link');
        $link->assertOk();
        $this->assertSame('linked', (string) $link->json('state'));

        $index = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/settings/data-transfer/imports/'.$id.'/index');
        $index->assertOk();
        $this->assertSame('completed', (string) $index->json('state'));

        $pending = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/settings/data-transfer/imports', [
            'code' => 'products',
            'csvContent' => $csv,
        ]);
        $pending->assertCreated();
        $pendingId = (int) $pending->json('id');

        $cancel = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/settings/data-transfer/imports/'.$pendingId.'/cancel');
        $cancel->assertOk();
        $this->assertSame('cancelled', (string) $cancel->json('state'));

        $mass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/settings/data-transfer/imports/bulk-delete', [
            'ids' => [$pendingId, 999999],
        ]);
        $mass->assertOk();
        $this->assertContains($pendingId, $mass->json('deleted'));

        $del = $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/settings/data-transfer/imports/'.$id);
        $del->assertOk();
        $this->assertFalse(DataTransferImport::query()->whereKey($id)->exists());

        $gql = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ adminSettingsDataTransferImports(limit: 5) { id code state } }',
        ]);
        $gql->assertOk();
        $this->assertArrayNotHasKey('errors', $gql->json() ?? [], json_encode($gql->json()));
    }
}
