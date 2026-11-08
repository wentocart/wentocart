<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Services\ProductService;
use App\Domain\Catalog\Services\UrlRewriteService;
use App\Domain\Cms\DemoCmsContent;
use App\Domain\Cms\Models\CmsBlock;
use App\Domain\Cms\Models\CmsCategory;
use App\Domain\Cms\Models\CmsPage;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\ProductQuestion;
use App\Domain\Customer\Models\Review;
use App\Domain\Installer\DemoInstallMeter;
use App\Domain\Installer\InstallProgress;
use App\Domain\Order\Models\Order;
use App\Domain\Promo\Models\DiscountRule;
use App\Domain\Store\Models\Store;
use App\Domain\Tax\Models\TaxClass;
use App\Domain\Theme\ThemeSettingsCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MerchantStoreBuilder
{
    public const ASSET_ROOT = 'themes/classic/assets/merchant';

    public function __construct(
        protected ProductService $products,
        protected UrlRewriteService $urls,
    ) {}

    public function run(): void
    {
        $categoryCount = count(MerchantProductCatalog::categories());
        $brandCount = count(MerchantProductCatalog::brands());
        $productCount = count(MerchantProductCatalog::products());
        $meter = DemoInstallMeter::begin([
            'Categories' => $categoryCount,
            'Brands' => $brandCount,
            'Products' => $productCount,
        ]);
        $meter->show('Categories');

        $this->ensureBundledHomeAssets();
        $this->wipeStorefrontData();
        $this->publishAssetsToPublic();
        $categories = $this->seedCategories();
        $brands = $this->seedBrands();
        $this->seedProducts($categories, $brands);
        $this->seedDemoReviews();
        $this->applyThemeHome($categories);
        $this->applyCms();
    }

    protected function wipeStorefrontData(): void
    {
        $prefix = strtolower((string) DB::getTablePrefix());
        $present = [];
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            $rows = DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
        } elseif ($driver === 'pgsql') {
            $rows = DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
        } else {
            $rows = DB::select('SHOW TABLES');
        }
        foreach ($rows as $row) {
            $name = strtolower((string) array_values((array) $row)[0]);
            $present[$name] = true;
        }

        Schema::disableForeignKeyConstraints();

        foreach ([
            'order_status_history',
            'order_items',
            'orders',
            'reviews',
            'product_questions',
            'wishlist_items',
            'cart_items',
            'carts',
            'product_relations',
            'product_images',
            'product_attachments',
            'attachment_product',
            'attachments',
            'product_feature_value',
            'product_translations',
            'products',
            'category_product',
            'category_translations',
            'categories',
            'brand_translations',
            'brands',
            'discount_rule_conditions',
            'discount_rules',
            'customer_addresses',
            'customers',
        ] as $table) {
            if (isset($present[$prefix.strtolower($table)])) {
                DB::table($table)->truncate();
            }
        }

        Schema::enableForeignKeyConstraints();

        Storage::disk('public')->deleteDirectory('catalog');
    }

    /** Sync hero + side tiles from bundled banner art (install-safe, no external downloads). */
    public function ensureBundledHomeAssets(): void
    {
        $root = base_path(self::ASSET_ROOT);
        foreach (['banners', 'promo', 'products', 'categories', 'home', 'brands'] as $dir) {
            if (! is_dir($root.'/'.$dir)) {
                mkdir($root.'/'.$dir, 0755, true);
            }
        }

        foreach ([1, 2, 3] as $n) {
            $src = $root.'/banners/'.$n.'.png';
            if (is_file($src)) {
                copy($src, $root.'/home/hero-'.$n.'.png');
            }
        }

        foreach ([18 => 1, 19 => 2] as $banner => $side) {
            $src = $root.'/banners/'.$banner.'.png';
            if (is_file($src)) {
                copy($src, $root.'/home/side-'.$side.'.png');
            }
        }
    }

    protected function publishAssetsToPublic(): void
    {
        $source = base_path(self::ASSET_ROOT);
        $target = public_path(self::ASSET_ROOT);

        if (! is_dir($source)) {
            throw new \RuntimeException('Merchant assets missing at '.self::ASSET_ROOT.'. Copy your images there first.');
        }

        // ThemeInstaller already published Classic (including merchant). Skip the second 60MB copy.
        $products = $target.'/products';
        if (is_dir($products) && count(glob($products.'/*.{png,jpg,jpeg,webp}', GLOB_BRACE) ?: []) >= 20) {
            return;
        }

        File::deleteDirectory($target);
        File::copyDirectory($source, $target);
    }

    protected function assetUrl(string $relative): string
    {
        return '/'.self::ASSET_ROOT.'/'.ltrim($relative, '/');
    }

    /** @return array<string, Category> */
    protected function seedCategories(): array
    {
        $rows = MerchantProductCatalog::categories();
        $map = [];
        foreach ($rows as $row) {
            $category = Category::query()->create([
                'parent_id' => isset($row['parent']) ? ($map[$row['parent']]->id ?? null) : null,
                'is_enabled' => true,
                'position' => count($map) + 1,
            ]);
            $description = $row['description'] ?? ($row['name'].' — curated picks from our latest collection.');
            $category->translations()->create([
                'language_code' => 'en',
                'name' => $row['name'],
                'slug' => $row['slug'],
                'description' => $description,
                'meta_title' => $row['name'],
                'meta_description' => \Illuminate\Support\Str::limit($description, 155, ''),
            ]);
            $this->urls->sync('category', $category->id, 'en', $row['slug']);
            $map[$row['slug']] = $category;

            $coverFile = $row['image'] ?? null;
            $source = $coverFile ? base_path(self::ASSET_ROOT.'/'.$coverFile) : '';
            if ($source !== '' && ! is_file($source)) {
                $source = public_path(self::ASSET_ROOT.'/'.$coverFile);
            }
            if ($source !== '' && is_file($source)) {
                $ext = pathinfo($source, PATHINFO_EXTENSION) ?: 'png';
                $storagePath = 'catalog/categories/'.$category->id.'/banner.'.$ext;
                $this->linkOrCopy($source, $storagePath);
                // The same HD source powers both category-page artwork and compact
                // category tiles. Store every media role explicitly: storefront
                // presenters intentionally do not use a wide banner as a tile logo.
                $category->update([
                    'image_path' => $storagePath,
                    'thumbnail_path' => $storagePath,
                    'banner_path' => $storagePath,
                    'cover_path' => $storagePath,
                ]);
            }
            DemoInstallMeter::current()?->advance('Categories');
        }

        return $map;
    }

    /** @return array<string, Brand> */
    protected function seedBrands(): array
    {
        $rows = MerchantProductCatalog::brands();
        $map = [];
        foreach ($rows as $row) {
            $brand = Brand::query()->create(['is_enabled' => true, 'position' => count($map) + 1]);
            $tagline = $row['tagline'] ?? ($row['name'].' — trusted gear for everyday tech.');
            $brand->translations()->create([
                'language_code' => 'en',
                'name' => $row['name'],
                'slug' => $row['slug'],
                'tagline' => $tagline,
                'short_description' => $tagline,
                'description' => $tagline,
            ]);
            $this->urls->sync('brand', $brand->id, 'en', $row['slug']);

            $logoFile = $row['logo'] ?? ($row['slug'].'.svg');
            $source = base_path(self::ASSET_ROOT.'/brands/'.$logoFile);
            if (is_file($source)) {
                $ext = pathinfo($source, PATHINFO_EXTENSION) ?: 'svg';
                $storagePath = 'catalog/brands/'.$brand->id.'/logo.'.$ext;
                $this->linkOrCopy($source, $storagePath);
                $brand->update(['logo_path' => $storagePath]);
            }

            $map[$row['slug']] = $brand;
            DemoInstallMeter::current()?->advance('Brands');
        }

        return $map;
    }

    /**
     * @param  array<string, Category>  $categories
     * @param  array<string, Brand>  $brands
     */
    protected function seedProducts(array $categories, array $brands): void
    {
        $tax = TaxClass::query()->firstOrCreate(['name' => 'Standard']);
        $defs = MerchantProductCatalog::products();
        $total = count($defs);
        $progress = config('wento.install_progress');
        if (is_callable($progress)) {
            InstallProgress::note($progress, 'Adding '.$total.' sample products…');
        }

        $byId = [];
        foreach ($categories as $mapped) {
            $byId[$mapped->id] = $mapped;
        }

        foreach ($defs as $index => $def) {
            if (is_callable($progress)) {
                InstallProgress::say($progress, InstallProgress::bar($index + 1, $total, $def['name'] ?? ('product '.($index + 1))));
            }
            DemoInstallMeter::current()?->advance('Products');
            $sku = 'MERCH-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);
            $cat = $categories[$def['category']] ?? reset($categories);
            $brand = $brands[$def['brand']] ?? reset($brands);
            $price = (float) $def['price'];
            $compare = isset($def['compare']) ? (float) $def['compare'] : null;
            $onSale = $compare && $compare > $price;
            $flags = $def['flags'] ?? [];

            $categoryIds = [];
            $walk = $cat instanceof Category ? $cat : null;
            $guard = 0;
            while ($walk && $guard++ < 6) {
                $categoryIds[] = $walk->id;
                $walk = $walk->parent_id ? ($byId[$walk->parent_id] ?? null) : null;
            }
            $payload = [
                'type' => 'simple',
                'sku' => $sku,
                'price' => $compare ?: $price,
                'compare_price' => $compare,
                'sale_price_type' => 'fixed',
                'stock_qty' => 25 + ($index * 5) % 120,
                'tax_class_id' => $tax->id,
                'brand_id' => $brand->id,
                'default_category_id' => $cat->id,
                'visibility' => 'both',
                'is_enabled' => true,
                'is_new' => (bool) ($flags['new'] ?? ($index < 8)),
                'is_on_sale' => $onSale || (bool) ($flags['sale'] ?? false),
                'is_best_seller' => (bool) ($flags['best'] ?? ($index % 9 === 0)),
                'weight' => round(0.15 + ($index % 6) * 0.2, 2),
                'available_for_order' => true,
                'show_price' => true,
                'manage_stock' => true,
                'available_all_carriers' => true,
            ];
            if ($onSale) {
                $payload['sale_price'] = $price;
            }

            $product = $this->products->save([
                'product' => $payload,
                'translation' => [
                    'language_code' => 'en',
                    'name' => $def['name'],
                    'slug' => Str::slug($def['name']),
                    'short_description' => $def['short'],
                    'description' => '<p>'.$def['short'].'</p><p>Ships fast with a 30-day return window. Pair it with accessories from the same collection for a complete setup.</p>',
                    'meta_title' => $def['name'],
                    'meta_description' => $def['short'],
                ],
                'category_ids' => array_values(array_unique(array_filter($categoryIds))),
            ]);

            $this->attachProductImage($product, $def['file'], $def['name']);
        }

        if (is_callable($progress)) {
            $progress('');
        }
    }

    /**
     * Approved 4–5★ reviews so homepage / listing cards show filled stars in demo installs.
     */
    protected function seedDemoReviews(): void
    {
        $products = Product::query()->orderBy('id')->get();
        if ($products->isEmpty()) {
            return;
        }

        $customers = [];
        $names = [
            ['Ava', 'Nguyen'], ['Noah', 'Patel'], ['Mia', 'Brooks'], ['Liam', 'Chen'],
            ['Zoe', 'Martin'], ['Eli', 'Garcia'], ['Nora', 'Singh'], ['Owen', 'Kim'],
        ];
        $password = Hash::make('password');
        foreach ($names as $i => [$first, $last]) {
            $customers[] = Customer::query()->updateOrCreate(
                ['email' => 'buyer'.($i + 1).'@demo.wentocart.test'],
                [
                    'name' => $first.' '.$last,
                    'first_name' => $first,
                    'last_name' => $last,
                    'password' => $password,
                    'is_enabled' => true,
                    'newsletter' => false,
                ]
            );
        }

        $reviewTotal = 0;
        foreach ($products as $index => $product) {
            $reviewTotal += 2 + ($index % 3);
        }
        DemoInstallMeter::current()?->expect('Reviews', $reviewTotal);

        $titles = ['Exactly as described', 'Daily driver now', 'Gifted this', 'Solid build', 'Would buy again'];
        $bodies = [
            'Arrived on time and matches the listing. Using it every day.',
            'Better than the last version I owned. Packaging was careful.',
            'Bought this for a friend and they asked for the link.',
            'Finish feels premium. No complaints after two weeks.',
            'Checkout was simple and the tracking email showed up quickly.',
        ];

        foreach ($products as $index => $product) {
            $reviewCount = 2 + ($index % 3);
            for ($r = 0; $r < $reviewCount; $r++) {
                $customer = $customers[($index + $r) % count($customers)];
                Review::query()->updateOrCreate(
                    [
                        'product_id' => $product->id,
                        'customer_id' => $customer->id,
                    ],
                    [
                        'rating' => 4 + (($index + $r) % 2),
                        'title' => $titles[($index + $r) % count($titles)],
                        'body' => $bodies[($index + $r) % count($bodies)],
                        'status' => 'approved',
                        'is_approved' => true,
                        'is_verified_purchase' => ($index + $r) % 3 === 0,
                    ]
                );
                DemoInstallMeter::current()?->advance('Reviews');
            }
        }
    }

    protected function linkOrCopy(string $source, string $storagePath): void
    {
        $dest = Storage::disk('public')->path($storagePath);
        File::ensureDirectoryExists(dirname($dest));
        if (is_file($dest)) {
            return;
        }
        if (! @link($source, $dest)) {
            File::copy($source, $dest);
        }
    }

    protected function attachProductImage(Product $product, string $file, string $alt): void
    {
        $source = public_path(self::ASSET_ROOT.'/products/'.$file);
        if (! is_file($source)) {
            return;
        }

        $path = 'catalog/products/'.$product->id.'/cover.png';
        $dest = Storage::disk('public')->path($path);
        File::ensureDirectoryExists(dirname($dest));
        if (! is_file($dest)) {
            // Hardlink when possible (instant); copy only as fallback.
            if (! @link($source, $dest)) {
                File::copy($source, $dest);
            }
        }
        $product->update(['cover_path' => $path]);
        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => $path,
            'alt' => $alt,
            'position' => 1,
        ]);
    }

    /**
     * @param  array<string, Category>|null  $categories
     */
    protected function applyThemeHome(?array $categories = null): void
    {
        if (! Schema::hasColumn('stores', 'theme_settings')) {
            return;
        }

        $featuredSlugs = [
            'electronics',
            'gaming',
            'audio',
            'wearables',
            'smart-home',
            'sports-outdoors',
            'mobile-tablets',
            'office-tech',
            'computers',
            'headphones',
            'speakers',
            'kitchen-appliances',
            'cycling',
            'personal-care',
            'lifestyle',
            'gaming-accessories',
        ];
        $featuredIds = [];
        foreach ($featuredSlugs as $slug) {
            if ($categories && isset($categories[$slug])) {
                $featuredIds[] = (int) $categories[$slug]->id;
            }
        }

        $headerSlugs = [
            'electronics',
            'gaming',
            'audio',
            'wearables',
            'smart-home',
            'sports-outdoors',
            'personal-care',
            'lifestyle',
        ];
        $headerCategoryIds = [];
        foreach ($headerSlugs as $slug) {
            if ($categories && isset($categories[$slug])) {
                $headerCategoryIds[] = (int) $categories[$slug]->id;
            }
        }

        $shelfSlugs = ['gaming', 'audio', 'mobile-tablets', 'smart-home'];
        $shelfIds = [];
        foreach ($shelfSlugs as $slug) {
            if ($categories && isset($categories[$slug])) {
                $shelfIds[] = (int) $categories[$slug]->id;
            }
        }

        $defaults = ThemeSettingsCatalog::defaults();
        $overlay = [
            'announcement' => 'FREE SHIPPING FOR ALL ORDERS OF $75',
            'announcement_url' => '/catalog',
            'phone' => '(+1) 1234-567-890',
            'top_bar' => [
                'enabled' => true,
                'background' => '#3361cf',
                'text_color' => '#f8fafc',
                'show_currency' => true,
                'show_language' => true,
                'announcement' => 'FREE SHIPPING FOR ALL ORDERS OF $75',
                'announcement_url' => '/catalog',
                'links' => [
                    ['label' => 'Gift Cards', 'url' => '/account/vouchers', 'open_in_new_tab' => false],
                    ['label' => 'About Us', 'url' => '/about', 'open_in_new_tab' => false],
                    ['label' => 'Help', 'url' => '/help', 'open_in_new_tab' => false],
                ],
                'contact' => [
                    'phone' => '(+1) 1234-567-890',
                    'label' => 'Contact us',
                    'url' => '/contact',
                    'email' => '',
                ],
            ],
            'home' => [
                'header' => [
                    'nav_source' => 'categories',
                    'show_search' => true,
                    'show_compare' => true,
                    'show_wishlist' => true,
                    'show_login' => true,
                    'show_cart' => true,
                    'cart_style' => 'pill',
                    'search_placeholder' => 'Search for products',
                    'category_ids' => $headerCategoryIds,
                ],
                'shop_categories' => [
                    'title' => 'Shop by Featured Categories',
                    'limit' => 16,
                    'category_ids' => $featuredIds,
                ],
                'category_shelves' => [
                    'category_ids' => $shelfIds,
                    'show_view_all' => true,
                    'banner_image_url' => '/themes/classic/assets/merchant/banners/15.png',
                ],
                // Hero layout/columns come from ThemeSettingsCatalog defaults (layout 1 +
                // per-layout banner sets). Do not overlay legacy slides/sides here — that
                // used to set layout=3 while keeping layout-1 columns and wipe Col-3 presets.
                'promos' => [
                    ['kicker' => 'Laptops', 'title' => "Power for\ncreators &\nwork", 'subtitle' => 'From $699', 'cta' => 'Shop tablets', 'url' => '/mobile-tablets', 'bg' => '#EEF1F6', 'image_url' => $this->assetUrl('banners/6.png')],
                    ['kicker' => 'Carry', 'title' => "Leather loop\nkeychains", 'subtitle' => 'From $19', 'cta' => 'Shop accessories', 'url' => '/gaming-accessories', 'bg' => '#EEF1F6', 'image_url' => $this->assetUrl('banners/7.png')],
                    ['kicker' => 'Headwear', 'title' => "Classic caps\nin three\ncolors", 'subtitle' => 'From $24', 'cta' => 'Shop lifestyle', 'url' => '/lifestyle', 'bg' => '#EEF1F6', 'image_url' => $this->assetUrl('banners/8.png')],
                ],
                'promos_mid' => [
                    ['kicker' => 'Style', 'title' => "Genuine leather\nbelt", 'subtitle' => 'From $34', 'cta' => 'Shop lifestyle', 'url' => '/lifestyle', 'bg' => '#F4F6FA', 'image_url' => $this->assetUrl('banners/9.png')],
                    ['kicker' => 'Lighting', 'title' => "Energy-saving\nLED bulbs", 'subtitle' => 'From $12', 'cta' => 'Shop smart home', 'url' => '/smart-home', 'bg' => '#F4F6FA', 'image_url' => $this->assetUrl('banners/10.png')],
                    ['kicker' => 'Travel', 'title' => "Lightweight\ncarry-on\nluggage", 'subtitle' => 'From $89', 'cta' => 'Shop lifestyle', 'url' => '/lifestyle', 'bg' => '#F4F6FA', 'image_url' => $this->assetUrl('banners/11.png')],
                ],
                'promos_bottom' => [
                    ['kicker' => 'Wi-Fi', 'title' => "Fast home\nmesh router", 'subtitle' => 'Stay connected', 'cta' => 'Shop smart home', 'url' => '/smart-home', 'bg' => '#E8F0FE', 'image_url' => $this->assetUrl('banners/12.png')],
                    ['kicker' => 'Office', 'title' => "Ergonomic\ndesk chair", 'subtitle' => 'Work in comfort', 'cta' => 'Shop office', 'url' => '/office-tech', 'bg' => '#E8F0FE', 'image_url' => $this->assetUrl('banners/13.png')],
                    ['kicker' => 'Audio', 'title' => "Portable\nBluetooth\nspeakers", 'subtitle' => 'From $49', 'cta' => 'Shop audio', 'url' => '/audio', 'bg' => '#E8F0FE', 'image_url' => $this->assetUrl('banners/14.png')],
                ],
            ],
        ];

        Store::query()->orderBy('id')->each(function (Store $store) use ($defaults, $overlay) {
            $store->update([
                'theme_settings' => ThemeSettingsCatalog::normalize(ThemeSettingsCatalog::merge($defaults, $overlay)),
            ]);
        });
    }

    protected function applyCms(): void
    {
        CmsBlock::query()->updateOrCreate(
            ['code' => 'home_hero'],
            [
                'title' => 'Tech for work, play, and home',
                'location' => 'home',
                'content' => '<p>Discover gaming mice, studio headphones, smart watches, printers, and connected home gear — all with clear pricing and fast checkout.</p>',
                'is_enabled' => true,
                'position' => 1,
            ]
        );
        CmsBlock::query()->updateOrCreate(
            ['code' => 'header_promo'],
            [
                'title' => 'Header promo',
                'location' => 'header',
                'content' => 'Free shipping over $75 · New electronics weekly',
                'is_enabled' => true,
                'position' => 1,
            ]
        );

        $infoCat = CmsCategory::query()->firstOrCreate(
            ['slug' => 'info'],
            ['name' => 'Info', 'is_enabled' => true, 'position' => 1]
        );
        $legalCat = CmsCategory::query()->firstOrCreate(
            ['slug' => 'legal'],
            ['name' => 'Legal', 'is_enabled' => true, 'position' => 2]
        );

        $categoryId = [
            'info' => $infoCat->id,
            'legal' => $legalCat->id,
        ];

        foreach (DemoCmsContent::pages() as $row) {
            $this->upsertCmsPage(
                $row['slug'],
                $row['title'],
                $categoryId[$row['category']] ?? $infoCat->id,
                $row['template'],
                $row['short'],
                $row['body']
            );
        }
    }

    protected function upsertCmsPage(string $slug, string $title, int $categoryId, string $template, string $short, string $body): void
    {
        $page = CmsPage::query()
            ->whereHas('translations', fn ($t) => $t->where('slug', $slug)->where('language_code', 'en'))
            ->first();

        if (! $page) {
            $page = CmsPage::query()->create([
                'is_enabled' => true,
                'status' => 'published',
                'is_visible' => true,
                'template' => $template,
                'cms_category_id' => $categoryId,
                'robots' => 'index,follow',
            ]);
        } else {
            $page->fill([
                'template' => $template,
                'cms_category_id' => $categoryId,
                'is_enabled' => true,
                'status' => 'published',
                'is_visible' => true,
            ])->save();
        }

        $page->translations()->updateOrCreate(
            ['language_code' => 'en'],
            [
                'title' => $title,
                'slug' => $slug,
                'short_description' => $short,
                'body' => $body,
                'meta_title' => $title,
                'meta_description' => $short,
            ]
        );
        $this->urls->sync('cms', $page->id, 'en', $slug);
    }
}
