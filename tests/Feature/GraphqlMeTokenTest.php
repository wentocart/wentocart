<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Store\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class GraphqlMeTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_me_query_returns_null_for_guest(): void
    {
        $this->seed();

        $response = $this->postJson('/api/v1/graphql', [
            'query' => '{ me { id email } }',
        ]);
        $response->assertOk();
        $this->assertArrayNotHasKey('errors', $response->json() ?? []);
        $this->assertNull($response->json('data.me'));
    }

    public function test_me_and_revoke_access_token_via_bearer(): void
    {
        $this->seed();
        $customer = Customer::factory()->create([
            'store_id' => app(StoreContext::class)->requireId(),
            'name' => 'Bearer Me',
        ]);
        $plain = $customer->createToken('gql-me')->plainTextToken;
        $tokenId = PersonalAccessToken::findToken($plain)?->id;
        $this->assertNotNull($tokenId);

        $me = $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson('/api/v1/graphql', [
                'query' => '{ me { id email name } }',
            ]);
        $me->assertOk();
        $this->assertArrayNotHasKey('errors', $me->json() ?? []);
        $this->assertSame((string) $customer->id, (string) $me->json('data.me.id'));
        $this->assertSame($customer->email, $me->json('data.me.email'));

        $revoke = $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson('/api/v1/graphql', [
                'query' => 'mutation { revokeAccessToken { ok } }',
            ]);
        $revoke->assertOk();
        $this->assertTrue((bool) $revoke->json('data.revokeAccessToken.ok'));
        $this->assertNull(PersonalAccessToken::query()->find($tokenId));
    }

    public function test_rest_logout_revokes_bearer_token(): void
    {
        $this->seed();
        $customer = Customer::factory()->create([
            'store_id' => app(StoreContext::class)->requireId(),
        ]);
        $plain = $customer->createToken('logout-me')->plainTextToken;
        $tokenId = PersonalAccessToken::findToken($plain)?->id;
        $this->assertNotNull($tokenId);

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertNull(PersonalAccessToken::query()->find($tokenId));
    }
}
