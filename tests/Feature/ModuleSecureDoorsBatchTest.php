<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Checkout\Models\Cart;
use App\Domain\Checkout\Services\CartService;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Plugin\PluginManifest;
use App\Domain\Plugin\Support\ModuleBuses;
use App\Domain\Plugin\Support\ModuleCarts;
use App\Domain\Plugin\Support\ModuleCatalogWrite;
use App\Domain\Plugin\Support\ModuleCheckout;
use App\Domain\Plugin\Support\ModuleCustomersWrite;
use App\Domain\Plugin\Support\ModuleOrdersWrite;
use App\Domain\Plugin\Support\ModuleWishlist;
use App\Support\Hooks\HookManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModuleSecureDoorsBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        HookManager::flush();
        ModuleBuses::flush();
    }

    public function test_orders_write_set_status_and_payment_snapshot(): void
    {
        $customer = Customer::factory()->create();
        $order = Order::createTrusted([
            'number' => 'TEST-STATUS-1',
            'customer_id' => $customer->id,
            'email' => $customer->email,
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
            'amount_paid' => 10,
            'amount_due' => 30,
            'billing_address' => ['line1' => '1 Main'],
            'shipping_address' => ['line1' => '1 Main'],
        ]);

        $writer = $this->ordersApi(['orders.write']);
        $snap = $writer->paymentSnapshot((int) $order->id);
        $this->assertSame(30.0, $snap['amount_due']);
        $this->assertCount(1, $writer->listOpenForCustomer((int) $customer->id));

        $writer->setStatus((int) $order->id, 'processing', 'Picked by extension');
        $order->refresh();
        $this->assertSame('processing', $order->status);
    }

    public function test_cart_meta_and_checkout_credit(): void
    {
        $customer = Customer::factory()->create();
        $product = Product::query()->where('is_enabled', true)->firstOrFail();
        $carts = app(CartService::class);
        $cart = $carts->current($customer);
        $carts->add($cart, $product, 1);

        $this->cartsApi(['carts.write'])->setMeta((int) $cart->id, ['delivery_date' => '2026-09-01']);
        $cart->refresh();
        $this->assertSame('2026-09-01', $cart->meta['delivery_date'] ?? null);

        $this->checkoutApi(['checkout.adjust'])->adjust(function (array $ctx) {
            $this->assertSame('2026-09-01', $ctx['cart_meta']['delivery_date'] ?? null);

            return [
                ['type' => 'credit', 'code' => 'loyalty', 'label' => 'Points', 'amount' => 5],
            ];
        });

        $summary = $carts->summary($cart->fresh()->load('items.product'), 0, 'US', null, $customer);
        $this->assertSame(5.0, (float) $summary['credit_total']);
        $this->assertTrue(collect($summary['adjustments'])->contains(fn ($a) => $a['type'] === 'credit'));
    }

    public function test_wishlist_and_address_and_catalog_categories(): void
    {
        $customer = Customer::factory()->create();
        $product = Product::query()->where('is_enabled', true)->firstOrFail();
        $category = Category::query()->firstOrFail();

        $wish = $this->wishlistApi(['wishlist.write', 'wishlist.read']);
        $wish->add((int) $customer->id, (int) $product->id);
        $this->assertContains((int) $product->id, $wish->idsFor((int) $customer->id));

        $addrId = $this->customersApi(['customers.write'])->upsertAddress((int) $customer->id, [
            'line1' => '10 Market St',
            'city' => 'Austin',
            'country_code' => 'US',
            'is_default_shipping' => true,
        ]);
        $this->assertGreaterThan(0, $addrId);

        $id = $this->catalogApi(['catalog.write'])->createProduct([
            'name' => 'Imported Chair',
            'sku' => 'IMP-CHAIR-1',
            'price' => 49,
            'category_ids' => [(int) $category->id],
            'weight' => 2.5,
        ]);
        $created = Product::query()->findOrFail($id);
        $this->assertTrue($created->categories()->where('categories.id', $category->id)->exists());
        $this->assertSame(2.5, (float) $created->weight);
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
    protected function cartsApi(array $capabilities): ModuleCarts
    {
        return new ModuleCarts($this->manifest($capabilities));
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
    protected function wishlistApi(array $capabilities): ModuleWishlist
    {
        return new ModuleWishlist($this->manifest($capabilities));
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
    protected function catalogApi(array $capabilities): ModuleCatalogWrite
    {
        return new ModuleCatalogWrite($this->manifest($capabilities));
    }

    /**
     * @param  list<string>  $capabilities
     */
    protected function manifest(array $capabilities): PluginManifest
    {
        $dir = storage_path('framework/testing/'.uniqid('secdoor_', true));
        mkdir($dir);
        file_put_contents($dir.'/app.json', json_encode([
            'vendor' => 'acme',
            'name' => 'secure-doors',
            'display_name' => 'Secure doors',
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
