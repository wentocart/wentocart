<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Category;
use App\Domain\Localization\Models\Language;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCategoryTranslationTest extends TestCase
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
        return array_replace_recursive([
            'position' => 0,
            'is_enabled' => true,
            'translations' => [
                'en' => [
                    'language_code' => 'en',
                    'name' => 'Headphones',
                    'slug' => 'headphones',
                    'description' => '<p>English description</p>',
                    'meta_title' => 'Shop Headphones',
                    'meta_description' => 'English SEO',
                ],
                'fr' => [
                    'language_code' => 'fr',
                    'name' => 'Casques',
                    'slug' => 'casques',
                    'description' => '<p>Description francaise</p>',
                    'meta_title' => 'Acheter Casques',
                    'meta_description' => 'SEO francais',
                ],
            ],
        ], $overrides);
    }

    public function test_admin_can_create_category_with_multiple_translations(): void
    {
        $this->post('/admin/categories', $this->payload())->assertRedirect('/admin/categories');

        $category = Category::query()->whereHas('translations', fn ($q) => $q->where('slug', 'headphones'))->firstOrFail();
        $this->assertSame('Headphones', $category->translation('en')?->name);
        $this->assertSame('Casques', $category->translation('fr')?->name);
    }

    public function test_default_language_name_is_required_but_other_languages_can_be_empty(): void
    {
        $response = $this->from('/admin/categories/create')->post('/admin/categories', $this->payload([
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
        $response->assertRedirect('/admin/categories/create');
        $response->assertSessionHasErrors('translations.en.name');

        $this->post('/admin/categories', $this->payload([
            'translations' => [
                'en' => [
                    'language_code' => 'en',
                    'name' => 'Speakers',
                    'slug' => 'speakers',
                    'description' => '',
                    'meta_title' => '',
                    'meta_description' => '',
                    'canonical_url' => '',
                    'meta_keywords' => '',
                ],
                'fr' => [
                    'language_code' => 'fr',
                    'name' => '',
                    'slug' => '',
                    'description' => '',
                    'meta_title' => '',
                    'meta_description' => '',
                    'canonical_url' => '',
                    'meta_keywords' => '',
                ],
            ],
        ]))->assertRedirect('/admin/categories');

        $category = Category::query()->whereHas('translations', fn ($q) => $q->where('slug', 'speakers'))->firstOrFail();
        $this->assertSame('Speakers', $category->translation('en')?->name);
        $this->assertNull($category->translations()->where('language_code', 'fr')->first());
    }

    public function test_updating_one_translation_does_not_overwrite_the_other(): void
    {
        $this->post('/admin/categories', $this->payload())->assertRedirect('/admin/categories');
        $category = Category::query()->whereHas('translations', fn ($q) => $q->where('slug', 'headphones'))->firstOrFail();

        $this->put('/admin/categories/'.$category->id, $this->payload([
            'translations' => [
                'en' => [
                    'language_code' => 'en',
                    'name' => 'Headphones',
                    'slug' => 'headphones',
                    'description' => '<p>English description</p>',
                    'meta_title' => 'Shop Headphones',
                    'meta_description' => 'English SEO',
                ],
                'fr' => [
                    'language_code' => 'fr',
                    'name' => 'Casques Audio',
                    'slug' => 'casques-audio',
                    'description' => '<p>Description mise a jour</p>',
                    'meta_title' => 'Acheter Casques Audio',
                    'meta_description' => 'SEO mis a jour',
                ],
            ],
        ]))->assertRedirect('/admin/categories');

        $category->refresh()->load('translations');
        $this->assertSame('Headphones', $category->translation('en')?->name);
        $this->assertSame('Casques Audio', $category->translation('fr')?->name);
        $this->assertSame('headphones', $category->translation('en')?->slug);
        $this->assertSame('casques-audio', $category->translation('fr')?->slug);
    }
}
