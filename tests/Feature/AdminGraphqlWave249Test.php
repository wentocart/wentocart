<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Settings\ShopSetting;
use App\Domain\Store\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AdminGraphqlWave249Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_shop_customer_profile_deletes(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);
        ShopSetting::put('customers.allow_account_deletion', true);

        $storeId = app(StoreContext::class)->requireId();
        $customer = Customer::factory()->create([
            'store_id' => $storeId,
            'email' => 'delete.w249@example.com',
            'password' => Hash::make('password'),
        ]);
        $other = Customer::factory()->create([
            'store_id' => $storeId,
            'email' => 'other.w249@example.com',
        ]);
        $token = $customer->createToken('shop')->plainTextToken;
        $tokenId = (int) explode('|', $token, 2)[0];

        $foreign = $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/store/customer-profile-deletes/'.$other->id, []);
        $foreign->assertNotFound();

        $deleted = $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/store/customer-profile-deletes/'.$customer->id, []);
        $deleted->assertCreated();
        // API: 201 with JSON null body (literal "null")
        $this->assertSame('null', $deleted->getContent());

        $erased = Customer::query()->find($customer->id);
        $this->assertNotNull($erased);
        $this->assertStringContainsString('@anonymized.invalid', (string) $erased->email);
        $this->assertFalse((bool) $erased->is_enabled);
        // Original email is free for re-registration (API parity / beat)
        $this->assertNull(Customer::query()->where('email', 'delete.w249@example.com')->first());
        $this->assertNull(PersonalAccessToken::query()->find($tokenId));
    }
}
