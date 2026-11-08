<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AdminGraphqlWave149Test extends TestCase
{
    use RefreshDatabase;

    public function test_integration_token_rate_limit_shared_across_graphql_and_rest_export(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => <<<'GQL'
                mutation {
                  createIntegrationToken(name: "Wave149 Shared", rateLimitPerMinute: 2, rateLimitPerDay: 0) {
                    id
                    plain_token
                  }
                }
            GQL,
        ]);
        $create->assertOk();
        $plain = (string) $create->json('data.createIntegrationToken.plain_token');
        $tokenId = (string) $create->json('data.createIntegrationToken.id');
        RateLimiter::clear('wento:inttok:min:'.$tokenId);

        $gql = $this->withToken($plain)->postJson('/api/v1/console/graphql', [
            'query' => '{ __typename }',
        ]);
        $gql->assertOk();
        $this->assertSame('2', $gql->headers->get('X-RateLimit-Limit'));

        $export = $this->withToken($plain)->get('/api/v1/console/stores/export');
        $export->assertOk();
        $this->assertSame('2', $export->headers->get('X-RateLimit-Limit'));
        $this->assertNotNull($export->headers->get('X-RateLimit-Remaining'));

        $blocked = $this->withToken($plain)->postJson('/api/v1/console/graphql', [
            'query' => '{ __typename }',
        ]);
        $blocked->assertStatus(429);
        $this->assertSame('rate_limit_exceeded', $blocked->json('error'));
    }

    public function test_storefront_key_middleware_still_rate_limits_shop_graphql(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation { createStorefrontApiKey(name: "Wave149 SF", rateLimit: 1) { id plain_key } }',
        ]);
        $plain = (string) $create->json('data.createStorefrontApiKey.plain_key');
        $keyId = (int) $create->json('data.createStorefrontApiKey.id');
        RateLimiter::clear('wento:sfkey:hour:'.$keyId.':'.now()->format('YmdH'));

        $ok = $this->withHeader('X-STOREFRONT-KEY', $plain)->postJson('/api/v1/graphql', [
            'query' => '{ products(first: 1) { edges { node { id } } } }',
        ]);
        $ok->assertOk();
        $this->assertSame('1', $ok->headers->get('X-RateLimit-Limit'));

        $blocked = $this->withHeader('X-STOREFRONT-KEY', $plain)->postJson('/api/v1/graphql', [
            'query' => '{ products(first: 1) { edges { node { id } } } }',
        ]);
        $blocked->assertStatus(429);
        $this->assertSame('rate_limit_exceeded', $blocked->json('error'));
    }
}
