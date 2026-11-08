<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Checkout\Services\CartService;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Plugin\PluginManifest;
use App\Domain\Plugin\Support\ModuleBuses;
use App\Domain\Plugin\Support\ModuleCheckout;
use App\Domain\Plugin\Support\ModuleOrdersWrite;
use App\Support\Hooks\HookManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ModuleCheckoutOrdersWriteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        HookManager::flush();
        ModuleBuses::flush();
    }

    public function test_checkout_adjust_adds_fee_and_due_now(): void
    {
        $this->checkoutApi(['checkout.adjust'])->adjust(function (array $ctx) {
            return [
                ['type' => 'fee', 'code' => 'emi_fee', 'label' => 'EMI fee', 'amount' => 5],
                ['type' => 'due_now', 'code' => 'deposit', 'label' => 'Deposit', 'amount' => 20],
            ];
        });

        $customer = Customer::factory()->create();
        $product = Product::query()->where('is_enabled', true)->firstOrFail();
        $carts = app(CartService::class);
        $cart = $carts->current($customer);
        $carts->add($cart, $product, 1);
        $cart->refresh()->load('items.product');

        $summary = $carts->summary($cart, 0, 'US', null, $customer);

        $this->assertSame(5.0, (float) $summary['fee_total']);
        $this->assertSame(20.0, (float) $summary['due_now']);
        $this->assertGreaterThan(0, (float) $summary['balance_due']);
        $this->assertTrue(collect($summary['adjustments'])->contains(fn ($a) => $a['code'] === 'emi_fee'));
    }

    public function test_orders_write_records_instalment_payment(): void
    {
        $order = Order::createTrusted([
            'number' => 'TEST-PARTIAL-1',
            'email' => 'buyer@example.com',
            'status' => 'pending',
            'currency_code' => 'USD',
            'currency_rate' => 1,
            'subtotal' => 100,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'fee_total' => 0,
            'grand_total' => 100,
            'payment_status' => 'partial',
            'amount_paid' => 25,
            'amount_due' => 75,
            'billing_address' => ['line1' => '1 Main'],
            'shipping_address' => ['line1' => '1 Main'],
        ]);

        $writer = $this->ordersApi(['orders.write']);
        $writer->recordPayment((int) $order->id, 25, [
            'plan_code' => 'emi-3',
            'schedule' => [
                ['due_at' => '2026-09-01', 'amount' => 25, 'status' => 'paid'],
                ['due_at' => '2026-10-01', 'amount' => 25, 'status' => 'pending'],
            ],
        ]);

        $order->refresh();
        $this->assertSame(50.0, (float) $order->amount_paid);
        $this->assertSame(50.0, (float) $order->amount_due);
        $this->assertSame('partial', $order->payment_status);
        $this->assertSame('emi-3', $order->payment_details['plan_code'] ?? null);
    }

    public function test_orders_write_requires_capability(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('orders.write');
        $this->ordersApi(['settings'])->recordPayment(1, 1);
    }

    public function test_checkout_adjust_requires_capability(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('checkout.adjust');
        $this->checkoutApi(['settings'])->adjust(fn () => []);
    }

    public function test_record_payment_cannot_exceed_total(): void
    {
        $order = Order::createTrusted([
            'number' => 'TEST-PARTIAL-2',
            'email' => 'buyer@example.com',
            'status' => 'pending',
            'currency_code' => 'USD',
            'currency_rate' => 1,
            'subtotal' => 40,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'fee_total' => 0,
            'grand_total' => 40,
            'payment_status' => 'partial',
            'amount_paid' => 30,
            'amount_due' => 10,
            'billing_address' => ['line1' => '1 Main'],
            'shipping_address' => ['line1' => '1 Main'],
        ]);

        try {
            $this->ordersApi(['orders.write'])->recordPayment((int) $order->id, 20);
            $this->fail('overpay should be rejected');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }
    }

    /**
     * @param  list<string>  $capabilities
     */
    protected function checkoutApi(array $capabilities): ModuleCheckout
    {
        return new ModuleCheckout($this->manifest($capabilities));
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
    protected function manifest(array $capabilities): PluginManifest
    {
        $dir = storage_path('framework/testing/'.uniqid('chkord_', true));
        mkdir($dir);
        file_put_contents($dir.'/app.json', json_encode([
            'vendor' => 'acme',
            'name' => 'partial',
            'display_name' => 'Partial',
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
