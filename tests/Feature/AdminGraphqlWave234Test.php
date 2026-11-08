<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave234Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_openapi_docs_and_swagger_playground(): void
    {
        $this->seed();

        $docs = $this->getJson('/api/v1/console/docs');
        $docs->assertOk();
        $this->assertSame('3.0.3', (string) $docs->json('openapi'));
        $this->assertSame('WentoCart Admin API', (string) $docs->json('info.title'));
        $this->assertNotEmpty($docs->json('paths./admin/dashboard/stats.get'));
        $this->assertNotEmpty($docs->json('paths./admin/settings/integrations.get'));
        $this->assertNotEmpty($docs->json('paths./admin/orders/place/{cartId}.post'));
        $this->assertNotEmpty($docs->json('paths./admin/customers/{customerId}/impersonate.post'));
        $this->assertArrayHasKey('integrationBearer', $docs->json('components.securitySchemes') ?? []);
        $servers = $docs->json('servers') ?? [];
        $this->assertIsArray($servers);
        $this->assertNotEmpty($servers);
        $this->assertStringContainsString('/api/v1', (string) ($servers[0]['url'] ?? ''));

        $aliasDocs = $this->getJson('/api/v1/console/docs');
        $aliasDocs->assertOk();
        $this->assertSame((string) $docs->json('info.title'), (string) $aliasDocs->json('info.title'));

        $openapiAlias = $this->getJson('/api/v1/console/openapi.json');
        $openapiAlias->assertOk();
        $this->assertSame('3.0.3', (string) $openapiAlias->json('openapi'));

        $playground = $this->get('/api/v1/console/playground');
        $playground->assertOk();
        $html = (string) $playground->getContent();
        $this->assertStringContainsString('swagger-ui', $html);
        $this->assertStringContainsString('/api/v1/console/docs', $html);
        $this->assertStringContainsString('SwaggerUIBundle', $html);
        $this->assertStringContainsString('Integration Bearer', $html);
        $this->assertSame('text/html; charset=UTF-8', (string) $playground->headers->get('Content-Type'));

        $compatAlias = $this->get('/api/v1/console');
        $compatAlias->assertOk();
        $this->assertStringContainsString('SwaggerUIBundle', (string) $compatAlias->getContent());

        $landing = $this->getJson('/api/json');
        $landing->assertOk();
        $this->assertStringContainsString('/admin/docs', (string) $landing->json('docs.admin_openapi'));
        $this->assertStringContainsString('/admin/playground', (string) $landing->json('surfaces.admin.playground'));
        $this->assertStringContainsString('/api/v1/console', (string) $landing->json('surfaces.admin.playground_rest_alias'));
        $highlights = $landing->json('highlights_wento') ?? [];
        $this->assertTrue(
            collect($highlights)->contains(fn ($h) => str_contains((string) $h, 'Admin OpenAPI')),
            'landing should highlight Admin OpenAPI'
        );
    }
}
