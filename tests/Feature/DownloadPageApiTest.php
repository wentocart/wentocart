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

class DownloadPageApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<array{name?: string, description?: string, filename: string, contents?: string, mime?: string, role?: string}>  $files
     */
    private function virtualOrder(
        Customer $customer,
        array $files = [['filename' => 'license.txt', 'name' => 'License key', 'contents' => 'LICENSE-KEY', 'mime' => 'text/plain']],
        array $orderOverrides = [],
        string $sku = 'DL-TEST-1',
    ): Order {
        Storage::fake('local');

        $product = Product::query()->create([
            'type' => 'virtual',
            'sku' => $sku,
            'price' => 25,
            'is_enabled' => true,
            'manage_stock' => false,
        ]);
        $product->translations()->create([
            'language_code' => 'en',
            'name' => 'Virtual '.$sku,
            'slug' => strtolower($sku),
        ]);

        $roles = app(AttachmentService::class);
        foreach ($files as $index => $spec) {
            $filename = $spec['filename'];
            $path = 'downloads/'.$sku.'-'.$index.'-'.$filename;
            Storage::disk('local')->put($path, $spec['contents'] ?? 'payload');
            $file = Attachment::query()->create([
                'name' => $spec['name'] ?? pathinfo($filename, PATHINFO_FILENAME),
                'description' => $spec['description'] ?? null,
                'original_filename' => $filename,
                'stored_filename' => $filename,
                'storage_path' => $path,
                'disk' => 'local',
                'mime_type' => $spec['mime'] ?? 'application/octet-stream',
                'file_size' => strlen($spec['contents'] ?? 'payload'),
            ]);
            $roles->attachToProduct($product, $file, $spec['role'] ?? AttachmentService::ROLE_PAID);
        }

        $order = Order::createTrusted(array_merge([
            'number' => 'ORD-'.$sku,
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'status' => 'completed',
            'currency_code' => 'USD',
            'subtotal' => 25,
            'grand_total' => 25,
            'payment_status' => 'paid',
            'amount_paid' => 25,
            'amount_due' => 0,
            'billing_address' => ['first_name' => 'Dee', 'line1' => '1 Main', 'city' => 'Austin', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Dee', 'line1' => '1 Main', 'city' => 'Austin', 'country_code' => 'US'],
        ], $orderOverrides));

        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'name' => 'Virtual '.$sku,
            'qty' => 1,
            'unit_price' => 25,
            'total' => 25,
        ]);

        app(OrderDownloadService::class)->issueFor($order->refresh());

        return $order->fresh(['items.downloads.attachment', 'items.product']);
    }

    public function test_downloads_list_has_order_and_product_context(): void
    {
        $this->seed();
        $customer = Customer::factory()->create(['email' => 'dl-list@example.test']);
        $this->virtualOrder($customer, [[
            'filename' => 'license.txt',
            'name' => 'License key',
            'description' => 'Your personal license file',
            'contents' => 'LICENSE-KEY',
            'mime' => 'text/plain',
        ]]);

        $this->actingAs($customer, 'customer');
        $res = $this->getJson('/api/v1/account/downloads')->assertOk();
        $rows = $res->json('downloads');

        $this->assertNotEmpty($rows);
        $first = $rows[0];

        foreach (['token', 'filename', 'original_filename', 'name', 'description', 'download_url', 'remaining', 'available', 'order', 'product', 'file_size_label', 'days_left'] as $key) {
            $this->assertArrayHasKey($key, $first, "Missing key: {$key}");
        }
        $this->assertSame('ORD-DL-TEST-1', $first['order']['number']);
        $this->assertSame('/account/orders/ORD-DL-TEST-1', $first['order']['url']);
        $this->assertSame('Virtual DL-TEST-1', $first['product']['name']);
        $this->assertSame('virtual', $first['product']['type']);
        $this->assertNotEmpty($first['product']['url'] ?? null);
        $this->assertSame(url('/api/v1/account/downloads/'.$first['token']), $first['download_url']);
        $this->assertSame('license.txt', $first['original_filename']);
        $this->assertSame('License key', $first['name']);
        $this->assertSame('Your personal license file', $first['description']);
        $this->assertTrue($first['available']);
    }

    public function test_order_detail_exposes_download_section_for_virtual_items(): void
    {
        $this->seed();
        $customer = Customer::factory()->create(['email' => 'dl-detail@example.test']);
        $order = $this->virtualOrder($customer, [
            ['filename' => 'ebook.pdf', 'name' => 'Ebook', 'contents' => '%PDF', 'mime' => 'application/pdf'],
            ['filename' => 'cover.jpg', 'name' => 'Cover art', 'contents' => 'fake-jpeg', 'mime' => 'image/jpeg'],
        ]);

        $this->actingAs($customer, 'customer');
        $res = $this->getJson('/api/v1/account/orders/'.$order->number)->assertOk();
        $downloads = $res->json('order.downloads');
        $this->assertCount(2, $downloads);

        $pdf = collect($downloads)->firstWhere('original_filename', 'ebook.pdf');
        $image = collect($downloads)->firstWhere('original_filename', 'cover.jpg');
        $this->assertNotNull($pdf);
        $this->assertNotNull($image);
        $this->assertSame('Ebook', $pdf['name']);
        $this->assertSame('PDF', $pdf['file_type_label']);
        $this->assertFalse($pdf['is_image']);
        $this->assertNull($pdf['preview_url']);
        $this->assertTrue($pdf['available']);
        $this->assertSame(url('/api/v1/account/downloads/'.$pdf['token']), $pdf['download_url']);

        $this->assertTrue($image['is_image']);
        $this->assertSame(url('/api/v1/account/downloads/'.$image['token'].'/preview'), $image['preview_url']);
        $this->assertNotEmpty($image['file_size_label']);

        $items = collect($res->json('order.items'));
        $virtualItem = $items->firstWhere('is_virtual', true);
        $this->assertNotNull($virtualItem);
        $this->assertCount(2, $virtualItem['downloads']);
    }

    public function test_order_detail_supports_multiple_virtual_products(): void
    {
        $this->seed();
        $customer = Customer::factory()->create(['email' => 'dl-multi@example.test']);
        $first = $this->virtualOrder($customer, [['filename' => 'a.pdf', 'name' => 'A', 'contents' => 'A', 'mime' => 'application/pdf']], [], 'VIRT-A');
        $secondProduct = Product::query()->create([
            'type' => 'virtual',
            'sku' => 'VIRT-B',
            'price' => 10,
            'is_enabled' => true,
            'manage_stock' => false,
        ]);
        Storage::disk('local')->put('downloads/b.zip', 'ZIP');
        $file = Attachment::query()->create([
            'name' => 'Pack B',
            'original_filename' => 'b.zip',
            'stored_filename' => 'b.zip',
            'storage_path' => 'downloads/b.zip',
            'disk' => 'local',
            'mime_type' => 'application/zip',
            'file_size' => 3,
        ]);
        app(AttachmentService::class)->attachToProduct($secondProduct, $file, AttachmentService::ROLE_PAID);
        OrderItem::query()->create([
            'order_id' => $first->id,
            'product_id' => $secondProduct->id,
            'sku' => $secondProduct->sku,
            'name' => 'Second virtual',
            'qty' => 1,
            'unit_price' => 10,
            'total' => 10,
        ]);
        app(OrderDownloadService::class)->issueFor($first->fresh(['items.product.attachments']));

        $this->actingAs($customer, 'customer');
        $downloads = $this->getJson('/api/v1/account/orders/'.$first->number)
            ->assertOk()
            ->json('order.downloads');

        $this->assertCount(2, $downloads);
        $names = collect($downloads)->pluck('original_filename')->sort()->values()->all();
        $this->assertSame(['a.pdf', 'b.zip'], $names);
    }

    public function test_pending_payment_hides_order_downloads_and_blocks_access(): void
    {
        $this->seed();
        $customer = Customer::factory()->create(['email' => 'dl-pending@example.test']);
        $order = $this->virtualOrder($customer, orderOverrides: [
            'status' => 'pending',
            'payment_status' => 'pending',
            'amount_paid' => 0,
            'amount_due' => 25,
        ]);
        $token = OrderDownload::query()->where('order_id', $order->id)->value('token');
        $this->assertNotEmpty($token);

        $this->actingAs($customer, 'customer');
        $this->getJson('/api/v1/account/orders/'.$order->number)
            ->assertOk()
            ->assertJsonPath('order.downloads', []);

        $this->getJson('/api/v1/account/downloads')
            ->assertOk()
            ->assertJsonCount(0, 'downloads');

        $this->get('/api/v1/account/downloads/'.$token)->assertForbidden();
        $this->get('/api/v1/account/downloads/'.$token.'/preview')->assertForbidden();
    }

    public function test_cancelled_order_blocks_download_access(): void
    {
        $this->seed();
        $customer = Customer::factory()->create(['email' => 'dl-cancel@example.test']);
        $order = $this->virtualOrder($customer, orderOverrides: [
            'status' => 'cancelled',
            'payment_status' => 'paid',
        ]);
        $token = OrderDownload::query()->where('order_id', $order->id)->value('token');

        $this->actingAs($customer, 'customer');
        $this->getJson('/api/v1/account/orders/'.$order->number)
            ->assertOk()
            ->assertJsonPath('order.downloads', []);
        $this->get('/api/v1/account/downloads/'.$token)->assertForbidden();
    }

    public function test_other_customer_cannot_download_or_preview(): void
    {
        $this->seed();
        $owner = Customer::factory()->create(['email' => 'dl-owner@example.test']);
        $other = Customer::factory()->create(['email' => 'dl-other@example.test']);
        $order = $this->virtualOrder($owner, [
            ['filename' => 'secret.jpg', 'name' => 'Secret', 'contents' => 'img', 'mime' => 'image/jpeg'],
        ]);
        $token = OrderDownload::query()->where('order_id', $order->id)->value('token');

        $this->actingAs($other, 'customer');
        $this->get('/api/v1/account/downloads/'.$token)->assertForbidden();
        $this->get('/api/v1/account/downloads/'.$token.'/preview')->assertForbidden();
        $this->getJson('/api/v1/account/orders/'.$order->number)->assertNotFound();
    }

    public function test_owner_can_download_and_preview_image(): void
    {
        $this->seed();
        $customer = Customer::factory()->create(['email' => 'dl-access@example.test']);
        $order = $this->virtualOrder($customer, [
            ['filename' => 'art.png', 'name' => 'Art', 'contents' => 'PNGDATA', 'mime' => 'image/png'],
        ]);
        $token = OrderDownload::query()->where('order_id', $order->id)->value('token');

        $this->actingAs($customer, 'customer');
        $this->get('/api/v1/account/downloads/'.$token)
            ->assertOk()
            ->assertHeader('content-disposition');
        $this->get('/api/v1/account/downloads/'.$token.'/preview')
            ->assertOk()
            ->assertHeader('content-type', 'image/png');
    }

    public function test_successful_download_decrements_remaining_and_exposes_entitlement_headers(): void
    {
        $this->seed();
        $customer = Customer::factory()->create(['email' => 'dl-count@example.test']);
        $order = $this->virtualOrder($customer);
        $download = OrderDownload::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(5, $download->max_uses);
        $this->assertSame(0, $download->uses);

        $this->actingAs($customer, 'customer');
        $this->get('/api/v1/account/downloads/'.$download->token)
            ->assertOk()
            ->assertHeader(OrderDownloadService::HEADER_REMAINING, '4')
            ->assertHeader(OrderDownloadService::HEADER_USES, '1')
            ->assertHeader(OrderDownloadService::HEADER_MAX_USES, '5')
            ->assertHeader(OrderDownloadService::HEADER_AVAILABLE, '1');

        $download->refresh();
        $this->assertSame(1, $download->uses);
        $this->assertSame(4, $download->remaining());

        $this->getJson('/api/v1/account/downloads')
            ->assertOk()
            ->assertJsonPath('downloads.0.remaining', 4)
            ->assertJsonPath('downloads.0.uses', 1)
            ->assertJsonPath('downloads.0.available', true);
    }

    public function test_failed_download_does_not_decrement_uses(): void
    {
        $this->seed();
        $customer = Customer::factory()->create(['email' => 'dl-fail@example.test']);
        $order = $this->virtualOrder($customer);
        $download = OrderDownload::query()->where('order_id', $order->id)->firstOrFail();
        $attachment = Attachment::query()->findOrFail($download->attachment_id);
        Storage::disk($attachment->disk ?: 'local')->delete((string) $attachment->storage_path);

        $this->actingAs($customer, 'customer');
        $this->get('/api/v1/account/downloads/'.$download->token)->assertNotFound();

        $download->refresh();
        $this->assertSame(0, $download->uses);
        $this->assertSame(5, $download->remaining());
    }

    public function test_unauthorized_download_does_not_decrement_uses(): void
    {
        $this->seed();
        $owner = Customer::factory()->create(['email' => 'dl-auth-owner@example.test']);
        $other = Customer::factory()->create(['email' => 'dl-auth-other@example.test']);
        $order = $this->virtualOrder($owner);
        $download = OrderDownload::query()->where('order_id', $order->id)->firstOrFail();

        $this->actingAs($other, 'customer');
        $this->get('/api/v1/account/downloads/'.$download->token)->assertForbidden();

        $download->refresh();
        $this->assertSame(0, $download->uses);
        $this->assertSame(5, $download->remaining());
    }

    public function test_download_reaches_zero_and_blocks_further_downloads(): void
    {
        $this->seed();
        $customer = Customer::factory()->create(['email' => 'dl-zero@example.test']);
        $order = $this->virtualOrder($customer);
        $download = OrderDownload::query()->where('order_id', $order->id)->firstOrFail();
        $download->forceFill(['max_uses' => 1, 'uses' => 0])->save();

        $this->actingAs($customer, 'customer');
        $this->get('/api/v1/account/downloads/'.$download->token)
            ->assertOk()
            ->assertHeader(OrderDownloadService::HEADER_REMAINING, '0')
            ->assertHeader(OrderDownloadService::HEADER_AVAILABLE, '0');

        $download->refresh();
        $this->assertSame(1, $download->uses);
        $this->assertSame(0, $download->remaining());
        $this->assertFalse($download->isAvailable());

        $this->get('/api/v1/account/downloads/'.$download->token)->assertStatus(410);
        $this->getJson('/api/v1/account/downloads')
            ->assertOk()
            ->assertJsonPath('downloads.0.remaining', 0)
            ->assertJsonPath('downloads.0.available', false);

        $download->refresh();
        $this->assertSame(1, $download->uses);
    }

    public function test_concurrent_download_requests_do_not_double_count_last_use(): void
    {
        $this->seed();
        $customer = Customer::factory()->create(['email' => 'dl-race@example.test']);
        $order = $this->virtualOrder($customer);
        $download = OrderDownload::query()->where('order_id', $order->id)->firstOrFail();
        $download->forceFill(['max_uses' => 1, 'uses' => 0])->save();

        $this->actingAs($customer, 'customer');
        $first = $this->get('/api/v1/account/downloads/'.$download->token);
        $second = $this->get('/api/v1/account/downloads/'.$download->token);

        $first->assertOk()->assertHeader(OrderDownloadService::HEADER_REMAINING, '0');
        $second->assertStatus(410);

        $download->refresh();
        $this->assertSame(1, $download->uses);
        $this->assertSame(0, $download->remaining());
    }

    public function test_non_image_preview_returns_404(): void
    {
        $this->seed();
        $customer = Customer::factory()->create(['email' => 'dl-noprev@example.test']);
        $order = $this->virtualOrder($customer);
        $token = OrderDownload::query()->where('order_id', $order->id)->value('token');

        $this->actingAs($customer, 'customer');
        $this->get('/api/v1/account/downloads/'.$token.'/preview')->assertNotFound();
    }

    public function test_document_role_files_are_not_issued_as_order_downloads(): void
    {
        $this->seed();
        $customer = Customer::factory()->create(['email' => 'dl-doc@example.test']);
        $this->virtualOrder($customer, [
            ['filename' => 'manual.pdf', 'name' => 'Manual', 'contents' => 'PDF', 'mime' => 'application/pdf', 'role' => AttachmentService::ROLE_DOCUMENT],
        ]);

        $this->actingAs($customer, 'customer');
        $this->getJson('/api/v1/account/downloads')->assertOk()->assertJsonCount(0, 'downloads');
    }

    public function test_download_rebinds_when_attachment_row_is_replaced_with_same_filename(): void
    {
        $this->seed();
        $customer = Customer::factory()->create(['email' => 'dl-rebind@example.test']);
        $order = $this->virtualOrder($customer, [
            ['filename' => 'guide.pdf', 'name' => 'Guide', 'contents' => 'PDF-V1', 'mime' => 'application/pdf'],
        ]);
        $download = OrderDownload::query()->where('order_id', $order->id)->firstOrFail();
        $oldAttachmentId = (int) $download->attachment_id;
        $product = Product::query()->findOrFail($download->product_id);

        // Merchant deletes/re-uploads the paid file; token still points at the old id.
        Attachment::query()->whereKey($oldAttachmentId)->delete();
        Storage::disk('local')->put('catalog/files/guide-v2.pdf', 'PDF-V2');
        $replacement = Attachment::query()->create([
            'name' => 'Guide',
            'original_filename' => 'guide.pdf',
            'stored_filename' => 'guide-v2.pdf',
            'storage_path' => 'catalog/files/guide-v2.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'file_size' => 6,
        ]);
        app(AttachmentService::class)->attachToProduct($product, $replacement, AttachmentService::ROLE_PAID);

        $this->actingAs($customer, 'customer');
        $this->get('/api/v1/account/downloads/'.$download->token)
            ->assertOk()
            ->assertHeader('content-disposition');

        $this->assertSame($replacement->id, (int) $download->fresh()->attachment_id);
        $this->getJson('/api/v1/account/downloads')
            ->assertOk()
            ->assertJsonPath('downloads.0.available', true)
            ->assertJsonPath('downloads.0.name', 'Guide');
    }

    public function test_order_detail_still_lists_downloads_when_order_item_id_is_stale(): void
    {
        $this->seed();
        $customer = Customer::factory()->create(['email' => 'dl-stale@example.test']);
        $order = $this->virtualOrder($customer);
        $download = OrderDownload::query()->where('order_id', $order->id)->firstOrFail();
        // Simulate a rebuilt order line after tokens were issued.
        $download->forceFill(['order_item_id' => 999999])->save();

        $this->actingAs($customer, 'customer');
        $this->getJson('/api/v1/account/orders/'.$order->number)
            ->assertOk()
            ->assertJsonCount(1, 'order.downloads')
            ->assertJsonPath('order.downloads.0.token', $download->token)
            ->assertJsonCount(1, 'order.items.0.downloads');
    }
}
