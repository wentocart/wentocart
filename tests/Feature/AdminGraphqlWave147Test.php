<?php

namespace Tests\Feature;

use App\Domain\Api\Models\StorefrontApiKey;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AdminGraphqlWave147Test extends TestCase
{
    use RefreshDatabase;

    public function test_storefront_key_hourly_rate_limit_headers_and_429(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => <<<'GQL'
                mutation {
                  createStorefrontApiKey(name: "Wave147 Tight", rateLimit: 2) {
                    id
                    plain_key
                    rate_limit
                  }
                }
            GQL,
        ]);
        $create->assertOk();
        $this->assertArrayNotHasKey('errors', $create->json() ?? [], json_encode($create->json()));
        $plain = (string) $create->json('data.createStorefrontApiKey.plain_key');
        $keyId = (int) $create->json('data.createStorefrontApiKey.id');
        $this->assertSame(2, (int) $create->json('data.createStorefrontApiKey.rate_limit'));

        $bucket = 'wento:sfkey:hour:'.$keyId.':'.now()->format('YmdH');
        RateLimiter::clear($bucket);

        $ok1 = $this->withHeader('X-STOREFRONT-KEY', $plain)->postJson('/api/v1/graphql', [
            'query' => '{ products(first: 1) { edges { node { id } } } }',
        ]);
        $ok1->assertOk();
        $this->assertSame('2', $ok1->headers->get('X-RateLimit-Limit'));
        $this->assertNotNull($ok1->headers->get('X-RateLimit-Remaining'));
        $this->assertNotNull($ok1->headers->get('X-RateLimit-Reset'));

        $ok2 = $this->withHeader('X-STOREFRONT-KEY', $plain)->postJson('/api/v1/graphql', [
            'query' => '{ products(first: 1) { edges { node { id } } } }',
        ]);
        $ok2->assertOk();

        $blocked = $this->withHeader('X-STOREFRONT-KEY', $plain)->postJson('/api/v1/graphql', [
            'query' => '{ products(first: 1) { edges { node { id } } } }',
        ]);
        $blocked->assertStatus(429);
        $this->assertSame('rate_limit_exceeded', $blocked->json('error'));
        $this->assertSame('0', $blocked->headers->get('X-RateLimit-Remaining'));
        $this->assertGreaterThan(0, (int) $blocked->json('retry_after'));
        $this->assertTrue(StorefrontApiKey::query()->whereKey($keyId)->exists());
    }
}
