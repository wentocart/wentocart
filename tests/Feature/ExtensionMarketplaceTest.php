<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExtensionMarketplaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_marketplace_catalog_endpoint(): void
    {
        $this->seed();

        $response = $this->getJson('/api/v1/extensions/marketplace');
        $response->assertOk()
            ->assertJsonStructure([
                'version',
                'count',
                'extensions',
                'local_available',
            ]);

        $ids = collect($response->json('extensions'))->pluck('id')->filter()->values()->all();
        $this->assertContains('weblytic/mainmenu', $ids);
        if (is_dir(base_path('extensions/weblytic/wtsstripe'))) {
            $this->assertContains('weblytic/wtsstripe', $ids);
        }
        // Intentionally small shelf — do not require a 200+ seed.
        $this->assertLessThan(20, (int) $response->json('count'));
    }

    public function test_marketplace_discovers_on_disk_packages(): void
    {
        $payload = app(\App\Domain\Plugin\ExtensionMarketplace::class)->payload();
        $ids = collect($payload['extensions'])->pluck('id')->filter()->values()->all();
        $this->assertContains('weblytic/mainmenu', $ids);
        if (is_dir(base_path('extensions/weblytic/wtsstripe'))) {
            $this->assertContains('weblytic/wtsstripe', $ids);
        }
        $this->assertLessThan(20, (int) $payload['count']);
    }
}
