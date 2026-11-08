<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave105Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_products_cursor_connection(): void
    {
        $this->seed();

        $page1 = $this->postJson('/api/v1/graphql', [
            'query' => '{
                products(first: 2) {
                    totalCount
                    totalItems
                    edges {
                        cursor
                        node { id sku name }
                    }
                    nodes { id }
                    pageInfo { hasNextPage hasPreviousPage startCursor endCursor }
                }
            }',
        ]);
        $page1->assertOk();
        $this->assertArrayNotHasKey('errors', $page1->json() ?? [], json_encode($page1->json()));
        $this->assertGreaterThan(0, (int) $page1->json('data.products.totalCount'));
        $edges = $page1->json('data.products.edges') ?? [];
        $this->assertNotEmpty($edges);
        $this->assertCount(count($edges), $page1->json('data.products.nodes') ?? []);
        $end = (string) $page1->json('data.products.pageInfo.endCursor');
        $this->assertNotSame('', $end);

        if ($page1->json('data.products.pageInfo.hasNextPage')) {
            $page2 = $this->postJson('/api/v1/graphql', [
                'query' => 'query ($after: String!) {
                    products(first: 2, after: $after) {
                        edges { node { id } }
                        pageInfo { hasPreviousPage endCursor }
                    }
                }',
                'variables' => ['after' => $end],
            ]);
            $page2->assertOk();
            $this->assertArrayNotHasKey('errors', $page2->json() ?? [], json_encode($page2->json()));
            $this->assertTrue((bool) $page2->json('data.products.pageInfo.hasPreviousPage'));
            $ids1 = collect($edges)->pluck('node.id')->all();
            $ids2 = collect($page2->json('data.products.edges') ?? [])->pluck('node.id')->all();
            $this->assertSame([], array_values(array_intersect($ids1, $ids2)));
        }

        // Legacy offset shape still works
        $legacy = $this->postJson('/api/v1/graphql', [
            'query' => '{ products(limit: 1) { total items { id sku } } }',
        ]);
        $legacy->assertOk();
        $this->assertArrayNotHasKey('errors', $legacy->json() ?? [], json_encode($legacy->json()));
        $this->assertGreaterThan(0, (int) $legacy->json('data.products.total'));
        $this->assertNotEmpty($legacy->json('data.products.items.0.id'));
    }
}
