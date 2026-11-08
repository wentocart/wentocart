<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave122Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_geo_and_payment_relay_connections(): void
    {
        $this->seed();

        $response = $this->postJson('/api/v1/graphql', [
            'query' => '{
                countries(first: 3) {
                    totalCount
                    pageInfo { hasNextPage endCursor }
                    edges {
                        cursor
                        node {
                            code name
                            states { totalCount edges { node { code defaultName } } }
                        }
                    }
                }
                locales(first: 3) { totalCount edges { node { code } } }
                paymentMethods(first: 5) { totalCount edges { node { code title } } }
            }',
        ]);
        $response->assertOk();
        $this->assertArrayNotHasKey('errors', $response->json() ?? [], json_encode($response->json()));
        $this->assertGreaterThanOrEqual(1, (int) $response->json('data.countries.totalCount'));
        $this->assertIsString($response->json('data.countries.edges.0.cursor'));
        $this->assertGreaterThanOrEqual(1, (int) $response->json('data.locales.totalCount'));
        $this->assertGreaterThanOrEqual(1, (int) $response->json('data.paymentMethods.totalCount'));
    }
}
