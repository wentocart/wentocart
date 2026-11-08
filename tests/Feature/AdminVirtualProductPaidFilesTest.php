<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Attachment;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\AttachmentService;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminVirtualProductPaidFilesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
        Storage::fake('local');
    }

    public function test_virtual_product_edit_lists_paid_and_document_attachments(): void
    {
        $product = $this->makeVirtualProduct('LIST');
        $paid = $this->makeAttachment('ebook.pdf');
        $doc = $this->makeAttachment('readme.pdf');
        $svc = app(AttachmentService::class);
        $svc->attachToProduct($product, $paid, AttachmentService::ROLE_PAID);
        $svc->attachToProduct($product, $doc, AttachmentService::ROLE_DOCUMENT);

        $this->get('/admin/products/'.$product->id.'/edit')
            ->assertOk()
            ->assertInertia(function (Assert $page) use ($paid, $doc) {
                $page->component('Catalog/Products/Form', false)
                    ->where('product.type', 'virtual')
                    ->has('product.attachments', 2);

                $rows = collect($page->toArray()['props']['product']['attachments'] ?? []);
                $paidRow = $rows->first(fn ($row) => (int) data_get($row, 'id') === (int) $paid->id);
                $docRow = $rows->first(fn ($row) => (int) data_get($row, 'id') === (int) $doc->id);

                $this->assertNotNull($paidRow, 'Paid attachment missing from edit props');
                $this->assertSame(AttachmentService::ROLE_PAID, data_get($paidRow, 'role'));
                $this->assertTrue((bool) data_get($paidRow, 'is_paid'));
                $this->assertNull(data_get($paidRow, 'download_url'));
                $this->assertNotEmpty(data_get($paidRow, 'admin_download_url'));
                $this->assertNotNull($docRow, 'Document attachment missing from edit props');
                $this->assertSame(AttachmentService::ROLE_DOCUMENT, data_get($docRow, 'role'));
                $this->assertFalse((bool) data_get($docRow, 'is_paid'));
            });
    }

    public function test_admin_can_sync_multiple_paid_files_on_virtual_product(): void
    {
        $product = $this->makeVirtualProduct('MULTI');
        $first = $this->makeAttachment('part-1.pdf');
        $second = $this->makeAttachment('part-2.zip');

        $this->put('/admin/products/'.$product->id, $this->withProductCategories([
            'type' => 'virtual',
            'name' => 'Virtual Bundle',
            'slug' => 'virtual-bundle-multi',
            'sku' => $product->sku,
            'price' => 29.99,
            'is_enabled' => true,
            'attachment_ids' => [$first->id, $second->id],
            'attachment_roles' => [
                $first->id => AttachmentService::ROLE_PAID,
                $second->id => AttachmentService::ROLE_PAID,
            ],
        ]))->assertRedirect();

        $product->refresh();
        $this->assertSame(2, $product->attachments()->count());
        $this->assertSame(AttachmentService::ROLE_PAID, $product->attachmentLink($first->id)?->role);
        $this->assertSame(AttachmentService::ROLE_PAID, $product->attachmentLink($second->id)?->role);
    }

    public function test_admin_can_detach_paid_file_from_virtual_product(): void
    {
        $product = $this->makeVirtualProduct('DETACH');
        $keep = $this->makeAttachment('keep.pdf');
        $drop = $this->makeAttachment('drop.pdf');
        $svc = app(AttachmentService::class);
        $svc->attachToProduct($product, $keep, AttachmentService::ROLE_PAID);
        $svc->attachToProduct($product, $drop, AttachmentService::ROLE_PAID);

        $this->delete('/admin/products/'.$product->id.'/attachments/'.$drop->id)
            ->assertRedirect();

        $product->refresh();
        $this->assertTrue($product->attachments()->where('attachments.id', $keep->id)->exists());
        $this->assertFalse($product->attachments()->where('attachments.id', $drop->id)->exists());
        $this->assertDatabaseHas('attachments', ['id' => $drop->id]);
    }

    public function test_guest_cannot_sync_virtual_product_attachments(): void
    {
        auth('admin')->logout();
        $product = $this->makeVirtualProduct('GUEST');
        $attachment = $this->makeAttachment('secret.pdf');

        $this->put('/admin/products/'.$product->id.'/attachments', [
            'attachment_ids' => [$attachment->id],
            'attachment_roles' => [$attachment->id => AttachmentService::ROLE_PAID],
        ])->assertRedirect(route('admin.login'));

        $this->assertSame(0, $product->fresh()->attachments()->count());
    }

    public function test_non_virtual_product_cannot_keep_paid_role_via_sync(): void
    {
        $product = $this->makeSimpleProduct('FORCE');
        $attachment = $this->makeAttachment('forced.pdf');

        $this->put('/admin/products/'.$product->id, $this->withProductCategories([
            'type' => 'simple',
            'name' => 'Simple Product',
            'slug' => 'simple-forced-paid',
            'sku' => $product->sku,
            'price' => 12,
            'is_enabled' => true,
            'attachment_ids' => [$attachment->id],
            'attachment_roles' => [$attachment->id => AttachmentService::ROLE_PAID],
        ]))->assertRedirect();

        $this->assertSame(
            AttachmentService::ROLE_DOCUMENT,
            $product->fresh()->attachmentLink($attachment->id)?->role,
        );
    }

    public function test_paid_file_is_not_publicly_downloadable_or_listed(): void
    {
        $product = $this->makeVirtualProduct('PRIV');
        $attachment = $this->makeAttachment('license-key.pdf');
        app(AttachmentService::class)->attachToProduct($product, $attachment, AttachmentService::ROLE_PAID);

        $slug = $product->translation('en')?->slug;
        $this->getJson('/api/v1/catalog/product/'.$slug)
            ->assertOk()
            ->assertJsonCount(0, 'product.attachments');

        $this->get('/download/attachment/'.$attachment->id.'?product='.$product->id)
            ->assertForbidden();
    }

    public function test_upload_rejects_invalid_type_when_creating_library_file(): void
    {
        $this->post('/admin/files', [
            'name' => 'Bad',
            'file' => UploadedFile::fake()->create('shell.php', 10, 'application/x-php'),
        ])->assertSessionHasErrors('file');
    }

    public function test_upload_rejects_oversized_library_file(): void
    {
        $this->post('/admin/files', [
            'name' => 'Huge',
            'file' => UploadedFile::fake()->create('big.pdf', 11000, 'application/pdf'),
        ])->assertSessionHasErrors('file');
    }

    public function test_virtual_create_can_attach_paid_file_on_save(): void
    {
        $file = UploadedFile::fake()->createWithContent('starter.pdf', '%PDF-1.4 paid content');

        $this->post('/admin/products', $this->withProductCategories([
            'type' => 'virtual',
            'name' => 'Course Pack',
            'slug' => 'course-pack-paid',
            'sku' => 'COURSE-PAID-1',
            'price' => 39.99,
            'is_enabled' => true,
            'attachment_files' => [$file],
            'attachment_labels' => ['Course PDF'],
            'attachment_descriptions' => ['Main deliverable'],
            'attachment_roles' => [AttachmentService::ROLE_PAID],
        ]))->assertProductSaveRedirect();

        $product = Product::query()->where('sku', 'COURSE-PAID-1')->first();
        $this->assertNotNull($product);
        $this->assertSame(1, $product->attachments()->count());
        $link = $product->attachments()->first();
        $this->assertSame(AttachmentService::ROLE_PAID, $product->attachmentLink($link->id)?->role);
    }

    protected function makeVirtualProduct(string $suffix): Product
    {
        $product = Product::query()->create([
            'type' => 'virtual',
            'sku' => 'VPF-'.$suffix,
            'price' => 25,
            'manage_stock' => false,
            'is_enabled' => true,
        ]);
        $product->translations()->create([
            'language_code' => 'en',
            'name' => 'Virtual Paid '.$suffix,
            'slug' => 'virtual-paid-'.strtolower($suffix),
        ]);

        return $product->refresh();
    }

    protected function makeSimpleProduct(string $suffix): Product
    {
        $product = Product::query()->create([
            'type' => 'simple',
            'sku' => 'SPF-'.$suffix,
            'price' => 10,
            'stock_qty' => 5,
            'is_enabled' => true,
        ]);
        $product->translations()->create([
            'language_code' => 'en',
            'name' => 'Simple '.$suffix,
            'slug' => 'simple-'.strtolower($suffix),
        ]);

        return $product->refresh();
    }

    protected function makeAttachment(string $filename): Attachment
    {
        return app(AttachmentService::class)->create([
            'name' => pathinfo($filename, PATHINFO_FILENAME),
            'description' => null,
        ], UploadedFile::fake()->createWithContent($filename, '%PDF-1.4 test content'));
    }
}
