<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave159Test extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_storefront_key_returns_rest_unauthenticated_shape(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation { updateStorefrontApiKeyRequirement(required: true) { ok } }',
        ])->assertOk();

        $res = $this->withHeader('X-STOREFRONT-KEY', 'pk_storefront_notreal')->postJson('/api/v1/graphql', [
            'query' => '{ products(first: 1) { edges { node { id } } } }',
        ]);
        $res->assertStatus(401);
        $this->assertSame('Unauthenticated.', $res->json('message'));
        $this->assertSame('unauthenticated', $res->json('error'));
    }
}
