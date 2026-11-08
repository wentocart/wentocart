<?php

namespace Tests\Feature;

use App\Http\Middleware\AssignApiRequestId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave161Test extends TestCase
{
    use RefreshDatabase;

    public function test_graphql_assigns_and_echoes_request_id(): void
    {
        $this->seed();

        $generated = $this->postJson('/api/v1/graphql', [
            'query' => '{ products(first: 1) { edges { node { id } } } }',
        ]);
        $generated->assertOk();
        $id = (string) $generated->headers->get(AssignApiRequestId::HEADER);
        $this->assertNotSame('', $id);
        $this->assertStringContainsString('X-Request-Id', (string) $generated->headers->get('Access-Control-Expose-Headers'));

        $echo = $this->withHeader(AssignApiRequestId::HEADER, 'wento-wave161-trace')
            ->postJson('/api/v1/graphql', [
                'query' => '{ products(first: 1) { edges { node { id } } } }',
            ]);
        $echo->assertOk();
        $this->assertSame('wento-wave161-trace', (string) $echo->headers->get(AssignApiRequestId::HEADER));
    }
}
