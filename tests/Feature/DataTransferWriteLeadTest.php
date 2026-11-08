<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataTransferWriteLeadTest extends TestCase
{
    use RefreshDatabase;

    public function test_data_transfer_start_writes_product_rows(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $sku = 'DT-LEAD-'.strtoupper(substr(uniqid(), -6));
        $csv = "sku,name,price\n{$sku},Data Transfer Lead Product,19.99\n";

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/settings/data-transfer/imports', [
            'code' => 'products',
            'action' => 'append',
            'csvContent' => $csv,
            'fileName' => 'dt-lead.csv',
        ]);
        $create->assertCreated();
        $id = (int) $create->json('id');

        $this->actingAs($admin, 'admin')
            ->postJson('/api/v1/console/settings/data-transfer/imports/'.$id.'/validate')
            ->assertOk()
            ->assertJsonPath('isValid', true);

        $start = $this->actingAs($admin, 'admin')
            ->postJson('/api/v1/console/settings/data-transfer/imports/'.$id.'/start');
        $start->assertOk();
        $this->assertSame('processing', (string) $start->json('state'));
        $this->assertGreaterThanOrEqual(1, (int) $start->json('processedRowsCount'));

        $this->assertDatabaseHas('products', ['sku' => $sku]);
        $product = Product::query()->where('sku', $sku)->first();
        $this->assertNotNull($product);
        $this->assertSame('Data Transfer Lead Product', (string) ($product->translation()?->name ?? ''));
    }
}
