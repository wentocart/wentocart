<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Settings\ShopSetting;
use App\Domain\Store\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminGraphqlWave242Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_shop_customer_profile_updates(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);

        $storeId = app(StoreContext::class)->requireId();
        $customer = Customer::factory()->create([
            'store_id' => $storeId,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada.w242@example.com',
            'password' => Hash::make('OldPass123!'),
            'phone' => '5550001111',
        ]);
        $other = Customer::factory()->create([
            'store_id' => $storeId,
            'email' => 'other.w242@example.com',
        ]);

        $ok = $this->actingAs($customer, 'customer')->putJson(
            '/api/v1/store/customer-profile-updates/'.$customer->id,
            [
                'firstName' => 'Augusta',
                'lastName' => 'Lovelace',
                'phone' => '5550002222',
                'gender' => 'Female',
                'dateOfBirth' => '1815-12-10',
                'subscribedToNewsLetter' => true,
            ]
        );
        $ok->assertOk();
        $this->assertTrue((bool) $ok->json('success'));
        $this->assertSame('Augusta', (string) $ok->json('firstName'));
        $this->assertSame('5550002222', (string) $ok->json('phone'));
        $this->assertSame('Female', (string) $ok->json('gender'));
        $this->assertSame('1815-12-10', (string) $ok->json('dateOfBirth'));
        $this->assertTrue((bool) $ok->json('subscribedToNewsLetter'));
        $this->assertContains((string) $ok->json('isVerified'), ['true', 'false', '1', '0']);

        $badPhone = $this->actingAs($customer, 'customer')->putJson(
            '/api/v1/store/customer-profile-updates/'.$customer->id,
            ['phone' => '+1 (555) 000-3333']
        );
        $badPhone->assertStatus(400);
        $this->assertStringContainsString('digits', (string) $badPhone->json('message'));

        $taken = $this->actingAs($customer, 'customer')->putJson(
            '/api/v1/store/customer-profile-updates/'.$customer->id,
            ['email' => $other->email]
        );
        $taken->assertStatus(400);

        $foreign = $this->actingAs($customer, 'customer')->putJson(
            '/api/v1/store/customer-profile-updates/'.$other->id,
            ['firstName' => 'Nope']
        );
        $foreign->assertNotFound();

        $pwd = $this->actingAs($customer, 'customer')->putJson(
            '/api/v1/store/customer-profile-updates/'.$customer->id,
            [
                'currentPassword' => 'OldPass123!',
                'password' => 'NewPass123!',
                'confirmPassword' => 'NewPass123!',
            ]
        );
        $pwd->assertOk();
        $this->assertTrue(Hash::check('NewPass123!', (string) $customer->fresh()->password));
    }
}
