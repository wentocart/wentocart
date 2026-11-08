<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave157Test extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_bearer_returns_rest_unauthenticated_shape(): void
    {
        $this->seed();

        $res = $this->withToken('999|not-a-real-token')->postJson('/api/v1/console/graphql', [
            'query' => '{ __typename }',
        ]);
        $res->assertStatus(401);
        $this->assertSame('Unauthenticated.', $res->json('message'));
        $this->assertSame('unauthenticated', $res->json('error'));
        $this->assertNull($res->json('errors'));
    }
}
