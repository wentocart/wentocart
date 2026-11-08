<?php

namespace Tests\Feature;

use App\Domain\Api\Graphql\GraphqlShopResponseCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave150Test extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_shop_graphql_emits_etag_and_supports_304(): void
    {
        $this->seed();

        $query = '{ products(first: 1) { edges { node { id } } } }';

        $first = $this->postJson('/api/v1/graphql', ['query' => $query]);
        $first->assertOk();
        $this->assertArrayNotHasKey('errors', $first->json() ?? [], json_encode($first->json()));
        $etag = (string) $first->headers->get('ETag');
        $this->assertNotSame('', $etag);
        $this->assertStringContainsString('max-age='.GraphqlShopResponseCache::TTL_SECONDS, (string) $first->headers->get('Cache-Control'));
        $this->assertStringContainsString('X-Wento-Locale', (string) $first->headers->get('Vary'));

        $second = $this->withHeader('If-None-Match', $etag)->postJson('/api/v1/graphql', ['query' => $query]);
        $second->assertStatus(304);
        $this->assertSame($etag, (string) $second->headers->get('ETag'));
        $this->assertSame('HIT', (string) $second->headers->get(GraphqlShopResponseCache::HEADER));
    }

    public function test_mutations_are_not_http_cacheable(): void
    {
        $this->seed();

        $res = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation { __typename }',
        ]);
        // mutation __typename may error or succeed depending on schema; headers matter
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        $this->assertNull($res->headers->get('ETag'));
    }
}
