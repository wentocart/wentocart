<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerGroup;
use App\Domain\Plugin\PluginManifest;
use App\Domain\Plugin\Support\ModuleCustomersWrite;
use App\Support\Hooks\HookManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ModuleCustomersWriteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        HookManager::flush();
    }

    public function test_assign_group_updates_code_and_id(): void
    {
        $vip = CustomerGroup::query()->where('code', 'vip')->firstOrFail();
        $customer = Customer::factory()->create([
            'customer_group' => 'retailer',
            'customer_group_id' => CustomerGroup::query()->where('code', 'retailer')->value('id'),
        ]);

        $this->writer(['customers.write'])->assignGroup((int) $customer->id, 'vip');

        $customer->refresh();
        $this->assertSame('vip', $customer->customer_group);
        $this->assertSame((int) $vip->id, (int) $customer->customer_group_id);
    }

    public function test_customers_write_requires_capability(): void
    {
        $customer = Customer::factory()->create(['customer_group' => 'retailer']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('customers.write');
        $this->writer(['customers.read'])->assignGroup((int) $customer->id, 'vip');
    }

    public function test_unknown_group_is_rejected(): void
    {
        $customer = Customer::factory()->create(['customer_group' => 'retailer']);

        try {
            $this->writer(['customers.write'])->assignGroup((int) $customer->id, 'not-a-group');
            $this->fail('unknown group should be rejected');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('customer_group', $e->errors());
        }
    }

    public function test_list_groups_returns_enabled_codes(): void
    {
        $groups = $this->writer(['customers.write'])->listGroups();
        $codes = collect($groups)->pluck('code')->all();

        $this->assertContains('retailer', $codes);
        $this->assertContains('vip', $codes);
    }

    public function test_customers_search_finds_by_email_with_read_capability(): void
    {
        $customer = Customer::factory()->create([
            'name' => 'Dropdown Buyer',
            'email' => 'dropdown.buyer@example.com',
        ]);

        $rows = $this->writer(['customers.read'])->search('dropdown.buyer', 10);
        $this->assertTrue(collect($rows)->contains(fn ($row) => (int) $row['id'] === (int) $customer->id));

        $found = $this->writer(['customers.read'])->find((int) $customer->id);
        $this->assertNotNull($found);
        $this->assertSame('dropdown.buyer@example.com', $found['email']);
    }

    public function test_customers_paginate_and_find_many_for_rule_builders(): void
    {
        $ray = Customer::factory()->create(['name' => 'Ray Subscriber', 'email' => 'ray@example.com']);
        $price = Customer::factory()->create(['name' => 'Price Member', 'email' => 'price@example.com']);

        $page = $this->writer(['customers.read'])->paginate('Ray', 1, 10);
        $this->assertGreaterThanOrEqual(1, $page['total']);
        $this->assertTrue(collect($page['data'])->contains(fn ($row) => (int) $row['id'] === (int) $ray->id));

        $many = $this->writer(['customers.read'])->findMany([(int) $ray->id, (int) $price->id]);
        $this->assertCount(2, $many);
    }

    /**
     * @param  list<string>  $capabilities
     */
    protected function writer(array $capabilities): ModuleCustomersWrite
    {
        $dir = storage_path('framework/testing/'.uniqid('custwrite_', true));
        mkdir($dir);
        file_put_contents($dir.'/app.json', json_encode([
            'vendor' => 'acme',
            'name' => 'members',
            'display_name' => 'Members',
            'version' => '1.0.0',
            'author' => 'test',
            'type' => 'safe',
            'description' => 'test',
            'capabilities' => $capabilities,
        ]));

        $manifest = PluginManifest::fromArray(
            json_decode((string) file_get_contents($dir.'/app.json'), true, 512, JSON_THROW_ON_ERROR),
            $dir,
            false
        );

        return new ModuleCustomersWrite($manifest);
    }
}
