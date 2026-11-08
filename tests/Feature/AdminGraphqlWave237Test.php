<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave237Test extends TestCase
{
    use RefreshDatabase;

    public function test_postman_collections_downloadable(): void
    {
        $this->seed();

        foreach ([
            'wento-shop.postman_collection.json',
            'wento-admin.postman_collection.json',
            'wento.postman_environment.json',
        ] as $file) {
            $path = base_path('api-collections/'.$file);
            $this->assertFileExists($path, $file);
            $json = json_decode((string) file_get_contents($path), true);
            $this->assertIsArray($json, $file);
        }

        $index = $this->getJson('/api/v1/collections');
        $index->assertOk();
        $this->assertStringContainsString('/collections/shop', (string) $index->json('shop'));
        $this->assertStringContainsString('/collections/admin', (string) $index->json('admin'));
        $this->assertStringContainsString('/collections/environment', (string) $index->json('environment'));

        $shop = $this->get('/api/v1/collections/shop');
        $shop->assertOk();
        $shopJson = json_decode((string) $shop->getContent(), true);
        $this->assertSame('WentoCart Shop API', (string) ($shopJson['info']['name'] ?? ''));
        $this->assertNotEmpty($shopJson['item'] ?? []);

        $admin = $this->get('/api/v1/collections/admin');
        $admin->assertOk();
        $adminJson = json_decode((string) $admin->getContent(), true);
        $this->assertSame('WentoCart Admin API', (string) ($adminJson['info']['name'] ?? ''));

        $env = $this->get('/api/v1/collections/environment');
        $env->assertOk();
        $envJson = json_decode((string) $env->getContent(), true);
        $keys = collect($envJson['values'] ?? [])->pluck('key')->all();
        $this->assertContains('base_url', $keys);
        $this->assertContains('storefront_key', $keys);
        $this->assertContains('integration_token', $keys);

        $landing = $this->getJson('/api/json');
        $landing->assertOk();
        $this->assertStringContainsString('/collections', (string) $landing->json('docs.postman_collections'));
        $highlights = $landing->json('highlights_wento') ?? [];
        $this->assertTrue(
            collect($highlights)->contains(fn ($h) => str_contains((string) $h, 'Postman')),
            'landing should highlight Postman collections'
        );
    }
}
