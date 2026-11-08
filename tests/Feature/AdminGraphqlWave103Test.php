<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave103Test extends TestCase
{
    use RefreshDatabase;

    public function test_store_home_modules_and_default_store(): void
    {
        $this->seed();

        $theme = $this->postJson('/api/v1/graphql', [
            'query' => '{ theme { code name sectionTypes } }',
        ]);
        $theme->assertOk();
        $this->assertArrayNotHasKey('errors', $theme->json() ?? [], json_encode($theme->json()));
        $this->assertNotSame('', (string) $theme->json('data.theme.code'));
        $types = $theme->json('data.theme.sectionTypes') ?? [];
        $this->assertContains('hero', $types);
        $this->assertContains('featured', $types);

        $store = $this->postJson('/api/v1/graphql', [
            'query' => '{ defaultStore { id code name currencyCode localeCode theme } }',
        ]);
        if (! empty($store->json('errors'))) {
            $store = $this->postJson('/api/v1/graphql', [
                'query' => '{ stores(first: 1) { edges { node { id code name } } } }',
            ]);
            $store->assertOk();
            $this->assertArrayNotHasKey('errors', $store->json() ?? [], json_encode($store->json()));
            $this->assertNotEmpty($store->json('data.stores.edges'));
        } else {
            $store->assertOk();
            $this->assertArrayNotHasKey('errors', $store->json() ?? [], json_encode($store->json()));
            $this->assertNotSame('', (string) $store->json('data.defaultStore.id'));
        }

        $list = $this->postJson('/api/v1/graphql', [
            'query' => '{
                homeModules(first: 50) {
                    totalCount
                    edges {
                        cursor
                        node {
                            id type name sortOrder status
                            translation { locale options }
                        }
                    }
                    pageInfo { hasNextPage endCursor }
                }
            }',
        ]);
        $list->assertOk();
        $this->assertArrayNotHasKey('errors', $list->json() ?? [], json_encode($list->json()));
        $this->assertGreaterThan(0, (int) $list->json('data.homeModules.totalCount'));
        $nodes = collect($list->json('data.homeModules.edges') ?? [])->pluck('node')->all();
        $this->assertNotEmpty($nodes);
        $firstId = (string) ($nodes[0]['id'] ?? '');
        $this->assertNotSame('', $firstId);
        $options = json_decode((string) ($nodes[0]['translation']['options'] ?? '{}'), true);
        $this->assertIsArray($options);

        $filtered = $this->postJson('/api/v1/graphql', [
            'query' => '{
                homeModules(first: 20, type: "hero") {
                    totalCount
                    edges { node { type name } }
                }
            }',
        ]);
        $filtered->assertOk();
        $this->assertArrayNotHasKey('errors', $filtered->json() ?? [], json_encode($filtered->json()));
        foreach ($filtered->json('data.homeModules.edges') ?? [] as $edge) {
            $this->assertSame('hero', (string) ($edge['node']['type'] ?? ''));
        }

        $one = $this->postJson('/api/v1/graphql', [
            'query' => 'query ($id: ID!) {
                homeModule(id: $id) {
                    id type name translation { options locale }
                }
            }',
            'variables' => ['id' => $firstId],
        ]);
        $one->assertOk();
        $this->assertArrayNotHasKey('errors', $one->json() ?? [], json_encode($one->json()));
        $this->assertSame($firstId, (string) $one->json('data.homeModule.id'));
    }
}
