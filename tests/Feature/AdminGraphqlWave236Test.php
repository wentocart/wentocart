<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave236Test extends TestCase
{
    use RefreshDatabase;

    public function test_playground_auto_inject_and_graphql_path_aliases(): void
    {
        $this->seed();

        config([
            'wento.api_playground.auto_inject_storefront_key' => false,
            'wento.api_playground.storefront_key' => '',
        ]);

        $off = $this->get('/api/v1/store/playground');
        $off->assertOk();
        $offHtml = (string) $off->getContent();
        $this->assertStringContainsString('Auto-inject', $offHtml);
        $this->assertStringContainsString('OFF', $offHtml);
        $this->assertStringNotContainsString('requestInterceptor', $offHtml);

        config([
            'wento.api_playground.auto_inject_storefront_key' => true,
            'wento.api_playground.storefront_key' => 'pk_storefront_w236_test_key',
        ]);

        $on = $this->get('/api/v1/store/playground');
        $on->assertOk();
        $onHtml = (string) $on->getContent();
        $this->assertStringContainsString('Auto-inject', $onHtml);
        $this->assertStringContainsString('ON', $onHtml);
        $this->assertStringContainsString('requestInterceptor', $onHtml);
        $this->assertStringContainsString('pk_storefront_w236_test_key', $onHtml);

        config(['app.debug' => true]);
        $gql = $this->get('/api/v1/graphiql');
        $gql->assertOk();
        $gqlHtml = (string) $gql->getContent();
        $this->assertStringContainsString('Auto-inject ON', $gqlHtml);
        $this->assertStringContainsString('pk_storefront_w236_test_key', $gqlHtml);
        $this->assertStringContainsString('X-STOREFRONT-KEY', $gqlHtml);

        $shopAlias = $this->postJson('/api/graphql', [
            'query' => '{ __typename }',
        ]);
        $shopAlias->assertOk();
        $this->assertSame('Query', (string) $shopAlias->json('data.__typename'));

        $landing = $this->getJson('/api/json');
        $landing->assertOk();
        $highlights = $landing->json('highlights_wento') ?? [];
        $this->assertTrue(
            collect($highlights)->contains(fn ($h) => str_contains((string) $h, 'auto-inject')),
            'landing should highlight playground auto-inject'
        );
        $this->assertTrue(
            collect($highlights)->contains(fn ($h) => str_contains((string) $h, '/api/graphql')),
            'landing should highlight GraphQL aliases'
        );
    }
}
