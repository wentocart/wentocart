<?php

namespace App\Domain\Installer;

use App\Domain\Catalog\MerchantProductCatalog;
use App\Domain\Cms\CoreCmsStubs;
use App\Domain\Cms\Models\CmsBlock;
use App\Domain\Cms\Models\Menu;
use App\Domain\Cms\Models\MenuItem;
use App\Domain\Ops\ShopStats;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Promo\Models\DiscountRule;
use App\Domain\Settings\Models\Setting;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Domain\Store\Models\Store;
use App\Domain\Theme\ThemeInstaller;
use App\Domain\Theme\ThemeSettingsCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class DemoDataService
{
    public function installed(): bool
    {
        return Setting::getValue('demo_data_installed') === '1';
    }

    /**
     * @return array{products: int, customers: int, orders: int, payments: int, shipping: int, discounts: int, installed: bool}
     */
    public function snapshot(): array
    {
        return [
            'products' => ShopStats::estimate('products'),
            'customers' => ShopStats::estimate('customers'),
            'orders' => ShopStats::estimate('orders'),
            'payments' => PaymentMethod::query()->where('is_enabled', true)->count(),
            'shipping' => ShippingMethod::query()->where('is_enabled', true)->count(),
            'discounts' => DiscountRule::query()->count(),
            'installed' => $this->installed(),
        ];
    }

    /**
     * Theme files for the storefront. Independent of sample products.
     *
     * @param  (callable(string): void)|null  $progress
     */
    public function publishDesign(?callable $progress = null): void
    {
        $this->ensurePublicStorageLink();

        // Incremental publish: merchant zips that already have public/themes skip the
        // full copy; git checkouts still pick up new files (footer icons, CSS).
        InstallProgress::note($progress, 'Installing store design…');
        InstallProgress::tick($progress, 'design', 0, 1, 'Installing theme assets…');
        $themeStarted = microtime(true);
        app(ThemeInstaller::class)->publishPublic('classic', false);
        InstallProgress::tick($progress, 'design', 1, 1, 'Theme assets installed');
        InstallProgress::stepDone($progress, 'Theme assets', $themeStarted);
    }

    /**
     * Sample catalog, customers, and orders.
     *
     * @param  (callable(string): void)|null  $progress
     */
    public function installCatalog(?callable $progress = null): void
    {
        DatabaseServerTune::raise($progress);
        InstallProgress::note($progress, 'Adding sample products, customers, and orders…');
        config(['wento.install_progress' => $progress]);
        app(DemoCatalogInstaller::class)->run();

        $meter = DemoInstallMeter::current();
        if ($meter instanceof DemoInstallMeter) {
            $meter->complete('Demo data is ready');
        } else {
            InstallProgress::tick($progress, 'demo', 1, 1, 'Demo data is ready');
        }
        config(['wento.install_progress' => null]);
        config(['wento.demo_meter' => null]);
        Setting::setValue('demo_data_installed', '1');
    }

    /**
     * @param  (callable(string): void)|null  $progress
     */
    public function install(?callable $progress = null): bool
    {
        if ($this->installed()) {
            return false;
        }

        $this->publishDesign($progress);
        $this->installCatalog($progress);

        return true;
    }

    /**
     * Drop sample catalog/customers/orders written by installCatalog().
     * Leaves the admin account, CoreSeeder methods, and shop configuration.
     */
    public function remove(): bool
    {
        if (! $this->installed()) {
            return false;
        }

        DB::transaction(function () {
            $this->removeDemoRows();
        });

        CoreCmsStubs::restore();
        $this->restoreEmptyStorefrontChrome();
        Setting::setValue('demo_data_installed', '0');

        return true;
    }

    /**
     * Empty `public/storage` directories (common on Windows helper installs) break every
     * /storage/… image URL. Replace with a real link to storage/app/public.
     */
    protected function ensurePublicStorageLink(): void
    {
        PublicStorageLink::ensure();
    }

    protected function removeDemoRows(): void
    {
        $productIds = $this->demoProductIds();
        $customerIds = $this->demoCustomerIds();
        $orderIds = $this->demoOrderIds($customerIds, $productIds);

        Schema::disableForeignKeyConstraints();
        $this->deleteOrders($orderIds);
        $this->deleteCustomerExtras($customerIds, $productIds);
        $this->deleteProducts($productIds);
        $this->deleteUnusedDemoTaxonomy();
        $this->deleteDemoDiscounts();
        Schema::enableForeignKeyConstraints();

        $this->restoreCoreCheckoutMethods();
        $this->resetOrderSequence();
    }

    /** @return list<int> */
    protected function demoProductIds(): array
    {
        if (! Schema::hasTable('products')) {
            return [];
        }

        return DB::table('products')
            ->where(function ($query) {
                $query->where('sku', 'like', 'MERCH-%')
                    ->orWhere('sku', 'DEMO-001')
                    ->orWhere('sku', 'like', 'EL-%')
                    ->orWhere('sku', 'like', 'FA-%')
                    ->orWhere('sku', 'like', 'HM-%')
                    ->orWhere('sku', 'like', 'BE-%')
                    ->orWhere('sku', 'like', 'SP-%')
                    ->orWhere('sku', 'like', 'GR-%')
                    ->orWhere('sku', 'like', 'TY-%')
                    ->orWhere('sku', 'like', 'AU-%')
                    ->orWhere('sku', 'like', 'OF-%');
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** @return list<int> */
    protected function demoCustomerIds(): array
    {
        if (! Schema::hasTable('customers')) {
            return [];
        }

        return DB::table('customers')
            ->where('email', 'demo@wentocart.test')
            ->orWhere('email', 'like', '%@demo.wentocart.test')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $customerIds
     * @param  list<int>  $productIds
     * @return list<int>
     */
    protected function demoOrderIds(array $customerIds, array $productIds): array
    {
        if (! Schema::hasTable('orders')) {
            return [];
        }

        $ids = [];
        if ($customerIds !== []) {
            $ids = array_merge($ids, DB::table('orders')->whereIn('customer_id', $customerIds)->pluck('id')->all());
        }
        if ($productIds !== [] && Schema::hasTable('order_items')) {
            $ids = array_merge($ids, DB::table('order_items')->whereIn('product_id', $productIds)->pluck('order_id')->all());
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** @param  list<int>  $orderIds */
    protected function deleteOrders(array $orderIds): void
    {
        if ($orderIds === []) {
            return;
        }

        foreach (['order_status_history', 'order_items'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->whereIn('order_id', $orderIds)->delete();
            }
        }
        DB::table('orders')->whereIn('id', $orderIds)->delete();
    }

    /**
     * @param  list<int>  $customerIds
     * @param  list<int>  $productIds
     */
    protected function deleteCustomerExtras(array $customerIds, array $productIds): void
    {
        $questionIds = [];
        if (Schema::hasTable('product_questions') && ($customerIds !== [] || $productIds !== [])) {
            $questionQuery = DB::table('product_questions');
            $questionQuery->where(function ($query) use ($customerIds, $productIds) {
                if ($customerIds !== []) {
                    $query->whereIn('customer_id', $customerIds);
                }
                if ($productIds !== []) {
                    $query->orWhereIn('product_id', $productIds);
                }
            });
            $questionIds = $questionQuery->pluck('id')->map(fn ($id) => (int) $id)->all();
        }
        if ($questionIds !== [] && Schema::hasTable('product_answers')) {
            DB::table('product_answers')->whereIn('product_question_id', $questionIds)->delete();
        }
        if ($questionIds !== []) {
            DB::table('product_questions')->whereIn('id', $questionIds)->delete();
        }

        if (Schema::hasTable('reviews') && ($customerIds !== [] || $productIds !== [])) {
            DB::table('reviews')->where(function ($query) use ($customerIds, $productIds) {
                if ($customerIds !== []) {
                    $query->whereIn('customer_id', $customerIds);
                }
                if ($productIds !== []) {
                    $query->orWhereIn('product_id', $productIds);
                }
            })->delete();
        }

        if ($customerIds !== []) {
            if (Schema::hasTable('wishlists')) {
                DB::table('wishlists')->whereIn('customer_id', $customerIds)->delete();
            }
            if (Schema::hasTable('wishlist_items')) {
                DB::table('wishlist_items')->whereIn('customer_id', $customerIds)->delete();
            }
            if (Schema::hasTable('customer_addresses')) {
                DB::table('customer_addresses')->whereIn('customer_id', $customerIds)->delete();
            }
            DB::table('customers')->whereIn('id', $customerIds)->delete();
        }
    }

    /** @param  list<int>  $productIds */
    protected function deleteProducts(array $productIds): void
    {
        if ($productIds === []) {
            return;
        }

        foreach ([
            'product_relations',
            'product_images',
            'product_attachments',
            'attachment_product',
            'product_feature_value',
            'product_translations',
            'category_product',
            'cart_items',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $column = $table === 'product_relations' || $table === 'cart_items'
                ? 'product_id'
                : (Schema::hasColumn($table, 'product_id') ? 'product_id' : null);
            if ($column) {
                DB::table($table)->whereIn($column, $productIds)->delete();
            }
        }
        if (Schema::hasTable('url_rewrites')) {
            DB::table('url_rewrites')->where('entity_type', 'product')->whereIn('entity_id', $productIds)->delete();
        }
        DB::table('products')->whereIn('id', $productIds)->delete();
        foreach ($productIds as $id) {
            Storage::disk('public')->deleteDirectory('catalog/products/'.$id);
        }
    }

    protected function deleteUnusedDemoTaxonomy(): void
    {
        $categorySlugs = $this->demoCategorySlugs();
        $brandSlugs = $this->demoBrandSlugs();

        if (Schema::hasTable('categories') && Schema::hasTable('category_translations') && $categorySlugs !== []) {
            $categoryIds = DB::table('category_translations')
                ->where('language_code', 'en')
                ->whereIn('slug', $categorySlugs)
                ->pluck('category_id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $used = [];
            if ($categoryIds !== [] && Schema::hasTable('category_product')) {
                $used = DB::table('category_product')->whereIn('category_id', $categoryIds)->pluck('category_id')->all();
            }
            $unused = array_values(array_diff($categoryIds, array_map('intval', $used)));
            if ($unused !== []) {
                if (Schema::hasTable('url_rewrites')) {
                    DB::table('url_rewrites')->where('entity_type', 'category')->whereIn('entity_id', $unused)->delete();
                }
                DB::table('category_translations')->whereIn('category_id', $unused)->delete();
                DB::table('categories')->whereIn('id', $unused)->delete();
                foreach ($unused as $id) {
                    Storage::disk('public')->deleteDirectory('catalog/categories/'.$id);
                }
            }
        }

        if (Schema::hasTable('brands') && Schema::hasTable('brand_translations') && $brandSlugs !== []) {
            $brandIds = DB::table('brand_translations')
                ->whereIn('slug', $brandSlugs)
                ->pluck('brand_id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $used = [];
            if ($brandIds !== [] && Schema::hasTable('products')) {
                $used = DB::table('products')->whereIn('brand_id', $brandIds)->pluck('brand_id')->all();
            }
            $unused = array_values(array_diff($brandIds, array_map('intval', $used)));
            if ($unused !== []) {
                if (Schema::hasTable('url_rewrites')) {
                    DB::table('url_rewrites')->where('entity_type', 'brand')->whereIn('entity_id', $unused)->delete();
                }
                DB::table('brand_translations')->whereIn('brand_id', $unused)->delete();
                DB::table('brands')->whereIn('id', $unused)->delete();
                foreach ($unused as $id) {
                    Storage::disk('public')->deleteDirectory('catalog/brands/'.$id);
                }
            }
        }
    }

    protected function deleteDemoDiscounts(): void
    {
        if (! Schema::hasTable('discount_rules')) {
            return;
        }

        $ids = DB::table('discount_rules')
            ->whereIn('code', ['SAVE10', 'WELCOME15'])
            ->orWhereIn('name', [
                'Cart $5 off $50',
                'Fashion 15% off',
                'Audio 15% off',
                'Volt $8 off',
                'VIP extra 5%',
            ])
            ->pluck('id')
            ->all();
        if ($ids === []) {
            return;
        }
        if (Schema::hasTable('discount_rule_conditions')) {
            DB::table('discount_rule_conditions')->whereIn('discount_rule_id', $ids)->delete();
        }
        DB::table('discount_rules')->whereIn('id', $ids)->delete();
    }

    protected function restoreCoreCheckoutMethods(): void
    {
        PaymentMethod::query()->whereIn('code', ['bank_transfer', 'cod'])->update(['is_enabled' => false]);
        $check = PaymentMethod::query()->where('code', 'check')->first();
        if ($check && (
            ! Schema::hasTable('orders')
            || DB::table('orders')->where('payment_method_id', $check->id)->doesntExist()
        )) {
            $check->delete();
        }

        ShippingMethod::query()->where('code', 'flat')->update([
            'name' => 'Flat rate',
            'description' => 'Standard delivery',
            'price' => 0,
            'is_enabled' => false,
        ]);
        ShippingMethod::query()->where('code', 'free')->update([
            'name' => 'Free shipping',
            'description' => 'Free on qualifying orders',
            'price' => 0,
            'is_enabled' => false,
        ]);
    }

    protected function resetOrderSequence(): void
    {
        $max = Schema::hasTable('orders')
            ? (int) DB::table('orders')->max('id')
            : 0;
        Setting::setValue('order_sequence', $max > 0 ? $max : 10000);
    }

    protected function restoreEmptyStorefrontChrome(): void
    {
        CmsBlock::query()->whereIn('code', ['home_hero', 'header_promo'])->delete();

        $menu = Menu::query()->where('code', 'main')->first();
        if ($menu) {
            MenuItem::query()
                ->where('menu_id', $menu->id)
                ->whereIn('label', ['Catalog', 'Gaming', 'Audio', 'Office', 'Help', 'Shop', 'Electronics', 'Fashion', 'Deals'])
                ->delete();
        }

        $defaults = ThemeSettingsCatalog::defaults();
        Store::query()->orderBy('id')->each(function (Store $store) use ($defaults) {
            $store->update([
                'theme_settings' => ThemeSettingsCatalog::normalize($defaults),
            ]);
        });
    }

    /** @return list<string> */
    protected function demoCategorySlugs(): array
    {
        $slugs = array_map(
            static fn (array $row): string => $row['slug'],
            MerchantProductCatalog::categories()
        );

        return array_values(array_unique(array_merge($slugs, [
            'catalog', 'electronics', 'fashion', 'home-living', 'beauty', 'sports', 'grocery', 'toys',
            'automotive', 'office', 'sale', 'new-arrivals', 'clearance', 'gift-shop', 'phones', 'laptops',
            'audio', 'wearables', 'women', 'men', 'shoes', 'accessories', 'furniture', 'kitchen', 'bedding',
            'decor', 'skincare', 'makeup', 'hair', 'fragrance', 'fitness', 'outdoor', 'cycling', 'yoga',
            'pantry', 'beverages', 'snacks', 'organic', 'stem', 'action-figures', 'board-games', 'baby',
            'interior', 'car-care', 'tools', 'car-electronics', 'desks', 'chairs', 'stationery', 'storage',
        ])));
    }

    /** @return list<string> */
    protected function demoBrandSlugs(): array
    {
        $slugs = array_map(
            static fn (array $row): string => $row['slug'],
            MerchantProductCatalog::brands()
        );

        return array_values(array_unique(array_merge($slugs, [
            'wento', 'volt', 'northline', 'lumen', 'peakform', 'casa', 'bloom', 'harbor', 'paperfolk', 'aura',
        ])));
    }
}
