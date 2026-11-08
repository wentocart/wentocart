<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerAddress;
use App\Domain\Order\Models\Order;
use App\Domain\Settings\ShopSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminGraphqlWave275Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_customer_rest_tree_and_core_aliases(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);

        $configs = $this->getJson('/api/v1/store-settings');
        $configs->assertOk();
        $this->assertIsArray($configs->json());

        $groups = $this->getJson('/api/v1/countries/states/groups');
        $groups->assertOk();
        $this->assertIsArray($groups->json('data'));

        $flat = $this->getJson('/api/v1/store/countries-states?per_page=5');
        $flat->assertOk();

        $customer = Customer::query()->create([
            'name' => 'Wave275 Shopper',
            'email' => 'w275@example.com',
            'password' => Hash::make('Password1!'),
            'is_enabled' => true,
        ]);
        $login = $this->postJson('/api/v1/customer/login', [
            'email' => 'w275@example.com',
            'password' => 'Password1!',
        ]);
        $login->assertCreated();
        $headers = ['Authorization' => 'Bearer '.(string) $login->json('token')];

        $me = $this->withHeaders($headers)->getJson('/api/v1/customer/get');
        $me->assertOk();
        $this->assertNotEmpty($me->json());

        $addr = $customer->addresses()->create([
            'first_name' => 'Wave',
            'last_name' => '275',
            'line1' => '1 Test St',
            'city' => 'Austin',
            'state' => 'TX',
            'country_code' => 'US',
            'postal_code' => '78701',
            'phone' => '555-0100',
            'is_default_billing' => false,
            'is_default_shipping' => false,
        ]);

        $makeDefault = $this->withHeaders($headers)
            ->patchJson('/api/v1/customer/addresses/make-default/'.$addr->id);
        $makeDefault->assertOk();
        $this->assertTrue((bool) $makeDefault->json('success'));
        $this->assertTrue((bool) $addr->fresh()->is_default_billing);

        $product = Product::query()->where('is_enabled', true)->where('type', 'simple')->firstOrFail();
        $this->withHeaders($headers)->postJson('/api/v1/customer/wishlist/'.$product->id)
            ->assertSuccessful();
        $wl = $this->withHeaders($headers)->getJson('/api/v1/customer/wishlist');
        $wl->assertOk();
        $this->assertNotEmpty($wl->json());

        $add = $this->withHeaders($headers)
            ->postJson('/api/v1/customer/cart/add/'.$product->id, ['quantity' => 1]);
        $add->assertOk();
        $this->assertGreaterThan(0, (int) ($add->json('itemsCount') ?? 0));

        $min = $this->withHeaders($headers)->postJson('/api/v1/customer/checkout/check-minimum-order');
        $this->assertContains($min->status(), [200, 422]);
        $this->assertArrayHasKey('minimumOrderAmount', $min->json() ?? []);

        Order::createTrusted([
            'number' => 'W275-'.uniqid(),
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'processing',
            'payment_status' => 'paid',
            'currency_code' => 'USD',
            'subtotal' => 10,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'fee_total' => 0,
            'grand_total' => 10,
            'refunded_total' => 0,
            'amount_paid' => 10,
            'amount_due' => 0,
            'billing_address' => [],
            'shipping_address' => [],
        ]);

        $txs = $this->withHeaders($headers)->getJson('/api/v1/customer/transactions');
        $txs->assertOk();
        $this->assertNotEmpty($txs->json());

        $gdpr = $this->withHeaders($headers)->getJson('/api/v1/customer/gdpr');
        // may be empty list or disabled message
        $this->assertContains($gdpr->status(), [200, 400]);
    }
}
