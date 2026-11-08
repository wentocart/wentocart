<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Store\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GraphqlBearerAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_issues_access_token_when_device_name_set(): void
    {
        $this->seed();
        $customer = Customer::factory()->create([
            'store_id' => app(StoreContext::class)->requireId(),
            'email' => 'bearer-login@example.test',
            'password' => 'Password1a',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $customer->email,
            'password' => 'Password1a',
            'device_name' => 'phpunit-headless',
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('access_token'));
        $this->assertSame('Bearer', $response->json('token_type'));
    }

    public function test_graphql_wishlist_accepts_bearer_token(): void
    {
        $this->seed();
        $customer = Customer::factory()->create([
            'store_id' => app(StoreContext::class)->requireId(),
        ]);
        $product = Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);
        $token = $customer->createToken('graphql-test')->plainTextToken;

        // No session — Authorization header only.
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $toggle = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/graphql', [
                'query' => 'mutation ($id: ID!) { wishlistToggle(productId: $id) { wishlisted product_id } }',
                'variables' => ['id' => (string) $product->id],
            ]);

        $toggle->assertOk();
        $this->assertArrayNotHasKey('errors', $toggle->json() ?? []);
        $this->assertTrue((bool) $toggle->json('data.wishlistToggle.wishlisted'));
        $this->assertTrue($customer->fresh()->wishlist()->where('product_id', $product->id)->exists());
    }
}
