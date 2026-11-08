<?php

namespace Tests;

use App\Domain\Plugin\PluginInstaller;
use App\Domain\Plugin\PluginKernel;
use App\Domain\Store\StoreBootstrap;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Theme\ThemeInstaller;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use ZipArchive;

abstract class TestCase extends BaseTestCase
{
    protected static bool $classicThemePublished = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->withHeader('Origin', 'http://localhost');
        $this->withHeader('Referer', 'http://localhost');
        PluginKernel::reset();
        StoreBootstrap::reset();
        if (! TestResponse::hasMacro('assertProductSaveRedirect')) {
            TestResponse::macro('assertProductSaveRedirect', function (?string $sku = null) {
                /** @var TestResponse $this */
                $saved = $sku
                    ? Product::query()->where('sku', $sku)->orderByDesc('id')->first()
                    : Product::query()->orderByDesc('id')->first();
                Assert::assertNotNull($saved, 'Expected a saved product after the request.');
                $this->assertRedirect('/admin/products/'.$saved->id.'/edit');

                return $this;
            });
        }
        if (! self::$classicThemePublished) {
            app(ThemeInstaller::class)->publishPublic('classic', false);
            self::$classicThemePublished = true;
        }
    }

    /** @param  array<string, mixed>  $payload */
    protected function withProductCategories(array $payload = []): array
    {
        $categoryId = (int) Category::query()->value('id');
        $this->assertGreaterThan(0, $categoryId, 'Expected at least one category in the database.');

        return array_merge([
            'category_ids' => [$categoryId],
            'default_category_id' => $categoryId,
        ], $payload);
    }

    protected function assertRedirectToProductEdit($response, Product|int|string|null $product = null): Product
    {
        if ($product instanceof Product) {
            $saved = $product;
        } elseif (is_int($product) || (is_string($product) && ctype_digit($product))) {
            $saved = Product::query()->find((int) $product);
        } elseif (is_string($product) && $product !== '') {
            $saved = Product::query()->where('sku', $product)->orderByDesc('id')->first();
        } else {
            $saved = Product::query()->orderByDesc('id')->first();
        }

        $this->assertNotNull($saved, 'Expected a saved product after the request.');
        $response->assertRedirect('/admin/products/'.$saved->id.'/edit');

        return $saved;
    }

    protected function installFixtureExtension(string $vendor, string $name): void
    {
        $root = base_path('tests/Fixtures/extensions/'.$vendor.'/'.$name);
        $this->assertDirectoryExists($root, $vendor.'/'.$name.' fixture is missing.');

        $path = storage_path('framework/testing/'.uniqid('fixture_', true).'.zip');
        File::ensureDirectoryExists(dirname($path));
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE) === true);
        foreach (File::allFiles($root) as $file) {
            $relative = str_replace('\\', '/', $file->getRelativePathname());
            // Scanner rejects index.php placeholders (controllers must be *Controller.php).
            if (basename($relative) === 'index.php') {
                continue;
            }
            $zip->addFile($file->getPathname(), $relative);
        }
        $zip->close();

        app(PluginInstaller::class)->install(
            new UploadedFile($path, $name.'.zip', 'application/zip', null, true)
        );
        PluginKernel::reset();
    }
}
