<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Feature;
use App\Domain\Catalog\Models\FeatureValue;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeatureManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_manage_features_and_values_separately(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->get('/admin/features')->assertOk();
        $this->get('/admin/features?name=RAM&status=active')->assertOk();
        $this->get('/admin/features/create')->assertOk();
        $this->get('/admin/features/values')->assertOk();
        $this->get('/admin/features/values?value=8&status=active')->assertOk();
        $this->get('/admin/features/values/create')->assertOk();

        $this->from('/admin/features/create')->post('/admin/features', [
            'name' => '',
            'is_enabled' => true,
        ])->assertSessionHasErrors();

        $this->from('/admin/features/create')->post('/admin/features', [
            'name' => 'RAM',
            'is_enabled' => true,
            'position' => 'abc',
        ])->assertSessionHasErrors(['position']);

        $expectedRamPosition = \App\Domain\Catalog\Support\PositionReorder::next(Feature::class);
        $this->post('/admin/features', [
            'name' => 'RAM',
            'is_enabled' => true,
        ])->assertRedirect('/admin/features');

        $ram = Feature::query()->where('code', 'ram')->first();
        $this->assertNotNull($ram);
        $this->assertSame('RAM', $ram->translation()->name);
        $this->assertSame($expectedRamPosition, (int) $ram->position);
        $this->assertSame(0, $ram->values()->count());

        $expectedStoragePosition = \App\Domain\Catalog\Support\PositionReorder::next(Feature::class);
        $this->post('/admin/features', [
            'name' => 'Storage',
            'is_enabled' => true,
        ])->assertRedirect('/admin/features');

        $storage = Feature::query()->where('code', 'storage')->firstOrFail();
        $this->assertSame($expectedStoragePosition, (int) $storage->position);
        $this->post('/admin/features/bulk-status', [
            'ids' => [$storage->id],
            'enabled' => false,
        ])->assertRedirect();
        $this->assertFalse($storage->fresh()->is_enabled);
        $this->post('/admin/features/bulk-status', [
            'ids' => [$storage->id],
            'enabled' => true,
        ])->assertRedirect();
        $this->assertTrue($storage->fresh()->is_enabled);

        $this->from('/admin/features/values/create')->post('/admin/features/values', [
            'feature_id' => null,
            'value' => '8 GB',
            'is_enabled' => true,
        ])->assertSessionHasErrors(['feature_id']);

        $this->from('/admin/features/values/create')->post('/admin/features/values', [
            'feature_id' => $ram->id,
            'value' => '',
            'is_enabled' => true,
        ])->assertSessionHasErrors();

        $expectedValuePosition = \App\Domain\Catalog\Support\PositionReorder::next(FeatureValue::class, 'feature_id', $ram->id);
        $this->post('/admin/features/values', [
            'feature_id' => $ram->id,
            'value' => '8 GB',
            'is_enabled' => true,
        ])->assertRedirect('/admin/features/values?feature_id='.$ram->id);

        $this->assertSame(1, $ram->fresh()->values()->count());
        $value = FeatureValue::query()->where('feature_id', $ram->id)->first();
        $this->assertSame('8 GB', $value->translation()->value);
        $this->assertSame($expectedValuePosition, (int) $value->position);
        $this->assertNull($value->code);

        $this->post('/admin/features/values', [
            'feature_id' => $ram->id,
            'value' => '8 GB',
            'is_enabled' => true,
        ])->assertSessionHasErrors(['value']);

        $this->post('/admin/features/values', [
            'feature_id' => $ram->id,
            'value' => '16 GB',
            'is_enabled' => true,
        ])->assertRedirect('/admin/features/values?feature_id='.$ram->id);

        $sixteen = FeatureValue::query()->where('feature_id', $ram->id)->whereHas('translations', fn ($q) => $q->where('value', '16 GB'))->firstOrFail();
        $this->post('/admin/features/values/reorder', [
            'ids' => [$sixteen->id, $value->id],
        ])->assertRedirect();
        $this->assertSame(0, (int) $sixteen->fresh()->position);
        $this->assertSame(1, (int) $value->fresh()->position);

        $this->post('/admin/features/'.$ram->id.'/toggle')->assertRedirect();
        $this->assertFalse($ram->fresh()->is_enabled);

        $this->post('/admin/features/values/'.$value->id.'/toggle')->assertRedirect();
        $this->assertFalse($value->fresh()->is_enabled);

        $this->delete('/admin/features/values/'.$sixteen->id)->assertRedirect('/admin/features/values');
        $this->delete('/admin/features/values/'.$value->id)->assertRedirect('/admin/features/values');
        $this->assertNull(FeatureValue::query()->find($value->id));

        $this->delete('/admin/features/'.$ram->id)->assertRedirect('/admin/features');
        $this->assertNull(Feature::query()->find($ram->id));

        $this->post('/admin/features/bulk-delete', ['ids' => [$storage->id]])->assertRedirect();
        $this->assertNull(Feature::query()->find($storage->id));
    }

    public function test_feature_import_sample_and_csv_import(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $response = $this->get('/admin/features/import/sample?delimiter=;&multivalue_separator=,');
        $response->assertOk();
        $this->assertStringContainsString('features-sample.csv', (string) $response->headers->get('content-disposition'));

        $csv = $response->streamedContent();
        $lines = preg_split('/\r\n|\r|\n/', trim($csv));
        $header = str_getcsv($lines[0], ';');
        $this->assertSame(\App\Domain\Catalog\Support\FeatureCsvSchema::columns(), $header);
        $this->assertGreaterThanOrEqual(5, count($lines) - 1);

        $count = app(\App\Domain\Catalog\Services\FeatureService::class)->importCsv($csv, 'en', ';', ',');
        $uniqueFeatureCodes = collect(\App\Domain\Catalog\Support\FeatureCsvSchema::sampleRows())
            ->pluck('code')
            ->unique()
            ->count();
        $this->assertSame($uniqueFeatureCodes, $count);

        $color = Feature::query()->where('code', 'color')->first();
        $this->assertNotNull($color);
        $this->assertSame('Color', $color->translation('en')?->name);
        $this->assertSame('color', $color->type);
        $this->assertTrue($color->is_enabled);
        $this->assertSame(3, $color->values()->count());
        $black = $color->values()
            ->whereHas('translations', fn ($q) => $q->where('language_code', 'en')->where('value', 'Black'))
            ->first();
        $this->assertNotNull($black);
        $this->assertSame('Black', $black->translation('en')?->value);
        $this->assertTrue($black->is_enabled);

        $legacy = Feature::query()->where('code', 'legacy-finish')->first();
        $this->assertNotNull($legacy);
        $this->assertFalse($legacy->is_enabled);

        $story = Feature::query()->where('code', 'brand-story')->first();
        $this->assertNotNull($story);
        $this->assertSame(0, $story->values()->count());

        $this->get('/admin/features/export')->assertOk();

        $exported = app(\App\Domain\Catalog\Services\FeatureService::class)->exportCsv('en', null, ';', ',');
        $reimport = app(\App\Domain\Catalog\Services\FeatureService::class)->importCsv($exported, 'en', ';', ',');
        $this->assertSame($uniqueFeatureCodes, $reimport);
        $this->assertSame(3, Feature::query()->where('code', 'color')->first()?->values()->count());
    }

    public function test_feature_create_auto_assigns_position_and_edit_preserves_it(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $expected = \App\Domain\Catalog\Support\PositionReorder::next(Feature::class);
        $this->post('/admin/features', [
            'name' => 'Auto Position Feature',
            'is_enabled' => true,
        ])->assertRedirect('/admin/features');

        $feature = Feature::query()->where('code', 'auto-position-feature')->firstOrFail();
        $this->assertSame($expected, (int) $feature->position);

        $this->put('/admin/features/'.$feature->id, [
            'name' => 'Auto Position Feature Updated',
            'is_enabled' => true,
        ])->assertRedirect('/admin/features');
        $this->assertSame($expected, (int) $feature->fresh()->position);

        $csv = "code;name;description;type;is_filterable;used_for_combinations;is_enabled;position;value;value_is_enabled;value_position\n";
        $csv .= "csv-auto;CSV Auto;;select;1;0;1;;;;\n";
        $csv .= "csv-auto;CSV Auto;;select;1;0;1;;Value A;1;\n";

        $count = app(\App\Domain\Catalog\Services\FeatureService::class)->importCsv($csv, 'en', ';', ',');
        $this->assertSame(1, $count);

        $imported = Feature::query()->where('code', 'csv-auto')->firstOrFail();
        $this->assertGreaterThanOrEqual(0, (int) $imported->position);
        $value = $imported->values()->first();
        $this->assertNotNull($value);
        $this->assertSame('Value A', $value->translation('en')?->value);
    }

    public function test_feature_bulk_duplicate_copies_values_and_assigns_next_position(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->post('/admin/features', [
            'name' => 'Bulk Dup Feature',
            'is_enabled' => true,
        ])->assertRedirect('/admin/features');

        $feature = Feature::query()->where('code', 'bulk-dup-feature')->firstOrFail();
        $this->post('/admin/features/values', [
            'feature_id' => $feature->id,
            'value' => 'Option A',
            'is_enabled' => true,
        ])->assertRedirect();

        $expected = \App\Domain\Catalog\Support\PositionReorder::next(Feature::class);
        $this->post('/admin/features/bulk-duplicate', [
            'ids' => [$feature->id],
        ])->assertRedirect();

        $copy = Feature::query()
            ->whereHas('translations', fn ($q) => $q->where('name', 'Bulk Dup Feature (copy)'))
            ->first();
        $this->assertNotNull($copy);
        $this->assertFalse($copy->is_enabled);
        $this->assertSame($expected, (int) $copy->position);
        $this->assertSame(1, $copy->values()->count());
        $this->assertSame('Option A', $copy->values()->first()?->translation('en')?->value);
    }
}
