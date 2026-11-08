<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave233Test extends TestCase
{
    use RefreshDatabase;

    public function test_shop_openapi_docs_and_swagger_playground(): void
    {
        $this->seed();

        $docs = $this->getJson('/api/v1/store/docs');
        $docs->assertOk();
        $this->assertSame('3.0.3', (string) $docs->json('openapi'));
        $this->assertSame('WentoCart Shop API', (string) $docs->json('info.title'));
        $this->assertNotEmpty($docs->json('paths./shop/customer/login.post'));
        $this->assertNotEmpty($docs->json('paths./shop/checkout-orders.post'));
        $this->assertNotEmpty($docs->json('paths./shop/customer-orders/{id}/cancel.post'));
        $this->assertArrayHasKey('customerBearer', $docs->json('components.securitySchemes') ?? []);
        $this->assertArrayHasKey('storefrontKey', $docs->json('components.securitySchemes') ?? []);
        $servers = $docs->json('servers') ?? [];
        $this->assertIsArray($servers);
        $this->assertNotEmpty($servers);
        $this->assertStringContainsString('/api/v1', (string) ($servers[0]['url'] ?? ''));

        $aliasDocs = $this->getJson('/api/v1/store/docs');
        $aliasDocs->assertOk();
        $this->assertSame((string) $docs->json('info.title'), (string) $aliasDocs->json('info.title'));

        $openapiAlias = $this->getJson('/api/v1/store/openapi.json');
        $openapiAlias->assertOk();
        $this->assertSame('3.0.3', (string) $openapiAlias->json('openapi'));

        $playground = $this->get('/api/v1/store/playground');
        $playground->assertOk();
        $html = (string) $playground->getContent();
        $this->assertStringContainsString('swagger-ui', $html);
        $this->assertStringContainsString('/api/v1/store/docs', $html);
        $this->assertStringContainsString('SwaggerUIBundle', $html);
        $this->assertSame('text/html; charset=UTF-8', (string) $playground->headers->get('Content-Type'));

        $compatAlias = $this->get('/api/v1/store');
        $compatAlias->assertOk();
        $this->assertStringContainsString('SwaggerUIBundle', (string) $compatAlias->getContent());

        $landing = $this->getJson('/api/json');
        $landing->assertOk();
        $this->assertStringContainsString('/shop/docs', (string) $landing->json('docs.shop_openapi'));
        $this->assertStringContainsString('/shop/playground', (string) $landing->json('surfaces.shop.playground'));
        $this->assertStringContainsString('/api/v1/store', (string) $landing->json('surfaces.shop.playground_rest_alias'));
        $highlights = $landing->json('highlights_wento') ?? [];
        $this->assertTrue(
            collect($highlights)->contains(fn ($h) => str_contains((string) $h, 'OpenAPI')),
            'landing should highlight Shop OpenAPI'
        );
    }
}
