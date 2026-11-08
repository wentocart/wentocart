<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Feature;
use App\Domain\Catalog\Services\AttributeService;
use App\Domain\Catalog\Services\BrandService;
use App\Domain\Catalog\Services\CategoryService;
use App\Domain\Catalog\Services\FeatureService;
use App\Domain\Catalog\Services\ProductService;
use App\Domain\Catalog\Services\SupplierService;
use App\Domain\Catalog\Support\AttributeCsvSchema;
use App\Domain\Catalog\Support\BrandCsvSchema;
use App\Domain\Catalog\Support\CategoryCsvSchema;
use App\Domain\Catalog\Support\FeatureCsvSchema;
use App\Domain\Catalog\Support\ProductCsvSchema;
use App\Domain\Catalog\Support\SupplierCsvSchema;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CatalogCsvImportValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_feature_sample_csv_imports_successfully(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $before = Feature::query()->count();
        $csv = FeatureCsvSchema::sampleCsv(';', ',');
        $count = app(FeatureService::class)->importCsv($csv, 'en', ';', ',');

        $this->assertGreaterThan(0, $count);
        $this->assertGreaterThan($before, Feature::query()->count());
    }

    public function test_feature_import_rejects_other_entity_sample_csvs(): void
    {
        $this->seed();
        $features = app(FeatureService::class);
        $before = Feature::query()->count();

        $cases = [
            'Category' => CategoryCsvSchema::sampleCsv(';', ','),
            'Product' => ProductCsvSchema::sampleCsv(';', ','),
            'Brand' => BrandCsvSchema::sampleCsv(';', ','),
            'Supplier' => SupplierCsvSchema::sampleCsv(';', ','),
            'Attribute' => AttributeCsvSchema::sampleCsv(';', ','),
        ];

        foreach ($cases as $label => $csv) {
            try {
                $features->importCsv($csv, 'en', ';', ',');
                $this->fail("Expected Feature import to reject {$label} CSV.");
            } catch (ValidationException $e) {
                $message = (string) collect($e->errors()['file'] ?? [])->first();
                $this->assertStringContainsString('not compatible with Feature import', $message);
                $this->assertStringContainsString('Expected Feature fields:', $message);
            }
        }

        $this->assertSame($before, Feature::query()->count());
    }

    public function test_feature_import_rejects_empty_csv_and_missing_headers(): void
    {
        $this->seed();
        $features = app(FeatureService::class);
        $before = Feature::query()->count();

        try {
            $features->importCsv('', 'en', ';', ',');
            $this->fail('Expected empty CSV to fail.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors()['file'] ?? []);
        }

        try {
            $features->importCsv("code;name\ncolor;Color\n", 'en', ';', ',');
            $this->fail('Expected incomplete Feature headers to fail.');
        } catch (ValidationException $e) {
            $message = (string) collect($e->errors()['file'] ?? [])->first();
            $this->assertStringContainsString('not compatible with Feature import', $message);
        }

        $this->assertSame($before, Feature::query()->count());
    }

    public function test_feature_import_rejects_invalid_rows_without_writing(): void
    {
        $this->seed();
        $features = app(FeatureService::class);
        $before = Feature::query()->count();

        $header = implode(';', FeatureCsvSchema::columns());
        $csv = $header."\n";
        $csv .= "bad;;;select;1;1;1;1;;;\n"; // missing name
        $csv .= "ok;Valid;;not-a-type;1;1;1;1;;;\n"; // invalid type

        try {
            $features->importCsv($csv, 'en', ';', ',');
            $this->fail('Expected invalid Feature rows to fail.');
        } catch (ValidationException $e) {
            $message = (string) collect($e->errors()['file'] ?? [])->first();
            $this->assertStringContainsString('Feature name is required', $message);
            $this->assertStringContainsString('Invalid Feature type', $message);
        }

        $this->assertSame($before, Feature::query()->count());
    }

    public function test_feature_http_import_rejects_category_csv_without_success_flash(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');
        $before = Feature::query()->count();

        $file = UploadedFile::fake()->createWithContent(
            'categories-sample.csv',
            CategoryCsvSchema::sampleCsv(';', ','),
        );

        $this->from('/admin/features')
            ->post('/admin/features/import', [
                'file' => $file,
                'language' => 'en',
                'delimiter' => ';',
                'multivalue_separator' => ',',
            ])
            ->assertRedirect('/admin/features')
            ->assertSessionHasErrors('file')
            ->assertSessionMissing('success');

        $this->assertSame($before, Feature::query()->count());
    }

    public function test_each_catalog_importer_rejects_foreign_sample_csv(): void
    {
        $this->seed();

        $matrix = [
            [FeatureService::class, FeatureCsvSchema::class, 'Feature'],
            [CategoryService::class, CategoryCsvSchema::class, 'Category'],
            [BrandService::class, BrandCsvSchema::class, 'Brand'],
            [SupplierService::class, SupplierCsvSchema::class, 'Supplier'],
            [AttributeService::class, AttributeCsvSchema::class, 'Attribute'],
            [ProductService::class, ProductCsvSchema::class, 'Product'],
        ];

        foreach ($matrix as [$serviceClass, $ownSchema, $label]) {
            $service = app($serviceClass);
            foreach ($matrix as [$otherService, $otherSchema, $otherLabel]) {
                if ($ownSchema === $otherSchema) {
                    continue;
                }
                try {
                    $service->importCsv($otherSchema::sampleCsv(';', ','), 'en', ';', ',');
                    $this->fail("Expected {$label} import to reject {$otherLabel} sample CSV.");
                } catch (ValidationException $e) {
                    $message = (string) collect($e->errors()['file'] ?? [])->first();
                    $this->assertStringContainsString("not compatible with {$label} import", $message);
                }
            }
        }
    }
}
