<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Support\CatalogMediaUrl;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminProductImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_upload_and_delete_product_image(): void
    {
        Storage::fake('public');
        $this->seed();
        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin');

        $product = Product::query()->create([
            'type' => 'simple',
            'sku' => 'IMG-DEL-1',
            'price' => 10,
            'is_enabled' => true,
        ]);

        $this->post('/admin/products/'.$product->id.'/images', [
            'image' => UploadedFile::fake()->image('cover.jpg', 200, 200),
            'cover' => '1',
        ])->assertRedirect();

        $image = ProductImage::query()->where('product_id', $product->id)->first();
        $this->assertNotNull($image);
        $this->assertSame($image->path, $product->fresh()->cover_path);
        Storage::disk('public')->assertExists($image->path);

        $this->delete('/admin/products/'.$product->id.'/images/'.$image->id)
            ->assertRedirect();

        $this->assertDatabaseMissing('product_images', ['id' => $image->id]);
        $this->assertNull($product->fresh()->cover_path);
        Storage::disk('public')->assertMissing($image->path);
    }

    public function test_admin_cannot_delete_image_from_another_product(): void
    {
        Storage::fake('public');
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $owner = Product::query()->create([
            'type' => 'simple',
            'sku' => 'IMG-OWN',
            'price' => 10,
            'is_enabled' => true,
        ]);
        $other = Product::query()->create([
            'type' => 'simple',
            'sku' => 'IMG-OTHER',
            'price' => 10,
            'is_enabled' => true,
        ]);

        $image = ProductImage::query()->create([
            'product_id' => $owner->id,
            'path' => 'catalog/products/'.$owner->id.'/a.jpg',
            'position' => 1,
        ]);
        Storage::disk('public')->put($image->path, 'fake');

        $this->delete('/admin/products/'.$other->id.'/images/'.$image->id)
            ->assertNotFound();

        $this->assertDatabaseHas('product_images', ['id' => $image->id]);
    }

    public function test_product_image_url_uses_shop_folder_and_defaults_when_missing(): void
    {
        config(['app.url' => 'http://localhost/shop-folder']);

        $image = new ProductImage(['path' => 'catalog/products/9/cover.jpg']);
        $this->assertSame('/shop-folder/storage/catalog/products/9/cover.jpg', $image->url);
        $this->assertSame('/shop-folder/storage/catalog/products/9/cover.jpg', $image->toArray()['url']);

        $missing = new ProductImage(['path' => '']);
        $this->assertSame(CatalogMediaUrl::url(null), $missing->url);
        $this->assertStringContainsString('p-md-default.png', $missing->url);
    }

    public function test_uploaded_image_exposes_catalog_media_url(): void
    {
        Storage::fake('public');
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $product = Product::query()->create([
            'type' => 'simple',
            'sku' => 'IMG-URL-1',
            'price' => 10,
            'is_enabled' => true,
        ]);

        $this->post('/admin/products/'.$product->id.'/images', [
            'image' => UploadedFile::fake()->image('side.jpg', 200, 200),
            'cover' => '0',
        ])->assertRedirect();

        $image = ProductImage::query()->where('product_id', $product->id)->first();
        $this->assertNotNull($image);
        $this->assertSame(CatalogMediaUrl::url($image->path), $image->url);
        $this->assertStringStartsWith('/storage/', $image->url);
        $this->assertStringNotContainsString('/storage/storage/', $image->url);
    }

    public function test_product_edit_inertia_returns_shop_prefixed_urls_after_upload_and_reload(): void
    {
        Storage::fake('public');
        config(['app.url' => 'http://localhost/shop-folder']);
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        foreach (['simple', 'virtual', 'combination', 'group'] as $type) {
            $product = Product::query()->create([
                'type' => $type,
                'sku' => 'IMG-EDIT-'.strtoupper($type),
                'price' => 10,
                'is_enabled' => true,
            ]);

            $this->post('/admin/products/'.$product->id.'/images', [
                'image' => UploadedFile::fake()->image($type.'-cover.jpg', 160, 160),
                'cover' => '1',
            ])->assertRedirect();

            $cover = ProductImage::query()->where('product_id', $product->id)->first();
            $this->assertNotNull($cover);
            $coverUrl = CatalogMediaUrl::url($cover->path);
            $this->assertSame('/shop-folder/storage/'.$cover->path, $coverUrl);

            $this->get('/admin/products/'.$product->id.'/edit')
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->component('Catalog/Products/Form', false)
                    ->where('product.type', $type)
                    ->where('product.cover_path', $cover->path)
                    ->has('product.images', 1)
                    ->where('product.images.0.path', $cover->path)
                    ->where('product.images.0.url', $coverUrl));

            $this->post('/admin/products/'.$product->id.'/images', [
                'image' => UploadedFile::fake()->image($type.'-extra.png', 80, 80),
                'cover' => '0',
            ])->assertRedirect();

            $extra = ProductImage::query()
                ->where('product_id', $product->id)
                ->whereKeyNot($cover->id)
                ->first();
            $this->assertNotNull($extra);
            $this->assertSame($cover->path, $product->fresh()->cover_path);

            $this->get('/admin/products/'.$product->id.'/edit')
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->component('Catalog/Products/Form', false)
                    ->has('product.images', 2)
                    ->where('product.cover_path', $cover->path)
                    ->where('product.images', function ($images) use ($cover, $extra) {
                        $byId = collect($images)->keyBy('id');

                        return ($byId[$cover->id]['url'] ?? null) === CatalogMediaUrl::url($cover->path)
                            && ($byId[$extra->id]['url'] ?? null) === CatalogMediaUrl::url($extra->path)
                            && ! str_starts_with((string) ($byId[$cover->id]['url'] ?? ''), '/storage/')
                            && ! str_starts_with((string) ($byId[$extra->id]['url'] ?? ''), '/storage/');
                    }));
        }
    }

    public function test_product_edit_form_builds_image_urls_through_catalog_media(): void
    {
        $form = (string) file_get_contents(resource_path('js/admin/Pages/Catalog/Products/Form.jsx'));
        $this->assertStringContainsString("import { catalogImageUrl } from '../../../lib/catalogMedia'", $form);
        $this->assertStringContainsString('catalogImageUrl(img.url || img.path', $form);
        $this->assertStringContainsString('catalogImageUrl(newest.url || newest.path', $form);
        $this->assertStringNotContainsString('`/storage/${', $form);
        $this->assertStringNotContainsString("'/storage/'", $form);

        $picker = (string) file_get_contents(resource_path('js/admin/ui/ProductMediaPicker.jsx'));
        $this->assertStringContainsString('<Thumb', $picker);
        $this->assertStringNotContainsString('src={image.url}', $picker);
    }
}
