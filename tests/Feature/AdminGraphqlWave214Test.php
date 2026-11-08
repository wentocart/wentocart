<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave214Test extends TestCase
{
    use RefreshDatabase;

    public function test_api_landing_lists_surfaces_and_leads(): void
    {
        $this->seed();

        $html = $this->get('/api');
        $html->assertOk();
        $this->assertStringContainsString('API', $html->getContent());
        $this->assertStringContainsString('llms.txt', $html->getContent());
        $this->assertStringContainsString('Integration', $html->getContent());

        $json = $this->getJson('/api/json');
        $json->assertOk();
        $this->assertSame('v1', (string) $json->json('version'));
        $this->assertNotEmpty($json->json('surfaces.shop.graphql'));
        $this->assertNotEmpty($json->json('surfaces.admin.integrations'));
        $this->assertNotEmpty($json->json('docs.llms'));
        $highlights = $json->json('highlights_wento') ?? [];
        $this->assertIsArray($highlights);
        $this->assertGreaterThanOrEqual(3, count($highlights));

        $v1 = $this->getJson('/api/v1?json=1');
        $v1->assertOk();
        $this->assertSame((string) $json->json('surfaces.admin.rest_base'), (string) $v1->json('surfaces.admin.rest_base'));
    }
}
