<?php

namespace Tests\Feature;

use App\Domain\Auth\Models\Profile;
use App\Domain\Auth\Models\Team;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Feature;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Catalog\Models\Warehouse;
use App\Domain\Cms\Models\CmsPage;
use App\Domain\Installer\DemoDataService;
use App\Domain\Installer\SchemaBootstrap;
use App\Domain\Localization\Models\Currency;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Domain\Theme\ThemeSettingsCatalog;
use App\Models\Admin;
use Database\Seeders\CoreSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CleanInstallSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_seeder_leaves_an_empty_catalog_and_disabled_methods(): void
    {
        config(['wento.installing' => true]);
        $this->seed(CoreSeeder::class);

        $this->assertSame(0, Product::query()->count());
        $this->assertSame(0, Category::query()->count());
        $this->assertSame(0, Brand::query()->count());
        $this->assertSame(0, Supplier::query()->count());
        $this->assertSame(0, Feature::query()->count());
        $this->assertSame(0, Admin::query()->count());
        $this->assertSame(['default'], Warehouse::query()->orderBy('id')->pluck('code')->all());

        $this->assertFalse((bool) PaymentMethod::query()->where('code', 'cod')->value('is_enabled'));
        $this->assertFalse((bool) PaymentMethod::query()->where('code', 'bank_transfer')->value('is_enabled'));
        $this->assertFalse((bool) ShippingMethod::query()->where('code', 'flat')->value('is_enabled'));
        $this->assertFalse((bool) ShippingMethod::query()->where('code', 'free')->value('is_enabled'));
        $this->assertFalse((bool) ShippingMethod::query()->where('code', 'local_pickup')->value('is_enabled'));
        $this->assertNull(ShippingMethod::query()->where('code', 'sandbox-ground')->first());
        $this->assertNull(PaymentMethod::query()->where('code', 'invoice')->first());

        $this->assertSame(1, Currency::query()->orderBy('id')->value('id'));
        $this->assertEqualsCanonicalizing(['catalog_editor', 'order_desk', 'customer_care', 'content_editor'], Profile::query()->pluck('slug')->all());
        $this->assertEqualsCanonicalizing(['operations', 'fulfilment', 'sales', 'marketing'], Team::query()->pluck('slug')->all());

        foreach (['about', 'contact', 'help', 'faq', 'stores', 'privacy', 'terms', 'shipping', 'returns', 'order-tracking'] as $slug) {
            $this->assertTrue(
                CmsPage::query()->whereHas('translations', fn ($t) => $t->where('slug', $slug)->where('language_code', 'en'))->exists(),
                $slug.' stub missing'
            );
        }

        $this->getJson('/api/v1/catalog/category/catalog')->assertOk();
        $this->getJson('/api/v1/catalog/category/new-arrivals')->assertOk();
        $this->getJson('/api/v1/catalog/page/stores')->assertOk();
        $this->getJson('/api/v1/catalog/page/contact')->assertOk();
        $this->getJson('/api/v1/checkout/options')->assertOk()
            ->assertJsonPath('payment_methods', [])
            ->assertJsonPath('shipping_methods', []);
    }

    public function test_database_seeder_still_provides_demo_product_for_tests(): void
    {
        $this->seed();

        $this->assertNotNull(Product::query()->where('sku', 'DEMO-001')->first());
        $this->assertTrue((bool) PaymentMethod::query()->where('code', 'cod')->value('is_enabled'));
        $this->assertNotNull(ShippingMethod::query()->where('code', 'sandbox-ground')->first());
        $this->assertSame(1, Admin::query()->count());
    }

    public function test_demo_catalog_enables_checkout_and_keeps_staff_shape(): void
    {
        config(['wento.installing' => true, 'wento.installing_demo' => true]);
        $this->seed(CoreSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->assertTrue((bool) PaymentMethod::query()->where('code', 'cod')->value('is_enabled'));
        $this->assertTrue((bool) PaymentMethod::query()->where('code', 'bank_transfer')->value('is_enabled'));
        $this->assertTrue((bool) ShippingMethod::query()->where('code', 'flat')->value('is_enabled'));
        $this->assertNull(ShippingMethod::query()->where('code', 'sandbox-ground')->first());
        $this->assertSame(0, Admin::query()->count());
        $this->assertCount(4, Profile::query()->get());
        $this->assertCount(4, Team::query()->get());

        $this->assertTrue(
            CmsPage::query()->whereHas('translations', fn ($t) => $t->where('slug', 'privacy')->where('language_code', 'en'))->exists()
        );
        $this->getJson('/api/v1/catalog/page/help')->assertOk();
        $this->getJson('/api/v1/catalog/page/shipping')->assertOk();
    }

    public function test_removing_demo_data_restores_the_empty_catalog(): void
    {
        config(['wento.installing' => true, 'wento.installing_demo' => true]);
        $this->seed(CoreSeeder::class);
        $demo = app(DemoDataService::class);
        $demo->installCatalog();

        $this->assertTrue($demo->installed());
        $this->assertTrue($demo->remove());
        $this->assertFalse($demo->installed());
        $this->assertSame(0, Product::query()->count());
        $this->assertSame(0, Category::query()->count());
        $this->assertSame(0, Brand::query()->count());
        $this->assertFalse((bool) PaymentMethod::query()->where('code', 'cod')->value('is_enabled'));
        $this->assertFalse((bool) ShippingMethod::query()->where('code', 'flat')->value('is_enabled'));
        $this->assertTrue(
            CmsPage::query()->whereHas('translations', fn ($t) => $t->where('slug', 'about')->where('language_code', 'en'))->exists()
        );
    }

    public function test_core_install_without_demo_stays_empty_and_unmarked(): void
    {
        config(['wento.installing' => true, 'wento.installing_demo' => false]);
        $this->seed(CoreSeeder::class);

        $this->assertFalse(app(DemoDataService::class)->installed());
        $this->assertSame(0, Product::query()->count());
        $this->assertSame(0, Category::query()->count());
        $this->assertFalse((bool) PaymentMethod::query()->where('code', 'cod')->value('is_enabled'));
    }

    public function test_theme_footer_stores_points_at_stores_not_contact(): void
    {
        $defaults = ThemeSettingsCatalog::defaults();
        $company = collect($defaults['home']['footer']['columns'] ?? [])->firstWhere('title', 'Our Company');
        $stores = collect($company['links'] ?? [])->firstWhere('label', 'Stores');
        $this->assertSame('/stores', $stores['url'] ?? null);
    }

    public function test_schema_import_strips_baked_auto_increment(): void
    {
        $sql = 'CREATE TABLE t (`id` int) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4;';
        $this->assertStringNotContainsString('AUTO_INCREMENT=', SchemaBootstrap::stripAutoIncrementCounters($sql));
    }
}
