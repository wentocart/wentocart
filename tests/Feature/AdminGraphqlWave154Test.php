<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave154Test extends TestCase
{
    use RefreshDatabase;

    public function test_llms_txt_lists_live_shop_and_admin_graphql_ops(): void
    {
        $this->seed();

        $res = $this->get('/api/v1/llms.txt');
        $res->assertOk();
        $res->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $body = (string) $res->getContent();
        $this->assertStringContainsString('# Wentocart API', $body);
        $this->assertStringContainsString('## GraphQL API › Shop › Query', $body);
        $this->assertStringContainsString('## GraphQL API › Admin › Mutation', $body);
        $this->assertStringContainsString('`products`', $body);
        $this->assertStringContainsString('`sections`', $body);
        $this->assertStringContainsString('`adminCatalogCategories`', $body);
        $this->assertStringContainsString('`createIntegrationToken`', $body);
        $this->assertStringContainsString('max aliases', $body);
    }
}
