<?php

namespace Tests\Feature;

use App\Domain\Api\Graphql\IntegrationTokenRateLimit;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AdminGraphqlWave146Test extends TestCase
{
    use RefreshDatabase;

    public function test_create_integration_token_defaults_rest_rate_limits(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $res = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => <<<'GQL'
                mutation {
                  createIntegrationToken(name: "Wave146 Defaults") {
                    id
                    plain_token
                    rateLimitPerMinute
                    rateLimitPerDay
                  }
                }
            GQL,
        ]);
        $res->assertOk();
        $this->assertArrayNotHasKey('errors', $res->json() ?? [], json_encode($res->json()));
        $this->assertSame(60, (int) $res->json('data.createIntegrationToken.rateLimitPerMinute'));
        $this->assertSame(10000, (int) $res->json('data.createIntegrationToken.rateLimitPerDay'));
        $this->assertNotEmpty($res->json('data.createIntegrationToken.plain_token'));
    }

    public function test_integration_token_rate_limit_returns_rest_headers(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => <<<'GQL'
                mutation {
                  createIntegrationToken(name: "Wave146 Tight", rateLimitPerMinute: 2, rateLimitPerDay: 0) {
                    id
                    plain_token
                    rateLimitPerMinute
                    rateLimitPerDay
                  }
                }
            GQL,
        ]);
        $create->assertOk();
        $this->assertSame(2, (int) $create->json('data.createIntegrationToken.rateLimitPerMinute'));
        $this->assertNull($create->json('data.createIntegrationToken.rateLimitPerDay'));
        $plain = (string) $create->json('data.createIntegrationToken.plain_token');
        $tokenId = (string) $create->json('data.createIntegrationToken.id');

        RateLimiter::clear('wento:inttok:min:'.$tokenId);

        $ok1 = $this->withToken($plain)->postJson('/api/v1/console/graphql', [
            'query' => '{ __typename }',
        ]);
        $ok1->assertOk();
        $this->assertSame('2', $ok1->headers->get('X-RateLimit-Limit'));
        $this->assertNotNull($ok1->headers->get('X-RateLimit-Remaining'));
        $this->assertNotNull($ok1->headers->get('X-RateLimit-Reset'));

        $ok2 = $this->withToken($plain)->postJson('/api/v1/console/graphql', [
            'query' => '{ __typename }',
        ]);
        $ok2->assertOk();

        $blocked = $this->withToken($plain)->postJson('/api/v1/console/graphql', [
            'query' => '{ __typename }',
        ]);
        $blocked->assertStatus(429);
        $this->assertSame('rate_limit_exceeded', $blocked->json('error'));
        $this->assertSame('0', $blocked->headers->get('X-RateLimit-Remaining'));
        $this->assertNotNull($blocked->headers->get('Retry-After'));
        $this->assertSame(IntegrationTokenRateLimit::DEFAULT_PER_MINUTE, 60);
    }

    public function test_update_integration_token_rate_limits(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation { createIntegrationToken(name: "Wave146 Update") { id rateLimitPerMinute } }',
        ]);
        $id = (string) $create->json('data.createIntegrationToken.id');

        $update = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) {
                updateIntegrationTokenRateLimits(id: $id, rateLimitPerMinute: 120, rateLimitPerDay: 5000) {
                  rateLimitPerMinute
                  rateLimitPerDay
                }
            }',
            'variables' => ['id' => $id],
        ]);
        $update->assertOk();
        $this->assertArrayNotHasKey('errors', $update->json() ?? [], json_encode($update->json()));
        $this->assertSame(120, (int) $update->json('data.updateIntegrationTokenRateLimits.rateLimitPerMinute'));
        $this->assertSame(5000, (int) $update->json('data.updateIntegrationTokenRateLimits.rateLimitPerDay'));
    }
}
