<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\AttributeValue;
use App\Domain\Catalog\Models\CatalogAttribute;
use App\Domain\Localization\Models\Language;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttributeManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_manage_attributes_and_values_separately(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->get('/admin/attributes')->assertOk();
        $this->get('/admin/attributes/create')->assertOk();
        $this->get('/admin/attributes/values')->assertOk();
        $this->get('/admin/attributes/values/create')->assertOk();

        $this->from('/admin/attributes/create')->post('/admin/attributes', [
            'name' => '',
            'type' => 'select',
            'is_enabled' => true,
        ])->assertSessionHasErrors(['name']);

        $expectedColorPosition = \App\Domain\Catalog\Support\PositionReorder::next(CatalogAttribute::class);
        $this->post('/admin/attributes', [
            'name' => 'Color',
            'type' => 'select',
            'supports_swatches' => true,
            'is_enabled' => true,
        ])->assertRedirect('/admin/attributes');

        $color = CatalogAttribute::query()->where('code', 'color')->first();
        $this->assertNotNull($color);
        $this->assertSame('Color', $color->translation()->name);
        $this->assertTrue($color->supports_swatches);
        $this->assertSame($expectedColorPosition, (int) $color->position);
        $this->assertSame(0, $color->values()->count());

        $expectedSizePosition = \App\Domain\Catalog\Support\PositionReorder::next(CatalogAttribute::class);
        $this->post('/admin/attributes', [
            'name' => 'Size',
            'type' => 'select',
            'is_enabled' => true,
        ])->assertRedirect('/admin/attributes');

        $size = CatalogAttribute::query()->where('code', 'size')->first();
        $this->assertNotNull($size);
        $this->assertSame($expectedSizePosition, (int) $size->position);

        $expectedBlackPosition = \App\Domain\Catalog\Support\PositionReorder::next(AttributeValue::class, 'attribute_id', $color->id);
        $this->post('/admin/attributes/values', [
            'attribute_id' => $color->id,
            'value' => 'Black',
            'swatch_type' => 'color',
            'color_code' => '#000000',
            'is_enabled' => true,
        ])->assertRedirect('/admin/attributes/values?attribute_id='.$color->id);

        $black = AttributeValue::query()->where('attribute_id', $color->id)->first();
        $this->assertSame('Black', $black->translation()->value);
        $this->assertSame('#000000', $black->color_code);
        $this->assertSame($expectedBlackPosition, (int) $black->position);
        $this->assertNull($black->code);

        $this->post('/admin/attributes/values', [
            'attribute_id' => $color->id,
            'value' => 'White',
            'is_enabled' => true,
        ])->assertRedirect('/admin/attributes/values?attribute_id='.$color->id);

        $white = AttributeValue::query()->where('attribute_id', $color->id)->whereHas('translations', fn ($q) => $q->where('value', 'White'))->firstOrFail();
        $this->post('/admin/attributes/values/reorder', [
            'ids' => [$white->id, $black->id],
        ])->assertRedirect();
        $this->assertSame(0, (int) $white->fresh()->position);
        $this->assertSame(1, (int) $black->fresh()->position);

        $this->post('/admin/attributes/values', [
            'attribute_id' => $color->id,
            'value' => 'Black',
            'is_enabled' => true,
        ])->assertSessionHasErrors(['value']);

        $this->post('/admin/attributes/'.$color->id.'/toggle')->assertRedirect();
        $this->assertFalse($color->fresh()->is_enabled);

        $this->post('/admin/attributes/values/'.$black->id.'/toggle')->assertRedirect();
        $this->assertFalse($black->fresh()->is_enabled);

        $this->delete('/admin/attributes/values/'.$white->id)->assertRedirect('/admin/attributes/values');
        $this->delete('/admin/attributes/values/'.$black->id)->assertRedirect('/admin/attributes/values');
        $this->assertNull(AttributeValue::query()->find($black->id));

        $this->delete('/admin/attributes/'.$color->id)->assertRedirect('/admin/attributes');
        $this->assertNull(CatalogAttribute::query()->find($color->id));
    }

    public function test_admin_can_create_attribute_and_value_with_multiple_translations(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
        Language::query()->updateOrCreate(
            ['code' => 'fr'],
            ['name' => 'French', 'is_enabled' => true, 'is_default' => false, 'position' => 2],
        );

        $this->post('/admin/attributes', [
            'type' => 'select',
            'supports_swatches' => false,
            'is_enabled' => true,
            'translations' => [
                'en' => ['language_code' => 'en', 'name' => 'I18n Color'],
                'fr' => ['language_code' => 'fr', 'name' => 'Couleur I18n'],
            ],
        ])->assertRedirect('/admin/attributes');

        $attribute = CatalogAttribute::query()->where('code', 'i18n-color')->firstOrFail();
        $this->assertSame('I18n Color', $attribute->translation('en')?->name);
        $this->assertSame('Couleur I18n', $attribute->translation('fr')?->name);

        $this->post('/admin/attributes/values', [
            'attribute_id' => $attribute->id,
            'is_enabled' => true,
            'translations' => [
                'en' => ['language_code' => 'en', 'value' => 'Black'],
                'fr' => ['language_code' => 'fr', 'value' => 'Noir'],
            ],
        ])->assertRedirect('/admin/attributes/values?attribute_id='.$attribute->id);

        $value = AttributeValue::query()->where('attribute_id', $attribute->id)->firstOrFail();
        $this->assertSame('Black', $value->translation('en')?->value);
        $this->assertSame('Noir', $value->translation('fr')?->value);
    }

    public function test_blank_non_default_attribute_translations_are_not_saved(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
        Language::query()->updateOrCreate(
            ['code' => 'fr'],
            ['name' => 'French', 'is_enabled' => true, 'is_default' => false, 'position' => 2],
        );

        $this->post('/admin/attributes', [
            'type' => 'select',
            'is_enabled' => true,
            'translations' => [
                'en' => ['language_code' => 'en', 'name' => 'I18n Size'],
                'fr' => ['language_code' => 'fr', 'name' => ''],
            ],
        ])->assertRedirect('/admin/attributes');

        $attribute = CatalogAttribute::query()->where('code', 'i18n-size')->firstOrFail();
        $this->assertSame('I18n Size', $attribute->translation('en')?->name);
        $this->assertNull($attribute->translations()->where('language_code', 'fr')->first());
    }

    public function test_attribute_list_supports_column_filters(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->post('/admin/attributes', [
            'name' => 'Filter Color',
            'type' => 'select',
            'is_enabled' => true,
        ])->assertRedirect('/admin/attributes');

        $this->get('/admin/attributes?name=Filter&type=select&status=active&id_min=1&values_min=0')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Catalog/Attributes/Index', false)
                ->has('attributes.data')
                ->where('filters.name', 'Filter')
                ->where('filters.type', 'select'));
    }

    public function test_attribute_import_sample_and_csv_import(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $response = $this->get('/admin/attributes/import/sample?delimiter=;&multivalue_separator=,');
        $response->assertOk();
        $this->assertStringContainsString('attributes-sample.csv', (string) $response->headers->get('content-disposition'));

        $csv = $response->streamedContent();
        $lines = preg_split('/\r\n|\r|\n/', trim($csv));
        $header = str_getcsv($lines[0], ';');
        $this->assertSame(\App\Domain\Catalog\Support\AttributeCsvSchema::columns(), $header);
        $this->assertGreaterThanOrEqual(5, count($lines) - 1);

        $count = app(\App\Domain\Catalog\Services\AttributeService::class)->importCsv($csv, 'en', ';', ',');
        $uniqueAttributeCodes = collect(\App\Domain\Catalog\Support\AttributeCsvSchema::sampleRows())
            ->pluck('code')
            ->unique()
            ->count();
        $this->assertSame($uniqueAttributeCodes, $count);

        $color = CatalogAttribute::query()->where('code', 'color')->first();
        $this->assertNotNull($color);
        $this->assertSame('Color', $color->translation('en')?->name);
        $this->assertTrue($color->supports_swatches);
        $this->assertTrue($color->is_enabled);
        $this->assertSame(3, $color->values()->count());
        $black = $color->values()
            ->whereHas('translations', fn ($q) => $q->where('language_code', 'en')->where('value', 'Black'))
            ->first();
        $this->assertNotNull($black);
        $this->assertSame('Black', $black->translation('en')?->value);
        $this->assertSame('color', $black->swatch_type);
        $this->assertSame('#111111', $black->color_code);

        $retired = CatalogAttribute::query()->where('code', 'retired-finish')->first();
        $this->assertNotNull($retired);
        $this->assertFalse($retired->is_enabled);

        $notes = CatalogAttribute::query()->where('code', 'notes')->first();
        $this->assertNotNull($notes);
        $this->assertSame(0, $notes->values()->count());

        $this->get('/admin/attributes/export')->assertOk();

        $exported = app(\App\Domain\Catalog\Services\AttributeService::class)->exportCsv('en', null, ';', ',');
        $reimport = app(\App\Domain\Catalog\Services\AttributeService::class)->importCsv($exported, 'en', ';', ',');
        $this->assertSame($uniqueAttributeCodes, $reimport);
        $this->assertSame(3, CatalogAttribute::query()->where('code', 'color')->first()?->values()->count());
    }

    public function test_attribute_bulk_status_and_duplicate(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->post('/admin/attributes', [
            'name' => 'Bulk Attr',
            'type' => 'select',
            'supports_swatches' => false,
            'is_enabled' => true,
        ])->assertRedirect('/admin/attributes');

        $attribute = CatalogAttribute::query()->where('code', 'bulk-attr')->firstOrFail();
        $this->post('/admin/attributes/values', [
            'attribute_id' => $attribute->id,
            'value' => 'Red',
            'is_enabled' => true,
        ])->assertRedirect();

        $this->post('/admin/attributes/bulk-status', [
            'ids' => [$attribute->id],
            'enabled' => 0,
        ])->assertRedirect();
        $this->assertFalse($attribute->fresh()->is_enabled);

        $this->post('/admin/attributes/bulk-status', [
            'ids' => [$attribute->id],
            'enabled' => 1,
        ])->assertRedirect();
        $this->assertTrue($attribute->fresh()->is_enabled);

        $expected = \App\Domain\Catalog\Support\PositionReorder::next(CatalogAttribute::class);
        $this->post('/admin/attributes/bulk-duplicate', [
            'ids' => [$attribute->id],
        ])->assertRedirect();

        $copy = CatalogAttribute::query()
            ->whereHas('translations', fn ($q) => $q->where('name', 'Bulk Attr (copy)'))
            ->first();
        $this->assertNotNull($copy);
        $this->assertFalse($copy->is_enabled);
        $this->assertSame($expected, (int) $copy->position);
        $this->assertSame(1, $copy->values()->count());
        $this->assertSame('Red', $copy->values()->first()?->translation('en')?->value);
    }

    public function test_attribute_value_bulk_status_and_delete(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->post('/admin/attributes', [
            'name' => 'Size',
            'type' => 'select',
            'is_enabled' => true,
        ])->assertRedirect('/admin/attributes');

        $attribute = CatalogAttribute::query()->where('code', 'size')->firstOrFail();

        $this->post('/admin/attributes/values', [
            'attribute_id' => $attribute->id,
            'value' => 'Small',
            'is_enabled' => true,
        ])->assertRedirect();
        $this->post('/admin/attributes/values', [
            'attribute_id' => $attribute->id,
            'value' => 'Large',
            'is_enabled' => true,
        ])->assertRedirect();

        $small = AttributeValue::query()
            ->where('attribute_id', $attribute->id)
            ->whereHas('translations', fn ($q) => $q->where('value', 'Small'))
            ->firstOrFail();
        $large = AttributeValue::query()
            ->where('attribute_id', $attribute->id)
            ->whereHas('translations', fn ($q) => $q->where('value', 'Large'))
            ->firstOrFail();

        $this->post('/admin/attributes/values/bulk-status', [
            'ids' => [$small->id, $large->id],
            'enabled' => 0,
        ])->assertRedirect();
        $this->assertFalse($small->fresh()->is_enabled);
        $this->assertFalse($large->fresh()->is_enabled);

        $this->post('/admin/attributes/values/bulk-status', [
            'ids' => [$small->id],
            'enabled' => 1,
        ])->assertRedirect();
        $this->assertTrue($small->fresh()->is_enabled);

        $this->post('/admin/attributes/values/bulk-delete', [
            'ids' => [$small->id, $large->id],
        ])->assertRedirect();
        $this->assertNull(AttributeValue::query()->find($small->id));
        $this->assertNull(AttributeValue::query()->find($large->id));
    }
}
