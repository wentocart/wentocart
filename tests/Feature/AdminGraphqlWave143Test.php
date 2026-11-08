<?php

namespace Tests\Feature;

use App\Domain\Api\Graphql\GraphqlShopResponseCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave143Test extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_shop_graphql_catalog_query_is_cached(): void
    {
        $this->seed();

        $query = '{ treeCategories { id name } }';

        $first = $this->postJson('/api/v1/graphql', ['query' => $query]);
        $first->assertOk();
        $this->assertArrayNotHasKey('errors', $first->json() ?? [], json_encode($first->json()));
        $this->assertSame('MISS', $first->headers->get(GraphqlShopResponseCache::HEADER));
        $this->assertIsArray($first->json('data.treeCategories'));

        $second = $this->postJson('/api/v1/graphql', ['query' => $query]);
        $second->assertOk();
        $this->assertArrayNotHasKey('errors', $second->json() ?? [], json_encode($second->json()));
        $this->assertSame('HIT', $second->headers->get(GraphqlShopResponseCache::HEADER));
        $this->assertSame($first->json('data'), $second->json('data'));

        $mutation = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation { createCartToken { cartToken } }',
        ]);
        $mutation->assertOk();
        $this->assertSame('BYPASS', $mutation->headers->get(GraphqlShopResponseCache::HEADER));
    }
}
