<?php

namespace Tests\Feature;

use App\Domain\Catalog\MerchantStoreBuilder;
use App\Domain\Catalog\MerchantProductCatalog;
use App\Domain\Catalog\Models\Category;
use App\Domain\Installer\InstallSchemaGuard;
use Database\Seeders\CoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DemoInstallSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_install_can_store_category_cover_path(): void
    {
        $this->seed(CoreSeeder::class);

        $category = Category::query()->orderBy('id')->first();
        $this->assertNotNull($category);

        $path = 'catalog/categories/'.$category->id.'/banner.png';
        $category->update(['cover_path' => $path]);

        $this->assertSame($path, $category->fresh()->cover_path);
    }

    public function test_merchant_demo_categories_populate_every_storefront_image_role(): void
    {
        $this->seed(CoreSeeder::class);

        $method = new \ReflectionMethod(MerchantStoreBuilder::class, 'seedCategories');
        $categories = $method->invoke(app(MerchantStoreBuilder::class));
        $category = $categories['sports-outdoors']->fresh();

        $this->assertNotNull($category->image_path);
        $this->assertSame($category->image_path, $category->thumbnail_path);
        $this->assertSame($category->image_path, $category->banner_path);
        $this->assertSame($category->image_path, $category->cover_path);
        $this->assertTrue(Storage::disk('public')->exists($category->image_path));

        $definition = collect(MerchantProductCatalog::categories())
            ->firstWhere('slug', 'sports-outdoors');
        $this->assertSame('categories/sports.png', $definition['image']);
        $this->assertFileExists(base_path(MerchantStoreBuilder::ASSET_ROOT.'/'.$definition['image']));
    }

    public function test_install_schema_dump_defines_category_cover_path(): void
    {
        $sql = (string) file_get_contents(database_path('schema/mysql-schema.sql'));
        $this->assertNotFalse(
            preg_match(
                '/CREATE TABLE IF NOT EXISTS `__DB_PREFIX__categories` \(.*?`cover_path` varchar\(255\)/s',
                $sql
            ),
            'mysql-schema.sql must create categories.cover_path. The wizard imports this dump instead of replaying every migration.'
        );
        $this->assertStringContainsString(
            '2026_09_07_103000_add_cover_path_to_categories',
            $sql
        );
    }

    public function test_schema_guard_adds_missing_category_cover_path(): void
    {
        $this->assertTrue(Schema::hasColumn('categories', 'cover_path'));

        Schema::table('categories', function ($table) {
            $table->dropColumn('cover_path');
        });
        $this->assertFalse(Schema::hasColumn('categories', 'cover_path'));

        InstallSchemaGuard::ensure();
        $this->assertTrue(
            InstallSchemaGuard::hasColumn('categories', 'cover_path'),
            'InstallSchemaGuard must add categories.cover_path when the dump or a skipped migration omitted it.'
        );
        $this->assertTrue(Schema::hasColumn('categories', 'cover_path'));
    }

    public function test_schema_guard_write_adds_missing_cover_path(): void
    {
        $this->seed(CoreSeeder::class);

        Schema::table('categories', function ($table) {
            $table->dropColumn('cover_path');
        });
        $this->assertFalse(Schema::hasColumn('categories', 'cover_path'));

        $category = Category::query()->orderBy('id')->first();
        $this->assertNotNull($category);
        $path = 'catalog/categories/'.$category->id.'/banner.png';
        InstallSchemaGuard::write($category, ['cover_path' => $path]);

        $this->assertTrue(Schema::hasColumn('categories', 'cover_path'));
        $this->assertSame($path, $category->fresh()->cover_path);
    }
}
