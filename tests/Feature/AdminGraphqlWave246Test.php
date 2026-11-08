<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderDownload;
use App\Domain\Settings\ShopSetting;
use App\Domain\Store\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave246Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_shop_customer_downloadable_products_list(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);

        $storeId = app(StoreContext::class)->requireId();
        $customer = Customer::factory()->create(['store_id' => $storeId]);
        $other = Customer::factory()->create(['store_id' => $storeId]);
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();

        $order = Order::createTrusted([
            'number' => 'W246-DL',
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'completed',
            'currency_code' => 'USD',
            'payment_status' => 'paid',
            'subtotal' => 29.99,
            'grand_total' => 29.99,
            'billing_address' => ['country_code' => 'US'],
            'shipping_address' => ['country_code' => 'US'],
        ]);
        $item = $order->items()->create([
            'product_id' => $product->id,
            'sku' => $product->sku,
            'name' => 'Demo download',
            'qty' => 1,
            'unit_price' => 29.99,
            'total' => 29.99,
        ]);

        $dl = OrderDownload::query()->create([
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'product_id' => $product->id,
            'attachment_id' => null,
            'token' => bin2hex(random_bytes(16)),
            'filename' => 'ebook.pdf',
            'max_uses' => 5,
            'uses' => 1,
            'expires_at' => now()->addDays(20),
        ]);

        $list = $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/store/customer-downloadable-products');
        $list->assertOk();
        $rows = $list->json();
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
        $this->assertSame((int) $dl->id, (int) $rows[0]['id']);
        $this->assertSame('ebook.pdf', (string) $rows[0]['fileName']);
        $this->assertSame(5, (int) $rows[0]['downloadBought']);
        $this->assertSame(1, (int) $rows[0]['downloadUsed']);
        $this->assertStringContainsString('/customer-downloadable-products/'.$dl->id.'/download', (string) $rows[0]['downloadUrl']);
        $this->assertStringContainsString('/customer-orders/', (string) $rows[0]['order']);

        $show = $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/store/customer-downloadable-products/'.$dl->id);
        $show->assertOk();
        $this->assertSame((int) $dl->id, (int) $show->json('id'));

        $this->actingAs($other, 'customer')
            ->getJson('/api/v1/store/customer-downloadable-products/'.$dl->id)
            ->assertNotFound();

        $empty = $this->actingAs($other, 'customer')
            ->getJson('/api/v1/store/customer-downloadable-products');
        $empty->assertOk();
        $this->assertSame([], $empty->json());
    }
}
