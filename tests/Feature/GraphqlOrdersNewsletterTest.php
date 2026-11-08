<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketing\Models\NewsletterSubscriber;
use App\Domain\Order\Models\Order;
use App\Domain\Store\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GraphqlOrdersNewsletterTest extends TestCase
{
    use RefreshDatabase;

    public function test_newsletter_subscribe_mutation(): void
    {
        $this->seed();
        $email = 'gql-news-'.uniqid().'@example.test';

        $response = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation ($email: String!) { newsletterSubscribe(email: $email) { ok message } }',
            'variables' => ['email' => $email],
        ]);
        $response->assertOk();
        $this->assertArrayNotHasKey('errors', $response->json() ?? []);
        $this->assertTrue((bool) $response->json('data.newsletterSubscribe.ok'));
        $this->assertTrue(NewsletterSubscriber::query()->where('email', $email)->exists());
    }

    public function test_my_orders_requires_auth_and_lists(): void
    {
        $this->seed();
        $guest = $this->postJson('/api/v1/graphql', [
            'query' => '{ myOrders { id number } }',
        ]);
        $guest->assertOk();
        $this->assertNotEmpty($guest->json('errors'));

        $customer = Customer::factory()->create([
            'store_id' => app(StoreContext::class)->requireId(),
        ]);
        Order::createTrusted([
            'number' => 'GQL-ORD-1',
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'pending',
            'currency_code' => 'USD',
            'currency_rate' => 1,
            'subtotal' => 10,
            'tax_total' => 0,
            'tax_details' => [],
            'shipping_total' => 0,
            'discount_total' => 0,
            'grand_total' => 10,
            'payment_status' => 'pending',
            'billing_address' => ['first_name' => 'A', 'line1' => '1', 'city' => 'X', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'A', 'line1' => '1', 'city' => 'X', 'country_code' => 'US'],
        ]);

        $list = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => '{ myOrders(limit: 5) { number status grand_total } }',
        ]);
        $list->assertOk();
        $this->assertArrayNotHasKey('errors', $list->json() ?? []);
        $numbers = collect($list->json('data.myOrders') ?? [])->pluck('number')->all();
        $this->assertContains('GQL-ORD-1', $numbers);
    }
}
