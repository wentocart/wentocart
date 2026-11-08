<?php

namespace Tests\Feature;

use App\Domain\Api\Models\StorefrontApiKey;
use App\Domain\Settings\ShopSetting;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave98Test extends TestCase
{
    use RefreshDatabase;

    public function test_storefront_api_key_guard_and_admin_lifecycle(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->assertNotNull($admin);
        ShopSetting::put('api.require_storefront_key', false);

        $open = $this->postJson('/api/v1/graphql', [
            'query' => '{ products(limit: 1) { items { id } } }',
        ]);
        $open->assertOk();
        $this->assertArrayNotHasKey('errors', $open->json() ?? [], json_encode($open->json()));

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation {
                createStorefrontApiKey(name: "Wave98 Mobile", rateLimit: 250) {
                    id name key_prefix rate_limit plain_key is_enabled
                }
            }',
        ]);
        $this->assertSame(200, $create->status(), json_encode($create->json()));
        $this->assertArrayNotHasKey('errors', $create->json() ?? [], json_encode($create->json()));
        $plain = (string) $create->json('data.createStorefrontApiKey.plain_key');
        $keyId = (string) $create->json('data.createStorefrontApiKey.id');
        $this->assertStringStartsWith('pk_storefront_', $plain);
        $this->assertSame(250, (int) $create->json('data.createStorefrontApiKey.rate_limit'));
        $this->assertTrue(StorefrontApiKey::query()->whereKey((int) $keyId)->exists());

        $require = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation { updateStorefrontApiKeyRequirement(required: true) { ok id } }',
        ]);
        $require->assertOk();
        $this->assertArrayNotHasKey('errors', $require->json() ?? [], json_encode($require->json()));
        $this->assertTrue(ShopSetting::bool('api.require_storefront_key', false));

        $denied = $this->postJson('/api/v1/graphql', [
            'query' => '{ products(limit: 1) { items { id } } }',
        ]);
        $this->assertSame(401, $denied->status());

        $bad = $this->withHeader('X-STOREFRONT-KEY', 'pk_storefront_notreal')
            ->postJson('/api/v1/graphql', [
                'query' => '{ products(limit: 1) { items { id } } }',
            ]);
        $this->assertSame(401, $bad->status());

        $allowed = $this->withHeader('X-STOREFRONT-KEY', $plain)
            ->withHeader('X-Wento-Locale', 'en')
            ->withHeader('X-Wento-Currency', 'USD')
            ->postJson('/api/v1/graphql', [
                'query' => '{ products(limit: 1) { items { id } } }',
            ]);
        $this->assertSame(200, $allowed->status(), json_encode($allowed->json()));
        $this->assertArrayNotHasKey('errors', $allowed->json() ?? [], json_encode($allowed->json()));

        $list = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ storefrontApiKeys { id name key_prefix } }',
        ]);
        $list->assertOk();
        $this->assertArrayNotHasKey('errors', $list->json() ?? [], json_encode($list->json()));
        $ids = collect($list->json('data.storefrontApiKeys') ?? [])->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->assertContains($keyId, $ids);

        $revoke = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($id: ID!) { revokeStorefrontApiKey(id: $id) { ok id } }',
            'variables' => ['id' => $keyId],
        ]);
        $revoke->assertOk();
        $this->assertTrue((bool) $revoke->json('data.revokeStorefrontApiKey.ok'));
        $this->assertNull(StorefrontApiKey::query()->find((int) $keyId));

        // Clean up enforcement for other tests in the same process.
        ShopSetting::put('api.require_storefront_key', false);
    }
}
