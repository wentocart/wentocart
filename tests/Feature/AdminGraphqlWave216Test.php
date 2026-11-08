<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave216Test extends TestCase
{
    use RefreshDatabase;

    public function test_data_transfer_import_sample_download(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $csv = $this->actingAs($admin, 'admin')
            ->get('/api/v1/console/settings/data-transfer/imports/sample/products/csv');
        $csv->assertOk();
        $body = $csv->streamedContent();
        $this->assertStringContainsString('sku', strtolower($body));
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('content-type'));

        $xml = $this->actingAs($admin, 'admin')
            ->get('/api/v1/console/settings/data-transfer/imports/sample/customers/xml');
        $xml->assertOk();
        $xmlBody = $xml->streamedContent();
        $this->assertStringContainsString('<?xml', $xmlBody);
        $this->assertStringContainsString('<customers>', $xmlBody);

        $customers = $this->actingAs($admin, 'admin')
            ->get('/api/v1/console/settings/data-transfer/imports/sample/customers/csv');
        $customers->assertOk();
        $this->assertNotSame('', $customers->streamedContent());

        $badType = $this->actingAs($admin, 'admin')
            ->getJson('/api/v1/console/settings/data-transfer/imports/sample/widgets/csv');
        $badType->assertNotFound();

        $badFmt = $this->actingAs($admin, 'admin')
            ->getJson('/api/v1/console/settings/data-transfer/imports/sample/products/pdf');
        $badFmt->assertNotFound();
    }
}
