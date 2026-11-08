<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Shipping\Models\ShippingMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountStorefrontTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_shop_has_checkout_options_filtered_by_cart(): void
    {
        $this->seed();

        $this->assertGreaterThanOrEqual(2, PaymentMethod::query()->count());
        $this->assertGreaterThanOrEqual(2, ShippingMethod::query()->count());

        $empty = $this->getJson('/api/v1/checkout/options')->assertOk();
        $this->assertGreaterThanOrEqual(2, count($empty->json('payment_methods')));
        // Free shipping has min_order — hidden on an empty cart.
        $this->assertNotContains('free', collect($empty->json('shipping_methods'))->pluck('code')->all());

        $product = \App\Domain\Catalog\Models\Product::query()->where('is_enabled', true)->firstOrFail();
        $this->postJson('/api/v1/cart', ['product_id' => $product->id, 'qty' => 10])->assertOk();
        $full = $this->getJson('/api/v1/checkout/options')->assertOk();
        $this->assertGreaterThanOrEqual(1, count($full->json('shipping_methods')));
    }

    public function test_customer_can_manage_profile_and_addresses(): void
    {
        $this->seed();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertOk();

        $ada = Customer::query()->where('email', 'ada@example.com')->first();
        $this->assertSame('Ada', $ada?->first_name);
        $this->assertSame('Lovelace', $ada?->last_name);

        $this->patchJson('/api/v1/account', [
            'name' => 'Ada L',
            'phone' => '+1 555 2222',
            'newsletter' => true,
        ])->assertOk()->assertJsonPath('customer.phone', '+1 555 2222');

        $this->postJson('/api/v1/account/addresses', [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'line1' => '12 Analytical Engine Rd',
            'city' => 'London',
            'country_code' => 'GB',
            'is_default_shipping' => true,
            'is_default_billing' => true,
        ])->assertCreated();

        $addressId = Customer::query()->where('email', 'ada@example.com')->first()->addresses()->value('id');
        $this->patchJson('/api/v1/account/addresses/'.$addressId, [
            'first_name' => 'Ada',
            'line1' => '13 Analytical Engine Rd',
            'city' => 'London',
            'country_code' => 'GB',
        ])->assertOk()->assertJsonPath('address.line1', '13 Analytical Engine Rd');

        $this->get('/api/v1/account')->assertOk()->assertJsonPath('stats.addresses', 1);
        $this->get('/api/v1/account/reviews')->assertOk()->assertJsonPath('reviews', []);
        $this->getJson('/api/v1/account/vouchers')->assertOk()->assertJsonPath('vouchers', []);
    }

    public function test_register_saves_first_and_last_name_exactly(): void
    {
        $this->seed();

        $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Mary Jane',
            'last_name' => 'Watson',
            'email' => 'mj@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'phone' => '+1 555 0100',
            'date_of_birth' => '1990-05-12',
            'gender' => 'female',
        ])->assertOk();

        $customer = Customer::query()->where('email', 'mj@example.com')->first();
        $this->assertSame('Mary Jane', $customer?->first_name);
        $this->assertSame('Watson', $customer?->last_name);
        $this->assertSame('Mary Jane Watson', $customer?->name);
        $this->assertSame('+1 555 0100', $customer?->phone);
        $this->assertSame('1990-05-12', $customer?->date_of_birth?->toDateString());
        $this->assertSame('female', $customer?->gender);
    }
}
