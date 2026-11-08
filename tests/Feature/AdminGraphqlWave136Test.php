<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketing\Models\NewsletterSubscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave136Test extends TestCase
{
    use RefreshDatabase;

    public function test_create_account_and_unsubscribe_newsletter_aliases(): void
    {
        $this->seed();
        $email = 'w136-'.uniqid().'@example.com';

        $reg = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation ($email: String!) {
                createAccount(input: {
                    firstName: "Wave"
                    lastName: "136"
                    email: $email
                    password: "Password123!"
                    confirmPassword: "Password123!"
                    subscribedToNewsLetter: true
                }) {
                    success
                    customer { id email firstName }
                    accessToken
                }
            }',
            'variables' => ['email' => $email],
        ]);
        $reg->assertOk();
        $this->assertArrayNotHasKey('errors', $reg->json() ?? [], json_encode($reg->json()));
        $this->assertTrue((bool) $reg->json('data.createAccount.success') || $reg->json('data.createAccount.customer.id'));
        $this->assertSame($email, (string) $reg->json('data.createAccount.customer.email'));
        $this->assertNotNull(Customer::query()->where('email', $email)->first());

        $sub = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation ($email: String!) {
                createNewsletter(input: { email: $email }) { ok success message }
            }',
            'variables' => ['email' => $email],
        ]);
        $sub->assertOk();
        $this->assertArrayNotHasKey('errors', $sub->json() ?? [], json_encode($sub->json()));
        $this->assertTrue((bool) $sub->json('data.createNewsletter.ok'));

        $unsub = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation ($email: String!) {
                createUnsubscribeNewsletter(input: { email: $email }) { ok success message }
            }',
            'variables' => ['email' => $email],
        ]);
        $unsub->assertOk();
        $this->assertArrayNotHasKey('errors', $unsub->json() ?? [], json_encode($unsub->json()));
        $this->assertTrue((bool) $unsub->json('data.createUnsubscribeNewsletter.ok'));
        $row = NewsletterSubscriber::query()->where('email', $email)->first();
        $this->assertNotNull($row);
        $this->assertFalse((bool) $row->is_subscribed);
    }
}
