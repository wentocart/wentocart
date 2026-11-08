<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave148Test extends TestCase
{
    use RefreshDatabase;

    public function test_shop_sections_connection_returns_published_blocks(): void
    {
        $this->seed();

        $res = $this->postJson('/api/v1/graphql', [
            'query' => <<<'GQL'
                {
                  sections(first: 5) {
                    totalCount
                    edges {
                      cursor
                      node {
                        id
                        id
                        type
                        name
                        sortOrder
                        status
                        themeCode
                        storeId
                        translation { locale options sectionId }
                        translations { locale sectionId }
                      }
                    }
                    pageInfo { hasNextPage endCursor }
                  }
                }
            GQL,
        ]);
        $res->assertOk();
        $this->assertArrayNotHasKey('errors', $res->json() ?? [], json_encode($res->json()));
        $this->assertGreaterThan(0, (int) $res->json('data.sections.totalCount'));
        $status = (string) $res->json('data.sections.edges.0.node.status');
        $this->assertSame('1', $status);
        $this->assertNotEmpty($res->json('data.sections.edges.0.node.type'));
        $id = (string) $res->json('data.sections.edges.0.node.id');

        $one = $this->postJson('/api/v1/graphql', [
            'query' => 'query ($id: ID!) { section(id: $id) { id type status translation { options } } }',
            'variables' => ['id' => $id],
        ]);
        $one->assertOk();
        $this->assertArrayNotHasKey('errors', $one->json() ?? [], json_encode($one->json()));
        $this->assertSame($id, (string) $one->json('data.section.id'));
        $this->assertSame('1', (string) $one->json('data.section.status'));
    }

    public function test_shop_sections_filter_by_type(): void
    {
        $this->seed();

        $res = $this->postJson('/api/v1/graphql', [
            'query' => '{ sections(type: "footer", first: 1) { edges { node { type name } } totalCount } }',
        ]);
        $res->assertOk();
        $this->assertArrayNotHasKey('errors', $res->json() ?? [], json_encode($res->json()));
        $this->assertGreaterThanOrEqual(1, (int) $res->json('data.sections.totalCount'));
        $this->assertSame('footer', (string) $res->json('data.sections.edges.0.node.type'));
    }
}
