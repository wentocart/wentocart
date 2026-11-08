<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Feature;
use App\Domain\Catalog\Models\FeatureValue;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Localization\Models\Language;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCatalogTranslationTest extends TestCase
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

    public function test_admin_can_create_brand_with_multiple_translations(): void
    {
        $this->post('/admin/brands', [
            'position' => 0,
            'is_enabled' => true,
            'translations' => [
                'en' => [
                    'language_code' => 'en',
                    'name' => 'Acme',
                    'slug' => 'acme',
                    'short_description' => 'English short',
                    'tagline' => 'English tagline',
                    'description' => '<p>English</p>',
                    'meta_title' => 'Acme EN',
                    'meta_description' => 'EN SEO',
                ],
                'fr' => [
                    'language_code' => 'fr',
                    'name' => 'Acme FR',
                    'slug' => 'acme-fr',
                    'short_description' => 'Court FR',
                    'tagline' => 'Slogan FR',
                    'description' => '<p>Francais</p>',
                    'meta_title' => 'Acme FR',
                    'meta_description' => 'SEO FR',
                ],
            ],
        ])->assertRedirect('/admin/brands');

        $brand = Brand::query()->whereHas('translations', fn ($q) => $q->where('slug', 'acme'))->firstOrFail();
        $this->assertSame('Acme', $brand->translation('en')?->name);
        $this->assertSame('Acme FR', $brand->translation('fr')?->name);
        $this->assertSame('Slogan FR', $brand->translation('fr')?->tagline);
    }

    public function test_admin_can_create_supplier_with_multiple_translations(): void
    {
        $this->post('/admin/suppliers', [
            'is_enabled' => true,
            'address' => '123 Main Street',
            'city' => 'Springfield',
            'state' => 'IL',
            'postcode' => '62701',
            'country' => 'US',
            'translations' => [
                'en' => [
                    'language_code' => 'en',
                    'name' => 'Pacific Mills',
                    'description' => 'English supplier',
                ],
                'fr' => [
                    'language_code' => 'fr',
                    'name' => 'Usines Pacifique',
                    'description' => 'Fournisseur FR',
                ],
            ],
        ])->assertRedirect('/admin/suppliers');

        $supplier = Supplier::query()->whereHas('translations', fn ($q) => $q->where('slug', 'pacific-mills'))->firstOrFail();
        $this->assertSame('Pacific Mills', $supplier->translation('en')?->name);
        $this->assertSame('Usines Pacifique', $supplier->translation('fr')?->name);
        $this->assertSame('Fournisseur FR', $supplier->translation('fr')?->description);
    }

    public function test_admin_can_create_feature_and_value_with_multiple_translations(): void
    {
        $this->post('/admin/features', [
            'position' => 0,
            'is_enabled' => true,
            'translations' => [
                'en' => ['language_code' => 'en', 'name' => 'I18n Material'],
                'fr' => ['language_code' => 'fr', 'name' => 'Matiere I18n'],
            ],
        ])->assertRedirect('/admin/features');

        $feature = Feature::query()->where('code', 'i18n-material')->firstOrFail();
        $this->assertSame('I18n Material', $feature->translation('en')?->name);
        $this->assertSame('Matiere I18n', $feature->translation('fr')?->name);

        $this->post('/admin/features/values', [
            'feature_id' => $feature->id,
            'position' => 0,
            'is_enabled' => true,
            'translations' => [
                'en' => ['language_code' => 'en', 'value' => 'Organic Cotton'],
                'fr' => ['language_code' => 'fr', 'value' => 'Coton bio'],
            ],
        ])->assertRedirect('/admin/features/values?feature_id='.$feature->id);

        $value = FeatureValue::query()->where('feature_id', $feature->id)->firstOrFail();
        $this->assertSame('Organic Cotton', $value->translation('en')?->value);
        $this->assertSame('Coton bio', $value->translation('fr')?->value);
    }

    public function test_blank_non_default_translations_are_not_saved(): void
    {
        $this->post('/admin/features', [
            'position' => 0,
            'is_enabled' => true,
            'translations' => [
                'en' => ['language_code' => 'en', 'name' => 'I18n Color'],
                'fr' => ['language_code' => 'fr', 'name' => ''],
            ],
        ])->assertRedirect('/admin/features');

        $feature = Feature::query()->where('code', 'i18n-color')->firstOrFail();
        $this->assertSame('I18n Color', $feature->translation('en')?->name);
        $this->assertNull($feature->translations()->where('language_code', 'fr')->first());
    }
}
