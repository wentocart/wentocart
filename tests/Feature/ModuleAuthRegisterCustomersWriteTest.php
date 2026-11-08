<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Plugin\PluginManifest;
use App\Domain\Plugin\Support\ModuleAuth;
use App\Domain\Plugin\Support\ModuleBuses;
use App\Domain\Plugin\Support\ModuleCustomersWrite;
use App\Support\Hooks\HookManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ModuleAuthRegisterCustomersWriteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        HookManager::flush();
        ModuleBuses::flush();
    }

    public function test_register_pending_approval_skips_login(): void
    {
        $this->authApi(['auth.register'])->configureRegister(fn () => [
            'is_enabled' => false,
            'auto_login' => false,
        ]);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Pending User',
            'email' => 'pending@example.com',
            'password' => 'Password1a',
            'password_confirmation' => 'Password1a',
        ]);

        $response->assertCreated()
            ->assertJsonPath('pending', true)
            ->assertJsonPath('reason', 'approval');
        $customer = Customer::query()->where('email', 'pending@example.com')->first();
        $this->assertNotNull($customer);
        $this->assertFalse((bool) $customer->is_enabled);
        $this->assertGuest('customer');
    }

    public function test_customers_write_set_enabled_and_profile(): void
    {
        $customer = Customer::factory()->create([
            'is_enabled' => false,
            'password' => Hash::make('password123'),
        ]);

        $writer = $this->customersApi(['customers.write']);
        $writer->setEnabled((int) $customer->id, true);
        $writer->setProfile((int) $customer->id, [
            'company' => 'Acme Dealers',
            'account_manager' => 'rep-7',
            'credit_limit' => '5000',
            'custom_vat' => 'GB123',
        ]);

        $customer->refresh();
        $this->assertTrue((bool) $customer->is_enabled);
        $this->assertSame('Acme Dealers', $customer->profile['company'] ?? null);
        $this->assertSame('rep-7', $customer->profile['account_manager'] ?? null);
        $this->assertSame('GB123', $customer->profile['custom_vat'] ?? null);
    }

    public function test_on_registered_receives_extra_fields(): void
    {
        $seen = [];
        $this->authApi(['auth.register'])->onRegistered(function (int $id, array $extra) use (&$seen) {
            $seen = ['id' => $id, 'extra' => $extra];
        });

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Extra User',
            'email' => 'extra@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'extra' => ['company' => 'Widgets Ltd'],
        ])->assertOk();

        $this->assertSame('Widgets Ltd', $seen['extra']['company'] ?? null);
        $customer = Customer::query()->find($seen['id'] ?? 0);
        $this->assertSame('Widgets Ltd', $customer?->profile['company'] ?? null);
    }

    /**
     * @param  list<string>  $capabilities
     */
    protected function authApi(array $capabilities): ModuleAuth
    {
        return new ModuleAuth($this->manifest($capabilities));
    }

    /**
     * @param  list<string>  $capabilities
     */
    protected function customersApi(array $capabilities): ModuleCustomersWrite
    {
        return new ModuleCustomersWrite($this->manifest($capabilities));
    }

    /**
     * @param  list<string>  $capabilities
     */
    protected function manifest(array $capabilities): PluginManifest
    {
        $dir = storage_path('framework/testing/'.uniqid('reg_', true));
        mkdir($dir);
        file_put_contents($dir.'/app.json', json_encode([
            'vendor' => 'acme',
            'name' => 'reg',
            'display_name' => 'Reg',
            'version' => '1.0.0',
            'author' => 'test',
            'type' => 'safe',
            'description' => 'test',
            'capabilities' => $capabilities,
        ]));

        return PluginManifest::fromArray(
            json_decode((string) file_get_contents($dir.'/app.json'), true, 512, JSON_THROW_ON_ERROR),
            $dir,
            false
        );
    }
}
