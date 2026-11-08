<?php

namespace Tests\Feature;

use App\Domain\Api\Models\StorefrontApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AdminGraphqlWave238Test extends TestCase
{
    use RefreshDatabase;

    public function test_generate_storefront_key_cli_and_security_md(): void
    {
        $this->seed();

        $this->assertFileExists(base_path('SECURITY.md'));
        $security = (string) file_get_contents(base_path('SECURITY.md'));
        $this->assertStringContainsString('Reporting a vulnerability', $security);
        $this->assertStringContainsString('do not', strtolower($security));

        $code = Artisan::call('wento:generate-storefront-key', [
            '--name' => 'Wave238 CLI Key',
            '--rate-limit' => '250',
        ]);
        $this->assertSame(0, $code);
        $out = Artisan::output();
        $this->assertStringContainsString('Storefront key generated successfully', $out);
        $this->assertStringContainsString('pk_storefront_', $out);
        $this->assertStringContainsString('250 requests/hour', $out);
        $this->assertStringContainsString('Active', $out);

        $row = StorefrontApiKey::query()->where('name', 'Wave238 CLI Key')->first();
        $this->assertNotNull($row);
        $this->assertSame(250, (int) $row->rate_limit);
        $this->assertTrue((bool) $row->is_enabled);

        $dup = Artisan::call('wento:generate-storefront-key', [
            '--name' => 'Wave238 CLI Key',
        ]);
        $this->assertSame(1, $dup);

        $inactive = Artisan::call('wento:generate-storefront-key', [
            '--name' => 'Wave238 Inactive',
            '--rate-limit' => '0',
            '--no-activation' => true,
        ]);
        $this->assertSame(0, $inactive);
        $off = StorefrontApiKey::query()->where('name', 'Wave238 Inactive')->first();
        $this->assertNotNull($off);
        $this->assertFalse((bool) $off->is_enabled);
        $this->assertNull($off->rate_limit);

        $landing = $this->getJson('/api/json');
        $landing->assertOk();
        $highlights = $landing->json('highlights_wento') ?? [];
        $this->assertTrue(
            collect($highlights)->contains(fn ($h) => str_contains((string) $h, 'generate-storefront-key')),
            'landing should highlight generate-storefront-key'
        );
    }
}
