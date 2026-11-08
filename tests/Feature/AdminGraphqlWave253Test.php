<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketing\Models\NewsletterSubscriber;
use App\Domain\Settings\ShopSetting;
use App\Domain\Store\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminGraphqlWave253Test extends TestCase
{
    use RefreshDatabase;

    public function test_verify_tokens_and_newsletter_unsubscribe(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);
        $storeId = app(StoreContext::class)->requireId();

        $customer = Customer::factory()->create([
            'store_id' => $storeId,
            'email' => 'verify.w253@example.com',
            'first_name' => 'Vera',
            'last_name' => 'Token',
            'password' => Hash::make('password'),
            'is_enabled' => true,
        ]);
        $plain = $customer->createToken('shop')->plainTextToken;

        // No Authorization — must stay isValid=false (always HTTP 201)
        $invalid = $this->postJson('/api/v1/store/verify-tokens', []);
        $invalid->assertCreated();
        $this->assertFalse((bool) $invalid->json('isValid'));
        $this->assertSame(0, (int) $invalid->json('id'));

        $valid = $this->withToken($plain)->postJson('/api/v1/store/verify-tokens', []);
        $valid->assertCreated();
        $this->assertTrue((bool) $valid->json('isValid'));
        $this->assertSame((int) $customer->id, (int) $valid->json('id'));
        $this->assertSame('Vera', (string) $valid->json('firstName'));
        $this->assertSame('verify.w253@example.com', (string) $valid->json('email'));

        $bogus = $this->withHeader('Authorization', 'Bearer 999999|not-a-real-token')
            ->postJson('/api/v1/store/verify-tokens', []);
        $bogus->assertCreated();
        $this->assertFalse((bool) $bogus->json('isValid'));

        $email = 'unsub.w253@example.com';
        NewsletterSubscriber::query()->create([
            'email' => $email,
            'is_subscribed' => true,
        ]);
        $unsub = $this->postJson('/api/v1/store/unsubscribe-newsletters', [
            'customerEmail' => $email,
        ]);
        $unsub->assertOk();
        $this->assertTrue((bool) $unsub->json('success'));
        $this->assertFalse((bool) NewsletterSubscriber::query()->where('email', $email)->value('is_subscribed'));

        $again = $this->deleteJson('/api/v1/store/newsletters', ['email' => $email]);
        $again->assertOk();
        $this->assertStringContainsString('Already unsubscribed', (string) $again->json('message'));
    }
}
