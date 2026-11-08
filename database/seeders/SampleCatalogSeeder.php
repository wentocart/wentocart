<?php

namespace Database\Seeders;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Feature;
use App\Domain\Catalog\Models\FeatureValue;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Catalog\Models\UrlRewrite;
use App\Domain\Catalog\Models\Warehouse;
use App\Domain\Catalog\Services\ProductService;
use App\Domain\Catalog\Services\UrlRewriteService;
use App\Domain\Cms\Models\CmsBlock;
use App\Domain\Cms\Models\CmsPage;
use App\Domain\Cms\Models\Menu;
use App\Domain\Cms\Models\MenuItem;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Domain\Tax\Models\TaxClass;
use Illuminate\Database\Seeder;

/**
 * Mini catalog used by PHPUnit and `php artisan db:seed`.
 * The installer never calls this — clean installs stay empty.
 */
class SampleCatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['name' => 'Delhi', 'code' => 'delhi', 'is_default' => false, 'is_enabled' => true],
            ['name' => 'Mumbai', 'code' => 'mumbai', 'is_default' => false, 'is_enabled' => true],
            ['name' => 'Bangalore', 'code' => 'bangalore', 'is_default' => false, 'is_enabled' => true],
        ] as $row) {
            $warehouse = Warehouse::query()->firstOrNew(['code' => $row['code']]);
            $warehouse->forceFill($row)->save();
        }

        $tax = TaxClass::query()->firstOrCreate(['name' => 'Standard']);

        PaymentMethod::query()->updateOrCreate(
            ['code' => 'invoice'],
            [
                'name' => 'Pay on account (Net terms)',
                'description' => 'Invoice now, pay within your company or group terms.',
                'driver' => 'offline',
                'is_enabled' => true,
                'position' => 40,
                'config' => ['net_terms' => true],
                'rules' => PaymentMethod::emptyRules(),
            ]
        );

        foreach (['bank_transfer', 'cod', 'invoice'] as $code) {
            PaymentMethod::query()->where('code', $code)->update(['is_enabled' => true]);
        }

        ShippingMethod::query()->updateOrCreate(
            ['code' => 'sandbox-ground'],
            [
                'name' => 'Sandbox ground',
                'description' => 'Quoted locally. No FedEx/DHL account. Buy a fake label from the order.',
                'type' => 'carrier',
                'driver' => 'sandbox',
                'price' => 8,
                'is_enabled' => true,
                'position' => 3,
                'conditions' => ShippingMethod::emptyConditions(),
                'rate_config' => ['tiers' => []],
                'config' => ['base' => 8, 'per_kg' => 2, 'intl_surcharge' => 12, 'origin_country' => 'US'],
            ]
        );
        foreach (['flat', 'free', 'sandbox-ground'] as $code) {
            $method = ShippingMethod::query()->where('code', $code)->first();
            if (! $method) {
                continue;
            }
            $payload = ['is_enabled' => true];
            if ($code === 'flat') {
                $payload['price'] = 5;
            }
            if ($code === 'free') {
                $payload['conditions'] = array_merge(ShippingMethod::emptyConditions(), ['min_order' => 2000]);
            }
            $method->forceFill($payload)->save();
        }

        $category = Category::query()
            ->whereHas('translations', fn ($t) => $t->where('slug', 'catalog')->where('language_code', 'en'))
            ->first();
        if (! $category) {
            $category = Category::query()->create(['is_enabled' => true, 'position' => 1]);
            $category->translations()->create([
                'language_code' => 'en',
                'name' => 'Catalog',
                'slug' => 'catalog',
                'description' => 'Default category',
                'meta_title' => 'Catalog',
            ]);
            app(UrlRewriteService::class)->sync('category', $category->id, 'en', 'catalog');
        }

        $brand = Brand::query()
            ->whereHas('translations', fn ($t) => $t->where('slug', 'wento')->where('language_code', 'en'))
            ->first();
        if (! $brand) {
            $brand = Brand::query()->create(['is_enabled' => true]);
            $brand->translations()->create(['language_code' => 'en', 'name' => 'Wento', 'slug' => 'wento']);
            app(UrlRewriteService::class)->sync('brand', $brand->id, 'en', 'wento', 'brand');
        }

        $supplier = Supplier::query()
            ->whereHas('translations', fn ($t) => $t->where('slug', 'wento-supply')->where('language_code', 'en'))
            ->first();
        if (! $supplier) {
            $supplier = Supplier::query()->create([
                'email' => 'buying@wento.test',
                'phone' => '+1 555 0100',
                'is_enabled' => true,
            ]);
            $supplier->translations()->create([
                'language_code' => 'en',
                'name' => 'Wento Supply Co',
                'slug' => 'wento-supply',
                'description' => 'Default demo supplier for catalog products.',
            ]);
            app(UrlRewriteService::class)->sync('supplier', $supplier->id, 'en', 'wento-supply', 'supplier');
        }

        $feature = Feature::query()->firstOrCreate(
            ['code' => 'material'],
            ['is_filterable' => true, 'used_for_combinations' => true]
        );
        if ($feature->wasRecentlyCreated) {
            $feature->translations()->create(['language_code' => 'en', 'name' => 'Material']);
        }
        $cotton = FeatureValue::query()->where('feature_id', $feature->id)->first();
        if (! $cotton) {
            $cotton = FeatureValue::query()->create(['feature_id' => $feature->id, 'position' => 1]);
            $cotton->translations()->create(['language_code' => 'en', 'value' => 'Cotton']);
        }

        if (! config('wento.installing_demo')) {
            $exists = \App\Domain\Catalog\Models\Product::query()->where('sku', 'DEMO-001')->exists();
            if (! $exists) {
                app(ProductService::class)->save([
                    'product' => [
                        'type' => 'simple',
                        'sku' => 'DEMO-001',
                        'price' => 29.99,
                        'stock_qty' => 50,
                        'tax_class_id' => $tax->id,
                        'brand_id' => $brand->id,
                        'supplier_id' => $supplier->id,
                        'default_category_id' => $category->id,
                        'visibility' => 'both',
                        'is_enabled' => true,
                        'is_new' => true,
                        'weight' => 0.4,
                    ],
                    'translation' => [
                        'language_code' => 'en',
                        'name' => 'Demo Cotton Tee',
                        'slug' => 'demo-cotton-tee',
                        'short_description' => 'A simple product for local development.',
                        'description' => 'Phase 1 simple product used to verify catalog through checkout.',
                        'meta_title' => 'Demo Cotton Tee',
                        'meta_description' => 'Demo product for WentoCart.',
                    ],
                    'category_ids' => [$category->id],
                    'supplier_ids' => [$supplier->id],
                    'feature_value_ids' => [
                        ['feature_id' => $feature->id, 'feature_value_id' => $cotton->id],
                    ],
                ]);
            }
        }

        CmsBlock::query()->updateOrCreate(
            ['code' => 'home_hero'],
            ['title' => 'Home hero', 'location' => 'home', 'content' => 'Welcome to WentoCart', 'is_enabled' => true, 'position' => 1]
        );

        $menu = Menu::query()->updateOrCreate(['code' => 'main'], ['name' => 'Main menu']);
        $catalogPath = UrlRewrite::query()
            ->where('entity_type', 'category')
            ->where('entity_id', $category->id)
            ->value('request_path') ?: 'catalog';
        $about = CmsPage::query()
            ->whereHas('translations', fn ($t) => $t->where('slug', 'about')->where('language_code', 'en'))
            ->first();
        $aboutPath = $about
            ? (UrlRewrite::query()->where('entity_type', 'cms')->where('entity_id', $about->id)->value('request_path') ?: 'about')
            : 'about';

        if (! MenuItem::query()->where('menu_id', $menu->id)->where('label', 'Catalog')->exists()) {
            MenuItem::query()->create(['menu_id' => $menu->id, 'label' => 'Catalog', 'url' => '/'.$catalogPath, 'position' => 2]);
        }
        if (! MenuItem::query()->where('menu_id', $menu->id)->where('label', 'About')->exists()) {
            MenuItem::query()->create(['menu_id' => $menu->id, 'label' => 'About', 'url' => '/'.$aboutPath, 'position' => 3]);
        }
    }
}
