<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class GraphqlProductReviewAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_by_sku_returns_enabled_product(): void
    {
        $this->seed();
        $product = Product::query()->where('is_enabled', true)->whereNotNull('sku')->first();
        $this->assertNotNull($product);

        $response = $this->postJson('/api/v1/graphql', [
            'query' => 'query ($sku: String!) { productBySku(sku: $sku) { id sku name } }',
            'variables' => ['sku' => (string) $product->sku],
        ]);
        $response->assertOk();
        $this->assertArrayNotHasKey('errors', $response->json() ?? []);
        $this->assertSame((string) $product->id, (string) $response->json('data.productBySku.id'));
        $this->assertSame((string) $product->sku, (string) $response->json('data.productBySku.sku'));
    }

    public function test_create_review_requires_auth_and_saves_for_customer(): void
    {
        $this->seed();
        $product = Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);

        $anon = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation ($id: ID!) { createReview(productId: $id, rating: 5, title: "Great", body: "Solid buy") { id } }',
            'variables' => ['id' => (string) $product->id],
        ]);
        $anon->assertOk();
        $this->assertNotEmpty($anon->json('errors'));

        $customer = Customer::factory()->create();
        $create = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'mutation ($id: ID!) { createReview(productId: $id, rating: 5, title: "Great", body: "Solid buy for daily use") { id rating title customer_name } }',
            'variables' => ['id' => (string) $product->id],
        ]);
        $create->assertOk();
        $this->assertArrayNotHasKey('errors', $create->json() ?? []);
        $this->assertSame(5, (int) $create->json('data.createReview.rating'));
        $this->assertSame('Great', $create->json('data.createReview.title'));
        $this->assertNotEmpty($create->json('data.createReview.id'));
    }

    public function test_forgot_password_returns_ok_without_leaking_existence(): void
    {
        $this->seed();
        Customer::factory()->create(['email' => 'reset-me@example.com']);

        $known = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation { forgotPassword(email: "reset-me@example.com") { ok message } }',
        ]);
        $known->assertOk();
        $this->assertArrayNotHasKey('errors', $known->json() ?? []);
        $this->assertTrue((bool) $known->json('data.forgotPassword.ok'));

        $unknown = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation { forgotPassword(email: "nobody-here@example.com") { ok } }',
        ]);
        $unknown->assertOk();
        $this->assertArrayNotHasKey('errors', $unknown->json() ?? []);
        $this->assertTrue((bool) $unknown->json('data.forgotPassword.ok'));
    }

    public function test_reset_password_with_valid_token(): void
    {
        $this->seed();
        $customer = Customer::factory()->create(['email' => 'token-reset@example.com']);
        $token = Password::broker('customers')->createToken($customer);

        $response = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation ($e: String!, $t: String!, $p: String!) {
                resetPassword(email: $e, token: $t, password: $p, passwordConfirmation: $p) { ok message }
            }',
            'variables' => [
                'e' => 'token-reset@example.com',
                't' => $token,
                'p' => 'NewPass99',
            ],
        ]);
        $response->assertOk();
        $this->assertArrayNotHasKey('errors', $response->json() ?? []);
        $this->assertTrue((bool) $response->json('data.resetPassword.ok'));

        $this->assertTrue(Hash::check('NewPass99', $customer->fresh()->password));
    }
}
