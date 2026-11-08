<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Settings\ShopSetting;
use App\Domain\Store\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AdminGraphqlWave228Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_shop_customer_addresses_rest(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);

        $storeId = app(StoreContext::class)->requireId();
        $customer = Customer::factory()->create(['store_id' => $storeId, 'email' => 'addr228@example.test']);
        $other = Customer::factory()->create(['store_id' => $storeId]);

        $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/store/customer-addresses')
            ->assertOk()
            ->assertExactJson([]);

        $created = $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/store/customer-addresses', [
                'firstName' => 'Jane',
                'lastName' => 'Doe',
                'address1' => '456 Oak Ave',
                'address2' => 'Suite 200',
                'city' => 'Los Angeles',
                'state' => 'CA',
                'country' => 'US',
                'postcode' => '90001',
                'phone' => '9876543210',
                'defaultAddress' => true,
            ]);
        $created->assertCreated();
        $aid = (int) $created->json('id');
        $this->assertSame($aid, (int) $created->json('addressId'));
        $this->assertSame('456 Oak Ave', $created->json('address1'));
        $this->assertTrue((bool) $created->json('defaultAddress'));

        $list = $this->actingAs($customer, 'customer')->getJson('/api/v1/store/customer-addresses');
        $list->assertOk();
        $this->assertCount(1, $list->json());
        $this->assertSame('customer', $list->json('0.addressType'));
        $this->assertStringContainsString('456 Oak Ave', (string) $list->json('0.address'));
        $this->assertTrue((bool) $list->json('0.defaultAddress'));

        $updated = $this->actingAs($customer, 'customer')
            ->putJson('/api/v1/store/customer-addresses/'.$aid, [
                'city' => 'Boston',
                'address1' => '789 Pine Rd',
            ]);
        $updated->assertOk();
        $this->assertSame('Boston', $updated->json('city'));
        $this->assertSame('789 Pine Rd', $updated->json('address1'));

        $this->actingAs($other, 'customer')
            ->putJson('/api/v1/store/customer-addresses/'.$aid, ['city' => 'X'])
            ->assertNotFound();

        $this->actingAs($customer, 'customer')
            ->deleteJson('/api/v1/store/customer-addresses/'.$aid)
            ->assertNoContent();

        $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/store/customer-addresses')
            ->assertOk()
            ->assertExactJson([]);

        $this->actingAs($customer, 'customer')
            ->deleteJson('/api/v1/store/customer-addresses/'.$aid)
            ->assertNotFound();

        Auth::guard('customer')->logout();
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $guest = $this->withHeaders(['Authorization' => ''])->getJson('/api/v1/store/customer-addresses');
        $this->assertContains($guest->status(), [401, 403], (string) $guest->getContent());
    }
}
