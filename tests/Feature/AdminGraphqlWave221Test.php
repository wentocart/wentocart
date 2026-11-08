<?php

namespace Tests\Feature;

use App\Domain\Settings\ShopSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave221Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_shop_theme_and_sections_rest(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);

        $theme = $this->getJson('/api/v1/store/theme');
        $theme->assertOk();
        $this->assertIsArray($theme->json());
        $this->assertNotEmpty($theme->json('0.code'));
        $types = $theme->json('0.sectionTypes');
        $this->assertIsArray($types);
        $this->assertContains('hero', $types);
        $this->assertContains('footer', $types);

        $sections = $this->getJson('/api/v1/store/sections?per_page=50');
        $sections->assertOk();
        $this->assertIsArray($sections->json());
        $this->assertNotEmpty($sections->json());
        $this->assertNotEmpty((string) $sections->headers->get('X-Total-Count'));
        $first = $sections->json('0');
        $this->assertArrayHasKey('type', $first);
        $this->assertArrayHasKey('translation', $first);
        $this->assertSame(1, (int) $first['status']);
        $this->assertIsString($first['translation']['options'] ?? null);

        $id = (int) $first['id'];
        $this->getJson('/api/v1/store/sections/'.$id)->assertOk()
            ->assertJsonPath('id', $id);

        $filtered = $this->getJson('/api/v1/store/sections?type=footer');
        $filtered->assertOk();
        foreach ($filtered->json() as $row) {
            $this->assertSame('footer', $row['type']);
        }

        $this->getJson('/api/v1/theme')->assertOk()
            ->assertJsonPath('0.code', $theme->json('0.code'));
    }
}
