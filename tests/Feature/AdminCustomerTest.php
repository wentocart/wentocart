<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminCustomerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_and_create_customers(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        Customer::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);

        $this->get('/admin/customers')->assertOk()->assertSee('Customers');
        $this->get('/admin/customers/create')->assertOk();

        $this->post('/admin/customers', [
            'first_name' => 'Pat',
            'last_name' => 'Shopper',
            'email' => 'pat.admin@example.com',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
            'customer_group' => 'vip',
            'is_enabled' => true,
            'newsletter' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('customers', [
            'email' => 'pat.admin@example.com',
            'name' => 'Pat Shopper',
            'customer_group' => 'vip',
        ]);
    }

    public function test_creating_customer_reports_all_required_fields(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->from('/admin/customers/create')
            ->post('/admin/customers', [])
            ->assertRedirect('/admin/customers/create')
            ->assertSessionHasErrors(['first_name', 'last_name', 'email', 'password', 'customer_group']);

        $this->assertSame('Customer group is required.', session('errors')->first('customer_group'));

        $passwordError = session('errors')->first('password');
        $this->assertSame('Enter a password.', $passwordError);
    }

    public function test_creating_customer_reports_short_password_only_when_filled(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->from('/admin/customers/create')
            ->post('/admin/customers', [
                'first_name' => 'Pat',
                'last_name' => 'Shopper',
                'email' => 'pat.short@example.com',
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertRedirect('/admin/customers/create')
            ->assertSessionHasErrors('password')
            ->assertSessionDoesntHaveErrors(['first_name', 'last_name', 'email']);
    }

    public function test_admin_customer_list_filters_status_and_numeric_ranges(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $active = Customer::factory()->create([
            'first_name' => 'Active',
            'last_name' => 'Filter',
            'name' => 'Active Filter',
            'email' => 'active.filter@example.com',
            'is_enabled' => true,
        ]);
        Customer::factory()->create([
            'first_name' => 'Inactive',
            'last_name' => 'Filter',
            'name' => 'Inactive Filter',
            'email' => 'inactive.filter@example.com',
            'is_enabled' => false,
        ]);

        $this->get('/admin/customers?status=active&name=Active+Filter')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Customers/Index', false)
                ->has('customers.data', 1)
                ->where('customers.data.0.email', 'active.filter@example.com')
                ->where('filters.status', 'active'));

        $this->get('/admin/customers?status=inactive&name=Inactive+Filter')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('customers.data', 1)
                ->where('customers.data.0.email', 'inactive.filter@example.com')
                ->where('filters.status', 'inactive'));

        $this->get('/admin/customers?id_min='.$active->id.'&id_max='.$active->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('customers.data', 1)
                ->where('customers.data.0.id', $active->id)
                ->where('filters.id_min', (string) $active->id));

        $this->from('/admin/customers')->post("/admin/customers/{$active->id}/toggle")->assertRedirect();
        $this->assertFalse((bool) $active->fresh()->is_enabled);

        $this->get('/admin/customer-groups?status=active&discount_min=0&discount_max=100&customers_min=0')
            ->assertOk();
    }

    public function test_admin_can_update_customer_status(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
        $customer = Customer::factory()->create(['is_enabled' => true]);

        $this->put("/admin/customers/{$customer->id}", [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => $customer->email,
            'is_enabled' => false,
        ])->assertRedirect();

        $this->assertFalse($customer->fresh()->is_enabled);
        $this->assertSame('Ada Lovelace', $customer->fresh()->name);
    }

    public function test_admin_can_upload_customer_photo(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
        Storage::fake('public');

        $customer = Customer::factory()->create([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada.photo@example.com',
        ]);

        $this->post("/admin/customers/{$customer->id}", [
            '_method' => 'put',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => $customer->email,
            'is_enabled' => true,
            'avatar' => UploadedFile::fake()->image('portrait.jpg', 120, 120),
        ])->assertRedirect();

        $customer->refresh();
        $this->assertNotNull($customer->avatar_path);
        Storage::disk('public')->assertExists($customer->avatar_path);
        $this->assertSame('/storage/'.$customer->avatar_path, $customer->avatarUrl());
    }

    public function test_default_addresses_are_saved_and_returned(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
        $customer = Customer::factory()->create([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada.addr@example.com',
        ]);

        $this->put("/admin/customers/{$customer->id}", [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => $customer->email,
            'is_enabled' => true,
            'addresses' => json_encode([
                [
                    'label' => 'Home',
                    'line1' => '12 Market Street',
                    'city' => 'Mumbai',
                    'country_code' => 'IN',
                    'is_default_billing' => true,
                    'is_default_shipping' => true,
                ],
            ]),
            'default_billing' => '',
            'default_shipping' => '',
        ])->assertRedirect();

        $customer->refresh()->load('addresses');
        $this->assertCount(1, $customer->addresses);
        $this->assertTrue($customer->addresses->first()->is_default_billing);
        $this->assertTrue($customer->addresses->first()->is_default_shipping);

        $this->get('/admin/addresses')
            ->assertOk()
            ->assertSee('12 Market Street');
    }

    public function test_admin_can_save_address_immediately_on_existing_customer(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
        $customer = Customer::factory()->create([
            'first_name' => 'Aarav',
            'last_name' => 'Sharma',
            'email' => 'aarav.addr@example.com',
        ]);

        $this->post("/admin/customers/{$customer->id}/addresses", [
            'label' => 'Home',
            'line1' => '14 Park Lane',
            'city' => 'Delhi',
            'postal_code' => '110001',
            'country_code' => 'IN',
            'is_default_shipping' => true,
            'is_default_billing' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('customer_addresses', [
            'customer_id' => $customer->id,
            'line1' => '14 Park Lane',
            'city' => 'Delhi',
        ]);

        $this->get('/admin/addresses')
            ->assertOk()
            ->assertSee('14 Park Lane');
    }

    public function test_admin_can_manage_addresses_from_the_addresses_page(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
        $customer = Customer::factory()->create([
            'first_name' => 'Neha',
            'last_name' => 'Patel',
            'email' => 'neha.addr@example.com',
        ]);

        $this->get('/admin/addresses')->assertOk()->assertSee('Addresses');

        $this->get('/admin/addresses/search/customers?q=Neha')
            ->assertOk()
            ->assertJsonFragment(['id' => $customer->id]);

        $this->post('/admin/addresses', [
            'customer_id' => $customer->id,
            'label' => 'Office',
            'line1' => '88 Commerce Row',
            'city' => 'Pune',
            'country_code' => 'IN',
            'is_default_shipping' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('customer_addresses', [
            'customer_id' => $customer->id,
            'line1' => '88 Commerce Row',
        ]);

        $address = $customer->addresses()->first();
        $this->put("/admin/addresses/{$address->id}", [
            'label' => 'Office',
            'line1' => '88 Commerce Row',
            'city' => 'Pune',
            'country_code' => 'IN',
            'is_default_billing' => true,
            'is_default_shipping' => true,
        ])->assertRedirect();

        $this->assertTrue($address->fresh()->is_default_billing);

        $this->delete("/admin/addresses/{$address->id}")->assertRedirect();
        $this->assertDatabaseMissing('customer_addresses', ['id' => $address->id]);
    }

    public function test_admin_can_edit_and_delete_customer_groups_from_the_groups_page(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->get('/admin/customer-groups')->assertOk()->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->component('Customers/Groups')
            ->where('groups', fn ($groups) => collect($groups)->pluck('code')->contains('visitor'))
        );

        $this->post('/admin/customer-groups', [
            'name' => 'Gold',
            'code' => 'gold',
            'color' => 'warning',
            'discount_percent' => 10,
            'is_enabled' => true,
        ])->assertRedirect();

        $group = \App\Domain\Customer\Models\CustomerGroup::query()->where('code', 'gold')->first();
        $this->assertNotNull($group);

        $this->put("/admin/customer-groups/{$group->id}", [
            'name' => 'Gold Club',
            'color' => 'warning',
            'discount_percent' => 12,
            'is_enabled' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('customer_groups', [
            'id' => $group->id,
            'name' => 'Gold Club',
        ]);

        $this->delete("/admin/customer-groups/{$group->id}")->assertRedirect();
        $this->assertDatabaseMissing('customer_groups', ['id' => $group->id]);
    }

    public function test_admin_can_import_customers_without_rehashing_each_row(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $existing = Customer::factory()->create([
            'name' => 'Old Name',
            'email' => 'import.existing@example.com',
        ]);

        $csv = implode("\n", [
            'id,name,email,phone,group,status,created_at',
            '1,Ada Lovelace,ada.import@example.com,555,vip,active,2026-01-01 00:00:00',
            $existing->id.',Updated Name,import.existing@example.com,555,wholesale,inactive,2026-01-01 00:00:00',
        ]);

        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('customers.csv', $csv);

        $this->post('/admin/customers/import', ['file' => $file])->assertRedirect();

        $this->assertDatabaseHas('customers', [
            'email' => 'ada.import@example.com',
            'name' => 'Ada Lovelace',
            'customer_group' => 'vip',
        ]);
        $this->assertDatabaseHas('customers', [
            'email' => 'import.existing@example.com',
            'name' => 'Updated Name',
            'customer_group' => 'wholesale',
            'is_enabled' => false,
        ]);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('password', $existing->fresh()->password));
    }

    public function test_admin_can_create_customer_group_and_assign_it(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->post('/admin/customer-groups', [
            'name' => 'Staff',
            'code' => 'staff',
            'color' => 'success',
            'discount_percent' => 5,
            'tax_exempt' => true,
            'description' => 'Store staff accounts',
            'is_enabled' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('customer_groups', [
            'code' => 'staff',
            'name' => 'Staff',
            'color' => '#16A34A',
            'tax_exempt' => true,
        ]);

        $this->post('/admin/customers', [
            'first_name' => 'Sam',
            'last_name' => 'Staff',
            'email' => 'sam.staff@example.com',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
            'customer_group' => 'staff',
            'is_enabled' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('customers', [
            'email' => 'sam.staff@example.com',
            'customer_group' => 'staff',
        ]);
    }
}
