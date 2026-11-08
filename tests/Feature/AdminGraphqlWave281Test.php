<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminGraphqlWave281Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_admin_auth_and_customers_crud_mass_addresses(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $admin->password = Hash::make('AdminPass1!');
        $admin->save();
        $suffix = strtolower(substr(uniqid(), -6));

        $login = $this->postJson('/api/v1/console/login', [
            'email' => $admin->email,
            'password' => 'AdminPass1!',
            'device_name' => 'wave281',
        ]);
        $login->assertOk();
        $token = (string) $login->json('token');
        $this->assertNotEmpty($token);

        $me = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/console/get');
        $me->assertOk();
        $this->assertSame((int) $admin->id, (int) $me->json('data.id'));

        $logout = $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/v1/console/logout');
        $logout->assertOk();
        // Drop stale Bearer so BindAdminIntegrationAuth does not clear session auth.
        $this->withHeader('Authorization', '');

        $forgot = $this->postJson('/api/v1/console/forgot-password', [
            'email' => $admin->email,
        ]);
        $this->assertContains($forgot->status(), [200, 400, 422]);

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/customers', [
            'email' => 'w281-'.$suffix.'@example.com',
            'first_name' => 'Wave',
            'last_name' => '281',
            'password' => 'Password1!',
            'status' => 1,
        ]);
        $create->assertCreated();
        $customerId = (int) $create->json('id');
        $this->assertGreaterThan(0, $customerId);

        $addr = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/customers/'.$customerId.'/addresses', [
            'first_name' => 'Wave',
            'last_name' => '281',
            'line1' => '10 Market St',
            'city' => 'Austin',
            'state' => 'TX',
            'postal_code' => '78701',
            'country_code' => 'US',
            'is_default_billing' => true,
            'is_default_shipping' => true,
        ]);
        $addr->assertCreated();
        $addressId = (int) $addr->json('data.id');
        $this->assertGreaterThan(0, $addressId);

        $this->actingAs($admin, 'admin')
            ->getJson('/api/v1/console/customers/'.$customerId.'/addresses')
            ->assertOk();

        $mass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/customers/bulk-update', [
            'ids' => [$customerId],
            'value' => 0,
        ]);
        $mass->assertOk();
        $this->assertFalse((bool) Customer::query()->find($customerId)?->is_enabled);

        $destroy = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/customers/bulk-delete', [
            'ids' => [$customerId],
        ]);
        $destroy->assertOk();
        $this->assertNull(Customer::query()->find($customerId));
    }
}
