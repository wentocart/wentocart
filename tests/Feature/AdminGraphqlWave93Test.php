<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Attachment;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\AttachmentService;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderDownload;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Services\OrderDownloadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminGraphqlWave93Test extends TestCase
{
    use RefreshDatabase;

    public function test_customer_downloadable_products_graphql(): void
    {
        $this->seed();
        Storage::fake('local');
        $customer = Customer::factory()->create();

        $product = Product::query()->create([
            'type' => 'virtual',
            'sku' => 'W93-DL-1',
            'price' => 19,
            'is_enabled' => true,
            'manage_stock' => false,
        ]);
        $product->translations()->create([
            'language_code' => 'en',
            'name' => 'Wave93 Ebook',
            'slug' => 'wave93-ebook',
        ]);
        $path = 'downloads/w93-ebook.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 wave93');
        $file = Attachment::query()->create([
            'name' => 'Wave93 Ebook PDF',
            'original_filename' => 'wave93.pdf',
            'stored_filename' => 'wave93.pdf',
            'storage_path' => $path,
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'file_size' => 14,
        ]);
        app(AttachmentService::class)->attachToProduct($product, $file, AttachmentService::ROLE_PAID);

        $order = Order::createTrusted([
            'number' => 'W93-DL-1',
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'completed',
            'currency_code' => 'USD',
            'subtotal' => 19,
            'grand_total' => 19,
            'payment_status' => 'paid',
            'amount_paid' => 19,
            'amount_due' => 0,
            'billing_address' => ['first_name' => 'W', 'line1' => '1 Main', 'city' => 'Austin', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'W', 'line1' => '1 Main', 'city' => 'Austin', 'country_code' => 'US'],
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'name' => 'Wave93 Ebook',
            'qty' => 1,
            'unit_price' => 19,
            'total' => 19,
        ]);
        app(OrderDownloadService::class)->issueFor($order->refresh());
        $download = OrderDownload::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($download);

        $list = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => '{
                customerDownloadableProducts(status: "available") {
                    id productName fileName status downloadUrl remainingDownloads order_number
                }
            }',
        ]);
        $list->assertOk();
        $this->assertArrayNotHasKey('errors', $list->json() ?? [], json_encode($list->json()));
        $rows = $list->json('data.customerDownloadableProducts') ?? [];
        $this->assertNotEmpty($rows);
        $this->assertSame((string) $download->id, (string) $rows[0]['id']);
        $this->assertSame('available', (string) $rows[0]['status']);
        $this->assertStringContainsString('/api/v1/account/downloads/', (string) $rows[0]['downloadUrl']);
        $this->assertSame('W93-DL-1', (string) $rows[0]['order_number']);

        $one = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'query ($id: ID!) {
                customerDownloadableProduct(id: $id) { id status fileName }
            }',
            'variables' => ['id' => (string) $download->id],
        ]);
        $one->assertOk();
        $this->assertArrayNotHasKey('errors', $one->json() ?? [], json_encode($one->json()));
        $this->assertSame((string) $download->id, (string) $one->json('data.customerDownloadableProduct.id'));

        $compatRest = $this->actingAs($customer, 'customer')
            ->get('/api/v1/store/customer-downloadable-products/'.$download->id.'/download');
        $compatRest->assertOk();
    }
}
