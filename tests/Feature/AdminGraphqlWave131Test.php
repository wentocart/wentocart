<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Settings\CustomerSettings;
use App\Domain\Settings\ShopSetting;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminGraphqlWave131Test extends TestCase
{
    use RefreshDatabase;

    public function test_shop_profile_and_save_order_aliases(): void
    {
        $this->seed();
        ShopSetting::put('customers.allow_account_deletion', true);

        $customer = Customer::factory()->create([
            'password' => Hash::make('password'),
            'is_enabled' => true,
        ]);

        $profile = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => '{ createCustomerProfile { id email firstName } readCustomerProfile { id email } }',
        ]);
        $profile->assertOk();
        $this->assertArrayNotHasKey('errors', $profile->json() ?? [], json_encode($profile->json()));
        $this->assertSame((string) $customer->id, (string) $profile->json('data.createCustomerProfile.id'));
        $this->assertSame((string) $customer->id, (string) $profile->json('data.readCustomerProfile.id'));

        $downloads = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => '{ downloadableProducts(limit: 1) { id } }',
        ]);
        $downloads->assertOk();
        $this->assertArrayNotHasKey('errors', $downloads->json() ?? [], json_encode($downloads->json()));

        // createSaveOrder is wired; empty cart should fail with a checkout error (field exists).
        $save = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'mutation { createSaveOrder(input: {}) { order { id } } }',
        ]);
        $save->assertOk();
        $this->assertNotEmpty($save->json('errors'));
        $this->assertSame('createSaveOrder', (string) ($save->json('errors.0.path.0') ?? ''));

        $this->assertTrue(CustomerSettings::allowAccountDeletion());
        $del = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'mutation {
                deleteCustomerAccount(input: { password: "password" }) { success status message }
            }',
        ]);
        $del->assertOk();
        $this->assertArrayNotHasKey('errors', $del->json() ?? [], json_encode($del->json()));
        $this->assertTrue((bool) $del->json('data.deleteCustomerAccount.success'));
    }

    public function test_admin_customer_group_rest_aliases(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $code = 'w131-'.substr(uniqid(), -6);

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($code: String!) {
                createAdminCustomerGroup(input: {
                    name: "Wave 131 Group"
                    code: $code
                    discountPercent: 5
                    enabled: true
                }) { id code name discount_percent }
            }',
            'variables' => ['code' => $code],
        ]);
        $create->assertOk();
        $this->assertArrayNotHasKey('errors', $create->json() ?? [], json_encode($create->json()));
        $this->assertSame($code, (string) $create->json('data.createAdminCustomerGroup.code'));

        $list = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ adminCustomerGroups(limit: 50) { id code name } }',
        ]);
        $list->assertOk();
        $this->assertArrayNotHasKey('errors', $list->json() ?? [], json_encode($list->json()));
        $codes = collect($list->json('data.adminCustomerGroups') ?? [])->pluck('code')->all();
        $this->assertContains($code, $codes);
    }
}
