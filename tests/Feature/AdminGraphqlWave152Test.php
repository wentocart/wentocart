<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AdminGraphqlWave152Test extends TestCase
{
    use RefreshDatabase;

    public function test_storefront_key_quota_shared_across_shop_graphql_and_rest_catalog(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation { createStorefrontApiKey(name: "Wave152 Shared", rateLimit: 2) { id plain_key } }',
        ]);
        $create->assertOk();
        $plain = (string) $create->json('data.createStorefrontApiKey.plain_key');
        $keyId = (int) $create->json('data.createStorefrontApiKey.id');
        RateLimiter::clear('wento:sfkey:hour:'.$keyId.':'.now()->format('YmdH'));

        $gql = $this->withHeader('X-STOREFRONT-KEY', $plain)->postJson('/api/v1/graphql', [
            'query' => '{ products(first: 1) { edges { node { id } } } }',
        ]);
        $gql->assertOk();
        $this->assertSame('2', $gql->headers->get('X-RateLimit-Limit'));

        $rest = $this->withHeader('X-STOREFRONT-KEY', $plain)->getJson('/api/v1/catalog/home');
        $rest->assertOk();
        $this->assertSame('2', $rest->headers->get('X-RateLimit-Limit'));

        $blocked = $this->withHeader('X-STOREFRONT-KEY', $plain)->getJson('/api/v1/catalog/categories');
        $blocked->assertStatus(429);
        $this->assertSame('rate_limit_exceeded', $blocked->json('error'));
    }

    public function test_catalog_rest_without_storefront_key_still_works(): void
    {
        $this->seed();

        $res = $this->getJson('/api/v1/catalog/home');
        $res->assertOk();
        // Laravel route throttle may set X-RateLimit-*; storefront key quota must not 429.
        $this->assertNotSame('rate_limit_exceeded', $res->json('error'));
    }
}
