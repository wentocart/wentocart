<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Attachment;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\AttachmentService;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttachmentManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
        Storage::fake('local');
    }

    public function test_admin_can_create_attachment(): void
    {
        $response = $this->post('/admin/files', [
            'name' => 'Product Manual',
            'description' => 'Setup guide',
            'file' => UploadedFile::fake()->createWithContent('manual.pdf', '%PDF-1.4 test content'),
        ]);

        $response->assertRedirect(route('admin.files.index'));
        $this->assertDatabaseHas('attachments', [
            'name' => 'Product Manual',
            'original_filename' => 'manual.pdf',
        ]);
    }

    public function test_admin_can_create_docx_attachment(): void
    {
        $response = $this->post('/admin/files', [
            'name' => 'Word Doc',
            'description' => 'Setup guide',
            'file' => UploadedFile::fake()->create('customizationGuide.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
            'translations' => [
                'en' => [
                    'language_code' => 'en',
                    'name' => 'Word Doc',
                    'description' => 'Setup guide',
                ],
            ],
        ]);

        $response->assertRedirect(route('admin.files.index'));
        $this->assertDatabaseHas('attachments', [
            'name' => 'Word Doc',
            'original_filename' => 'customizationGuide.docx',
        ]);
    }

    public function test_docx_with_generic_mime_is_accepted(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'docx');
        file_put_contents($path, 'plain office payload');

        $file = new UploadedFile(
            $path,
            'customizationGuide.docx',
            'application/octet-stream',
            UPLOAD_ERR_OK,
            true,
        );

        $this->post('/admin/files', [
            'name' => 'Word Doc',
            'file' => $file,
        ])->assertRedirect(route('admin.files.index'));

        @unlink($path);
    }

    public function test_invalid_file_type_is_rejected(): void
    {
        $this->post('/admin/files', [
            'name' => 'Bad',
            'file' => UploadedFile::fake()->create('shell.php', 10, 'application/x-php'),
        ])->assertSessionHasErrors('file');
    }

    public function test_oversized_file_is_rejected(): void
    {
        $this->post('/admin/files', [
            'name' => 'Huge',
            'file' => UploadedFile::fake()->create('big.pdf', 11000, 'application/pdf'),
        ])->assertSessionHasErrors('file');
    }

    public function test_post_too_large_shows_friendly_file_error(): void
    {
        $response = $this->from(route('admin.files.create'))->call('POST', '/admin/files', [
            'name' => 'Huge',
        ], [], [], [
            'CONTENT_LENGTH' => (string) (999 * 1024 * 1024),
        ]);

        $response->assertRedirect(route('admin.files.create'));
        $response->assertSessionHasErrors('file');
        $this->assertStringContainsString('10 MB', session('errors')->get('file')[0]);
    }

    public function test_admin_can_edit_attachment_metadata(): void
    {
        $attachment = $this->makeAttachment('manual.pdf');

        $this->put("/admin/files/{$attachment->id}", [
            'name' => 'Updated Manual',
            'description' => 'Revised',
        ])->assertRedirect(route('admin.files.index'));

        $this->assertDatabaseHas('attachments', [
            'id' => $attachment->id,
            'name' => 'Updated Manual',
            'description' => 'Revised',
        ]);
    }

    public function test_admin_can_replace_physical_file(): void
    {
        $attachment = $this->makeAttachment('manual.pdf');
        $oldPath = $attachment->storage_path;

        $this->put("/admin/files/{$attachment->id}", [
            'name' => 'Manual',
            'file' => UploadedFile::fake()->create('manual-v2.pdf', 120, 'application/pdf'),
        ])->assertRedirect(route('admin.files.index'));

        $attachment->refresh();
        $this->assertSame('manual-v2.pdf', $attachment->original_filename);
        $this->assertNotSame($oldPath, $attachment->storage_path);
        Storage::disk('local')->assertMissing($oldPath);
    }

    public function test_admin_can_download_attachment(): void
    {
        $attachment = $this->makeAttachment('manual.pdf');

        $this->get("/admin/files/{$attachment->id}/download")
            ->assertOk()
            ->assertDownload('manual.pdf');
    }

    public function test_admin_can_delete_attachment(): void
    {
        $attachment = $this->makeAttachment('manual.pdf');
        $path = $attachment->storage_path;

        $this->delete("/admin/files/{$attachment->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_product_can_have_multiple_attachments(): void
    {
        $product = $this->makeProduct();
        $a = $this->makeAttachment('one.pdf');
        $b = $this->makeAttachment('two.pdf');

        app(AttachmentService::class)->syncProductAttachments($product, [$a->id, $b->id]);

        $this->assertCount(2, $product->fresh()->attachments);
    }

    public function test_attachment_can_belong_to_multiple_products(): void
    {
        $manual = $this->makeAttachment('shared.pdf');
        $productA = $this->makeProduct('A');
        $productB = $this->makeProduct('B');
        $service = app(AttachmentService::class);

        $service->attachToProduct($productA, $manual);
        $service->attachToProduct($productB, $manual);

        $this->assertTrue($manual->products()->where('products.id', $productA->id)->exists());
        $this->assertTrue($manual->products()->where('products.id', $productB->id)->exists());
    }

    public function test_duplicate_product_file_relationship_is_prevented(): void
    {
        $product = $this->makeProduct();
        $attachment = $this->makeAttachment('manual.pdf');
        $service = app(AttachmentService::class);

        $service->syncProductAttachments($product, [$attachment->id]);
        $service->syncProductAttachments($product, [$attachment->id]);

        $this->assertSame(1, $product->attachments()->count());
    }

    public function test_customer_can_see_product_attachments_in_api(): void
    {
        $product = $this->makeProduct();
        $attachment = $this->makeAttachment('manual.pdf', [
            'name' => 'Product Manual',
            'description' => 'Setup and care guide',
        ]);
        app(AttachmentService::class)->attachToProduct($product, $attachment);

        $slug = $product->translation('en')?->slug;
        $this->getJson('/api/v1/catalog/product/'.$slug)
            ->assertOk()
            ->assertJsonPath('product.attachments.0.name', 'Product Manual')
            ->assertJsonPath('product.attachments.0.description', 'Setup and care guide')
            ->assertJsonPath('product.attachments.0.original_filename', 'manual.pdf')
            ->assertJsonPath('product.attachments.0.file_type_label', 'PDF')
            ->assertJsonPath('product.attachments.0.is_image', false)
            ->assertJsonPath('product.attachments.0.preview_url', null)
            ->assertJsonPath('product.attachments.0.download_url', route('attachments.download', [
                'attachment' => $attachment->id,
                'product' => $product->id,
            ]));

        $payload = $this->getJson('/api/v1/catalog/product/'.$slug)->json('product.attachments.0');
        $this->assertNotEmpty($payload['file_size_label'] ?? null);
        $this->assertGreaterThan(0, (int) ($payload['file_size'] ?? 0));
    }

    public function test_product_api_exposes_image_preview_for_document_images(): void
    {
        $product = $this->makeProduct('IMG');
        $attachment = $this->makeImageAttachment('datasheet.png', [
            'name' => 'Size chart',
            'description' => 'Full size guide',
        ]);
        app(AttachmentService::class)->attachToProduct($product, $attachment, AttachmentService::ROLE_DOCUMENT);

        $slug = $product->translation('en')?->slug;
        $this->getJson('/api/v1/catalog/product/'.$slug)
            ->assertOk()
            ->assertJsonPath('product.attachments.0.name', 'Size chart')
            ->assertJsonPath('product.attachments.0.description', 'Full size guide')
            ->assertJsonPath('product.attachments.0.original_filename', 'datasheet.png')
            ->assertJsonPath('product.attachments.0.file_type_label', 'PNG')
            ->assertJsonPath('product.attachments.0.is_image', true)
            ->assertJsonPath('product.attachments.0.preview_url', route('attachments.preview', [
                'attachment' => $attachment->id,
                'product' => $product->id,
            ]))
            ->assertJsonPath('product.attachments.0.download_url', route('attachments.download', [
                'attachment' => $attachment->id,
                'product' => $product->id,
            ]));
    }

    public function test_customer_can_preview_authorized_image_attachment(): void
    {
        $product = $this->makeProduct('PREV');
        $attachment = $this->makeImageAttachment('cover.jpg');
        app(AttachmentService::class)->attachToProduct($product, $attachment, AttachmentService::ROLE_DOCUMENT);

        $this->get('/download/attachment/'.$attachment->id.'/preview?product='.$product->id)
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');
    }

    public function test_non_image_attachment_preview_returns_404(): void
    {
        $product = $this->makeProduct('NOPREV');
        $attachment = $this->makeAttachment('manual.pdf');
        app(AttachmentService::class)->attachToProduct($product, $attachment, AttachmentService::ROLE_DOCUMENT);

        $this->get('/download/attachment/'.$attachment->id.'/preview?product='.$product->id)
            ->assertNotFound();
    }

    public function test_paid_image_attachment_has_no_storefront_preview(): void
    {
        $product = $this->makeVirtualProduct('PAIDIMG');
        $attachment = $this->makeImageAttachment('art.jpg');
        app(AttachmentService::class)->attachToProduct($product, $attachment, AttachmentService::ROLE_PAID);

        $slug = $product->translation('en')?->slug;
        $this->getJson('/api/v1/catalog/product/'.$slug)
            ->assertOk()
            ->assertJsonCount(0, 'product.attachments');

        $this->get('/download/attachment/'.$attachment->id.'/preview?product='.$product->id)
            ->assertForbidden();
    }

    public function test_customer_can_download_authorized_attachment(): void
    {
        $product = $this->makeProduct();
        $attachment = $this->makeAttachment('manual.pdf');
        app(AttachmentService::class)->attachToProduct($product, $attachment);

        $this->get('/download/attachment/'.$attachment->id.'?product='.$product->id)
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    public function test_download_fails_without_product_link(): void
    {
        $product = $this->makeProduct();
        $attachment = $this->makeAttachment('manual.pdf');

        $this->get('/download/attachment/'.$attachment->id.'?product='.$product->id)
            ->assertNotFound();
    }

    public function test_non_existing_attachment_returns_404(): void
    {
        $product = $this->makeProduct();

        $this->get('/download/attachment/99999?product='.$product->id)
            ->assertNotFound();
    }

    public function test_deleting_product_removes_pivot_rows(): void
    {
        $product = $this->makeProduct();
        $attachment = $this->makeAttachment('manual.pdf');
        app(AttachmentService::class)->attachToProduct($product, $attachment);

        $product->delete();

        $this->assertDatabaseMissing('attachment_product', [
            'product_id' => $product->id,
            'attachment_id' => $attachment->id,
        ]);
        $this->assertDatabaseHas('attachments', ['id' => $attachment->id]);
    }

    public function test_admin_files_index_is_accessible(): void
    {
        $this->get('/admin/files')->assertOk();
    }

    public function test_admin_can_bulk_delete_attachments(): void
    {
        $first = $this->makeAttachment('one.pdf');
        $second = $this->makeAttachment('two.pdf');

        $this->post('/admin/files/bulk-delete', [
            'ids' => [$first->id, $second->id],
        ])->assertRedirect();

        $this->assertDatabaseMissing('attachments', ['id' => $first->id]);
        $this->assertDatabaseMissing('attachments', ['id' => $second->id]);
    }

    protected function makeProduct(string $suffix = '1'): Product
    {
        $product = Product::query()->create([
            'type' => 'simple',
            'sku' => 'ATT-'.$suffix,
            'price' => 10,
            'stock_qty' => 5,
            'is_enabled' => true,
        ]);
        $product->translations()->create([
            'language_code' => 'en',
            'name' => 'Attachment Product '.$suffix,
            'slug' => 'attachment-product-'.$suffix,
        ]);

        // translation() reads the loaded relation, so reload after creating it.
        return $product->refresh();
    }

    protected function makeVirtualProduct(string $suffix = 'V'): Product
    {
        $product = Product::query()->create([
            'type' => 'virtual',
            'sku' => 'ATT-'.$suffix,
            'price' => 25,
            'manage_stock' => false,
            'is_enabled' => true,
        ]);
        $product->translations()->create([
            'language_code' => 'en',
            'name' => 'Virtual Product '.$suffix,
            'slug' => 'virtual-product-'.$suffix,
        ]);

        return $product->refresh();
    }

    public function test_paid_product_file_is_not_exposed_on_the_storefront_product_page(): void
    {
        $product = $this->makeVirtualProduct();
        $attachment = $this->makeAttachment('license.pdf');

        app(AttachmentService::class)->attachToProduct($product, $attachment, AttachmentService::ROLE_PAID);

        $slug = $product->translation('en')?->slug;
        $this->getJson('/api/v1/catalog/product/'.$slug)
            ->assertOk()
            ->assertJsonCount(0, 'product.attachments');
    }

    public function test_paid_product_file_cannot_be_downloaded_from_the_product_page(): void
    {
        $product = $this->makeVirtualProduct();
        $attachment = $this->makeAttachment('license.pdf');

        app(AttachmentService::class)->attachToProduct($product, $attachment, AttachmentService::ROLE_PAID);

        // Even a signed-in customer, and even a guest, must not be able to fetch it here.
        $this->get('/download/attachment/'.$attachment->id.'?product='.$product->id)
            ->assertForbidden();
    }

    public function test_document_product_file_stays_public(): void
    {
        $product = $this->makeProduct();
        $attachment = $this->makeAttachment('manual.pdf');

        app(AttachmentService::class)->attachToProduct($product, $attachment, AttachmentService::ROLE_DOCUMENT);

        $slug = $product->translation('en')?->slug;
        $this->getJson('/api/v1/catalog/product/'.$slug)
            ->assertOk()
            ->assertJsonPath('product.attachments.0.role', AttachmentService::ROLE_DOCUMENT)
            ->assertJsonPath('product.attachments.0.is_paid', false);

        $this->get('/download/attachment/'.$attachment->id.'?product='.$product->id)
            ->assertOk();
    }

    public function test_virtual_product_defaults_new_files_to_paid(): void
    {
        $product = $this->makeVirtualProduct('DEF');
        $attachment = $this->makeAttachment('ebook.pdf');

        app(AttachmentService::class)->attachToProduct($product, $attachment);

        $this->assertSame(AttachmentService::ROLE_PAID, $product->attachmentLink($attachment->id)?->role);
    }

    public function test_simple_product_defaults_new_files_to_document(): void
    {
        $product = $this->makeProduct('DEF');
        $attachment = $this->makeAttachment('datasheet.pdf');

        app(AttachmentService::class)->attachToProduct($product, $attachment);

        $this->assertSame(AttachmentService::ROLE_DOCUMENT, $product->attachmentLink($attachment->id)?->role);
    }

    public function test_non_virtual_product_forces_document_even_when_paid_is_requested(): void
    {
        $product = $this->makeProduct('FORCE');
        $attachment = $this->makeAttachment('forced.pdf');

        app(AttachmentService::class)->attachToProduct(
            $product,
            $attachment,
            AttachmentService::ROLE_PAID,
        );

        $this->assertSame(AttachmentService::ROLE_DOCUMENT, $product->attachmentLink($attachment->id)?->role);

        app(AttachmentService::class)->syncProductAttachments(
            $product,
            [$attachment->id],
            [$attachment->id => AttachmentService::ROLE_PAID],
        );

        $this->assertSame(
            AttachmentService::ROLE_DOCUMENT,
            $product->fresh()->attachmentLink($attachment->id)?->role,
        );
        $this->assertSame(
            AttachmentService::ROLE_DOCUMENT,
            app(AttachmentService::class)->roleOf(AttachmentService::ROLE_PAID, $product),
        );
    }

    public function test_rental_product_attachments_are_always_document(): void
    {
        $product = Product::query()->create([
            'type' => 'rental',
            'sku' => 'ATT-RENTAL',
            'price' => 40,
            'manage_stock' => true,
            'is_enabled' => true,
        ]);
        $product->translations()->create([
            'language_code' => 'en',
            'name' => 'Rental Product',
            'slug' => 'rental-product-att',
        ]);
        $attachment = $this->makeAttachment('rental-guide.pdf');

        app(AttachmentService::class)->syncProductAttachments(
            $product->refresh(),
            [$attachment->id],
            [$attachment->id => AttachmentService::ROLE_PAID],
        );

        $this->assertSame(
            AttachmentService::ROLE_DOCUMENT,
            $product->fresh()->attachmentLink($attachment->id)?->role,
        );
    }

    public function test_virtual_product_keeps_selected_document_role(): void
    {
        $product = $this->makeVirtualProduct('DOC');
        $attachment = $this->makeAttachment('readme.pdf');

        app(AttachmentService::class)->syncProductAttachments(
            $product,
            [$attachment->id],
            [$attachment->id => AttachmentService::ROLE_DOCUMENT],
        );

        $this->assertSame(
            AttachmentService::ROLE_DOCUMENT,
            $product->fresh()->attachmentLink($attachment->id)?->role,
        );
    }

    /** @param  array<string, mixed>  $overrides */
    protected function makeAttachment(string $filename, array $overrides = []): Attachment
    {
        return app(AttachmentService::class)->create([
            'name' => $overrides['name'] ?? 'Manual',
            'description' => $overrides['description'] ?? null,
        ], UploadedFile::fake()->createWithContent($filename, '%PDF-1.4 test content'));
    }

    /** @param  array<string, mixed>  $overrides */
    protected function makeImageAttachment(string $filename = 'photo.jpg', array $overrides = []): Attachment
    {
        return app(AttachmentService::class)->create([
            'name' => $overrides['name'] ?? 'Photo',
            'description' => $overrides['description'] ?? null,
        ], UploadedFile::fake()->image($filename, 40, 40));
    }
}
