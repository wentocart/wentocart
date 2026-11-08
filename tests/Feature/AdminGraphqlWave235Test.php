<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AdminGraphqlWave235Test extends TestCase
{
    use RefreshDatabase;

    public function test_export_api_schema_and_console_graphiql(): void
    {
        $this->seed();

        $out = storage_path('app/testing-api-schemas-w235');
        File::deleteDirectory($out);

        $code = Artisan::call('wento:export-api-schema', ['--path' => $out]);
        $this->assertSame(0, $code);

        foreach (['shop-openapi.json', 'admin-openapi.json', 'shop.graphql', 'admin.graphql'] as $file) {
            $full = $out.DIRECTORY_SEPARATOR.$file;
            $this->assertFileExists($full, $file);
            $this->assertGreaterThan(100, filesize($full), $file);
        }

        $shopOpen = json_decode((string) file_get_contents($out.'/shop-openapi.json'), true);
        $this->assertSame('3.0.3', (string) ($shopOpen['openapi'] ?? ''));
        $this->assertArrayHasKey('/store/customer/login', $shopOpen['paths'] ?? []);

        $adminOpen = json_decode((string) file_get_contents($out.'/admin-openapi.json'), true);
        $this->assertSame('3.0.3', (string) ($adminOpen['openapi'] ?? ''));
        $this->assertArrayHasKey('/console/dashboard/stats', $adminOpen['paths'] ?? []);

        $shopSdl = (string) file_get_contents($out.'/shop.graphql');
        $this->assertStringContainsString('type Query', $shopSdl);
        $this->assertStringContainsString('ProductListing', $shopSdl);
        $adminSdl = (string) file_get_contents($out.'/admin.graphql');
        $this->assertStringContainsString('type Query', $adminSdl);
        $this->assertStringContainsString('dashboard', $adminSdl);

        File::deleteDirectory($out);

        config(['app.debug' => true]);

        $adminGql = $this->get('/api/v1/console/graphiql');
        $adminGql->assertOk();
        $html = (string) $adminGql->getContent();
        $this->assertStringContainsString('Wento Admin GraphiQL', $html);
        $this->assertStringContainsString('/api/v1/console/graphql', $html);

        $storeGql = $this->get('/api/v1/graphiql');
        $storeGql->assertOk();
        $this->assertStringContainsString('/api/v1/graphql', (string) $storeGql->getContent());

        $landing = $this->getJson('/api/json');
        $landing->assertOk();
        $this->assertStringContainsString('/console/graphiql', (string) $landing->json('surfaces.console.graphiql'));
        $this->assertSame('wento', (string) $landing->json('identity'));
        $highlights = $landing->json('highlights') ?? [];
        $this->assertNotEmpty($highlights);
    }
}
