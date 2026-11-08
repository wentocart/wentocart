<?php

namespace Tests\Feature;

use App\Http\Middleware\ExposeApiRateLimitHeaders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave155Test extends TestCase
{
    use RefreshDatabase;

    public function test_api_exposes_rate_limit_and_timing_headers_to_browsers(): void
    {
        $this->seed();

        $res = $this->postJson('/api/v1/graphql', [
            'query' => '{ products(first: 1) { edges { node { id } } } }',
        ]);
        $res->assertOk();
        $exposed = (string) $res->headers->get('Access-Control-Expose-Headers');
        foreach (['X-RateLimit-Limit', 'X-Wento-GQL-Duration-Ms', 'Server-Timing', 'ETag', 'X-Wento-GQL-Cache'] as $header) {
            $this->assertStringContainsString($header, $exposed);
        }
        $this->assertSame(ExposeApiRateLimitHeaders::HEADERS[0], 'Retry-After');
    }
}
