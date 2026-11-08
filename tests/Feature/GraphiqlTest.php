<?php

namespace Tests\Feature;

use Tests\TestCase;

class GraphiqlTest extends TestCase
{
    public function test_graphiql_available_when_debug(): void
    {
        config(['app.debug' => true]);

        $this->get('/api/v1/graphiql')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertSee('Wento GraphiQL', false)
            ->assertSee('/api/v1/graphql', false);
    }

    public function test_graphiql_hidden_when_debug_off(): void
    {
        config(['app.debug' => false]);
        putenv('GRAPHQL_PLAYGROUND=false');
        $_ENV['GRAPHQL_PLAYGROUND'] = 'false';
        $_SERVER['GRAPHQL_PLAYGROUND'] = 'false';

        $this->get('/api/v1/graphiql')->assertNotFound();
    }

    public function test_apollo_sandbox_available_when_debug(): void
    {
        config(['app.debug' => true]);

        $this->get('/api/v1/sandbox')
            ->assertOk()
            ->assertSee('Wento GraphQL Sandbox', false)
            ->assertSee('sandbox.embed.apollographql.com', false)
            ->assertSee('/api/v1/graphql', false);
    }
}
