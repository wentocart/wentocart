<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Checkout\Models\Cart;
use App\Domain\Checkout\Services\CartService;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Plugin\PluginManifest;
use App\Domain\Plugin\Support\ModuleBuses;
use App\Domain\Plugin\Support\ModuleCarts;
use App\Domain\Plugin\Support\ModuleCatalogWrite;
use App\Domain\Plugin\Support\ModuleOrdersWrite;
use App\Domain\Plugin\Support\ModuleSchedule;
use App\Models\Admin;
use App\Support\Hooks\HookManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ModuleOpsBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        HookManager::flush();
        ModuleBuses::flush();
    }

    public function test_carts_read_lists_abandoned_carts_without_session_id(): void
    {
        $customer = Customer::factory()->create(['email' => 'leave@example.com', 'name' => 'Leaver']);
        $product = Product::query()->where('is_enabled', true)->firstOrFail();
        $carts = app(CartService::class);
        $cart = $carts->current($customer);
        $carts->add($cart, $product, 2);
        Cart::query()->whereKey($cart->id)->update(['updated_at' => now()->subHours(3)]);

        $api = $this->cartsApi(['carts.read']);
        $rows = $api->abandoned(60, 20);

        $this->assertNotEmpty($rows);
        $match = collect($rows)->firstWhere('id', $cart->id);
        $this->assertNotNull($match);
        $this->assertSame('leave@example.com', $match['email']);
        $this->assertSame(1, $match['item_count']);
        $this->assertSame(2, (int) ($match['items'][0]['qty'] ?? 0));
        $this->assertArrayNotHasKey('session_id', $match);
    }

    public function test_schedule_tick_runs_registered_callbacks(): void
    {
        $hits = 0;
        $this->scheduleApi(['schedule.tick'])->onTick(function () use (&$hits) {
            $hits++;
        });

        $this->assertSame(1, ModuleBuses::runScheduleTicks());
        $this->assertSame(1, $hits);
    }

    public function test_orders_write_assigns_staff(): void
    {
        $admin = Admin::factory()->create(['is_enabled' => true]);
        $order = Order::createTrusted([
            'number' => 'TEST-ASSIGN-1',
            'email' => 'buyer@example.com',
            'status' => 'pending',
            'currency_code' => 'USD',
            'currency_rate' => 1,
            'subtotal' => 50,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'fee_total' => 0,
            'grand_total' => 50,
            'payment_status' => 'pending',
            'billing_address' => ['line1' => '1 Main'],
            'shipping_address' => ['line1' => '1 Main'],
        ]);

        $writer = $this->ordersApi(['orders.write']);
        $staff = $writer->listStaff();
        $this->assertTrue(collect($staff)->contains(fn ($row) => (int) $row['id'] === (int) $admin->id));

        $writer->assignAdmin((int) $order->id, (int) $admin->id);
        $order->refresh();
        $this->assertSame((int) $admin->id, (int) $order->assigned_admin_id);

        $writer->assignAdmin((int) $order->id, null);
        $order->refresh();
        $this->assertNull($order->assigned_admin_id);
    }

    public function test_catalog_write_updates_sale_price(): void
    {
        $writer = $this->catalogApi(['catalog.write']);
        $id = $writer->createProduct([
            'name' => 'Sale Mug',
            'sku' => 'SALE-MUG-1',
            'price' => 20,
            'sale_price' => 15,
            'sale_price_type' => 'fixed',
        ]);

        $product = Product::query()->findOrFail($id);
        $this->assertSame(15.0, (float) $product->sale_price);
        $this->assertSame('fixed', $product->sale_price_type);

        $writer->updateProduct($id, ['sale_price' => 12.5]);
        $product->refresh();
        $this->assertSame(12.5, (float) $product->sale_price);

        $writer->updateProduct($id, ['sale_price' => null]);
        $product->refresh();
        $this->assertNull($product->sale_price);
    }

    public function test_assign_disabled_admin_is_rejected(): void
    {
        $admin = Admin::factory()->create(['is_enabled' => false]);
        $order = Order::createTrusted([
            'number' => 'TEST-ASSIGN-2',
            'email' => 'buyer@example.com',
            'status' => 'pending',
            'currency_code' => 'USD',
            'currency_rate' => 1,
            'subtotal' => 10,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'fee_total' => 0,
            'grand_total' => 10,
            'payment_status' => 'pending',
            'billing_address' => ['line1' => '1 Main'],
            'shipping_address' => ['line1' => '1 Main'],
        ]);

        try {
            $this->ordersApi(['orders.write'])->assignAdmin((int) $order->id, (int) $admin->id);
            $this->fail('disabled admin should be rejected');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('assigned_admin_id', $e->errors());
        }
    }

    /**
     * @param  list<string>  $capabilities
     */
    protected function cartsApi(array $capabilities): ModuleCarts
    {
        return new ModuleCarts($this->manifest($capabilities));
    }

    /**
     * @param  list<string>  $capabilities
     */
    protected function scheduleApi(array $capabilities): ModuleSchedule
    {
        return new ModuleSchedule($this->manifest($capabilities));
    }

    /**
     * @param  list<string>  $capabilities
     */
    protected function ordersApi(array $capabilities): ModuleOrdersWrite
    {
        return new ModuleOrdersWrite($this->manifest($capabilities));
    }

    /**
     * @param  list<string>  $capabilities
     */
    protected function catalogApi(array $capabilities): ModuleCatalogWrite
    {
        return new ModuleCatalogWrite($this->manifest($capabilities));
    }

    /**
     * @param  list<string>  $capabilities
     */
    protected function manifest(array $capabilities): PluginManifest
    {
        $dir = storage_path('framework/testing/'.uniqid('ops_', true));
        mkdir($dir);
        file_put_contents($dir.'/app.json', json_encode([
            'vendor' => 'acme',
            'name' => 'ops',
            'display_name' => 'Ops',
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
