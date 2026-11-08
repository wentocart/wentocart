<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Localization\Models\Language;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminProductTranslationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        Language::query()->updateOrCreate(
            ['code' => 'fr'],
            ['name' => 'French', 'is_enabled' => true, 'is_default' => false, 'position' => 2],
        );
    }

    protected function payload(array $overrides = []): array
    {
        return $this->withProductCategories(array_replace_recursive([
            'type' => 'simple',
            'sku' => 'PHONE-17',
            'price' => 999,
            'is_enabled' => true,
            'translations' => [
                'en' => [
                    'language_code' => 'en',
                    'name' => 'Phone 17',
                    'slug' => 'phone-17',
                    'short_description' => 'English short copy',
                    'description' => '<p>English long copy</p>',
                    'meta_title' => 'Buy Phone 17',
                    'meta_description' => 'English SEO description',
                    'robots' => 'index,follow',
                ],
                'fr' => [
                    'language_code' => 'fr',
                    'name' => 'Telephone 17',
                    'slug' => 'telephone-17',
                    'short_description' => 'Copie courte francaise',
                    'description' => '<p>Copie longue francaise</p>',
                    'meta_title' => 'Acheter Telephone 17',
                    'meta_description' => 'Description SEO francaise',
                    'robots' => 'index,follow',
                ],
            ],
        ], $overrides));
    }

    public function test_admin_can_create_product_with_multiple_translations(): void
    {
        $this->post('/admin/products', $this->payload())->assertProductSaveRedirect();

        $product = Product::query()->where('sku', 'PHONE-17')->firstOrFail();
        $this->assertSame('Phone 17', $product->translation('en')?->name);
        $this->assertSame('Telephone 17', $product->translation('fr')?->name);
    }

    public function test_default_language_name_is_required_but_other_languages_can_be_empty(): void
    {
        $response = $this->from('/admin/products/create')->post('/admin/products', $this->payload([
            'translations' => [
                'en' => [
                    'language_code' => 'en',
                    'name' => '',
                    'slug' => '',
                ],
                'fr' => [
                    'language_code' => 'fr',
                    'name' => '',
                    'slug' => '',
                ],
            ],
        ]));

        $response->assertRedirect('/admin/products/create');
        $response->assertSessionHasErrors('translations.en.name');

        $this->post('/admin/products', $this->payload([
            'sku' => 'PHONE-18',
            'translations' => [
                'en' => [
                    'language_code' => 'en',
                    'name' => 'Phone 18',
                    'slug' => 'phone-18',
                ],
                'fr' => [
                    'language_code' => 'fr',
                    'name' => '',
                    'slug' => '',
                    'short_description' => '',
                    'description' => null,
                    'meta_title' => '',
                    'meta_description' => '',
                    'focus_keyword' => '',
                    'canonical_url' => '',
                ],
            ],
        ]))->assertProductSaveRedirect();

        $product = Product::query()->where('sku', 'PHONE-18')->firstOrFail();
        $this->assertSame('Phone 18', $product->translation('en')?->name);
        $this->assertNull($product->translations()->where('language_code', 'fr')->first());
    }

    public function test_updating_one_translation_does_not_overwrite_or_delete_the_other(): void
    {
        $this->post('/admin/products', $this->payload())->assertProductSaveRedirect();
        $product = Product::query()->where('sku', 'PHONE-17')->firstOrFail();

        $this->put('/admin/products/' . $product->id, $this->payload([
            'translations' => [
                'en' => [
                    'language_code' => 'en',
                    'name' => 'Phone 17',
                    'slug' => 'phone-17',
                    'short_description' => 'English short copy',
                    'description' => '<p>English long copy</p>',
                    'meta_title' => 'Buy Phone 17',
                    'meta_description' => 'English SEO description',
                    'robots' => 'index,follow',
                ],
                'fr' => [
                    'language_code' => 'fr',
                    'name' => 'Telephone 17 Modifie',
                    'slug' => 'telephone-17-fr',
                    'short_description' => 'Copie courte mise a jour',
                    'description' => '<p>Copie longue mise a jour</p>',
                    'meta_title' => 'Acheter Telephone 17 Modifie',
                    'meta_description' => 'Description SEO mise a jour',
                    'robots' => 'index,follow',
                ],
            ],
        ]))->assertRedirect('/admin/products/' . $product->id . '/edit');

        $product->refresh()->load('translations');

        $this->assertSame('Phone 17', $product->translation('en')?->name);
        $this->assertSame('Telephone 17 Modifie', $product->translation('fr')?->name);
        $this->assertSame('phone-17', $product->translation('en')?->slug);
        $this->assertSame('telephone-17-fr', $product->translation('fr')?->slug);
    }

    public function test_product_requires_at_least_one_category(): void
    {
        $response = $this->from('/admin/products/create')->post('/admin/products', [
            'type' => 'simple',
            'sku' => 'NO-CAT',
            'price' => 10,
            'is_enabled' => true,
            'name' => 'No Category Product',
            'slug' => 'no-category-product',
            'category_ids' => [],
            'default_category_id' => '',
        ]);

        $response->assertRedirect('/admin/products/create');
        $response->assertSessionHasErrors(['category_ids']);
    }
}
