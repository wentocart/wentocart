<?php

namespace App\Domain\Installer;

use App\Domain\Catalog\DemoMediaPack;
use App\Domain\Catalog\MerchantStoreBuilder;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Feature;
use App\Domain\Catalog\Models\FeatureValue;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Catalog\Services\ProductService;
use App\Domain\Catalog\Services\UrlRewriteService;
use App\Domain\Cms\DemoCmsContent;
use App\Domain\Cms\Models\CmsBlock;
use App\Domain\Cms\Models\CmsCategory;
use App\Domain\Cms\Models\CmsPage;
use App\Domain\Cms\Models\Menu;
use App\Domain\Cms\Models\MenuItem;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerGroup;
use App\Domain\Customer\Models\ProductQuestion;
use App\Domain\Customer\Models\Review;
use App\Domain\Localization\Models\Currency;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Services\OrderNumberService;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Promo\Models\DiscountRule;
use App\Domain\Promo\Models\DiscountRuleCondition;
use App\Domain\Settings\Models\Setting;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Domain\Store\StoreBootstrap;
use App\Domain\Store\StoreContext;
use App\Domain\Tax\Models\TaxClass;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Sample catalog used by the installer and Advanced → Install demo data.
 * Lives in App\Domain so merchant tidy can delete database/seeders.
 */
class DemoCatalogInstaller
{
    public const TARGET_PRODUCTS = 48;

    public const TARGET_CUSTOMERS = 20;

    public const TARGET_ORDERS = 20;

    public function run(): void
    {
        // Dump already has required columns — skip exception-driven ALTER probes.
        StoreBootstrap::ensure();
        $this->currencyRates();

        $this->payments();
        $this->shipping();

        if ($this->usesMerchantPack()) {
            if (! app()->runningUnitTests()) {
                app(MerchantStoreBuilder::class)->run();
                $this->demoStep('Adding sample discounts…', fn () => $this->discounts());
                $this->demoStep('Adding sample customers…', fn () => $this->customers());
                $this->demoStep('Adding sample orders…', fn () => $this->orders());
                $this->demoStep('Adding sample reviews…', fn () => $this->reviews());
                $this->demoStep('Adding sample questions…', fn () => $this->questions());
            }
            $this->merchantMenu();

            return;
        }

        // Merchant photos are required for public installs — never download third-party stock.
        Log::warning('themes/classic/assets/merchant is missing; skipping catalog demo products.');
        $this->cms();
        $this->menu();

        if (app()->runningUnitTests()) {
            return;
        }

        $this->discounts();
        $this->customers();
    }

    /** Refresh storefront media from the bundled merchant pack (no third-party downloads). */
    public function refreshStorefrontMedia(): void
    {
        if ($this->usesMerchantPack()) {
            app(MerchantStoreBuilder::class)->run();

            return;
        }

        Log::warning('Merchant pack missing — skip media refresh. Add themes/classic/assets/merchant.');
    }

    /**
     * Keep each demo SKU on its leaf + parent + catalog only (fixes stray category links).
     *
     * @param  list<array<string, mixed>>  $defs
     * @param  array<string, Category>  $categories
     */
    protected function repairProductCategories(array $defs, array $categories): void
    {
        $catalogRoot = $categories['catalog'] ?? null;
        foreach ($defs as $def) {
            $product = Product::query()->where('sku', $def['sku'])->first();
            $cat = $categories[$def['category'] ?? ''] ?? null;
            if (! $product || ! $cat) {
                continue;
            }
            $parent = $cat->parent_id
                ? ($categories[$this->parentSlug($cat)] ?? null)
                : null;
            $ids = array_values(array_unique(array_filter([
                $cat->id,
                $parent?->id,
                $catalogRoot?->id,
            ])));
            $product->categories()->sync($ids);
            $product->update(['default_category_id' => $cat->id]);
        }
    }

    protected function currencyRates(): void
    {
        $rates = [
            'USD' => 1, 'INR' => 83, 'EUR' => 0.92, 'GBP' => 0.79, 'AED' => 3.67,
            'AUD' => 1.52, 'CAD' => 1.36, 'SGD' => 1.35, 'JPY' => 155, 'NZD' => 1.64,
        ];
        foreach ($rates as $code => $rate) {
            Currency::query()->where('code', $code)->update(['exchange_rate' => $rate]);
        }
        Currency::query()->where('code', 'JPY')->update(['decimal_places' => 0]);
    }

    protected function payments(): void
    {
        $rows = [
            ['code' => 'bank_transfer', 'name' => 'Bank transfer', 'description' => 'Pay to account 000-111 and use your order number as the reference.', 'position' => 1],
            ['code' => 'cod', 'name' => 'Cash on delivery', 'description' => 'Pay the courier when the parcel arrives.', 'position' => 2],
            ['code' => 'check', 'name' => 'Check', 'description' => 'Mail a check payable to WentoCart. We ship after the check clears.', 'position' => 3],
        ];

        foreach ($rows as $row) {
            // Code is unique for the whole install. Multishop hides rows that are
            // not linked to the current shop, so a plain lookup would insert a duplicate.
            $method = PaymentMethod::query()->withoutGlobalScope('shop')->updateOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'description' => $row['description'],
                    'driver' => 'offline',
                    'is_enabled' => true,
                    'position' => $row['position'],
                    'config' => ['instructions' => $row['description']],
                    'rules' => PaymentMethod::emptyRules(),
                ]
            );
            $method->attachToCurrentShop();
        }
    }

    protected function shipping(): void
    {
        $rows = [
            [
                'code' => 'flat',
                'name' => 'Standard delivery',
                'description' => '3 to 5 business days.',
                'type' => 'flat',
                'price' => 5,
                'position' => 1,
                'conditions' => ShippingMethod::emptyConditions(),
            ],
            [
                'code' => 'free',
                'name' => 'Free shipping',
                'description' => 'Free on orders of $75 or more.',
                'type' => 'free',
                'price' => 0,
                'position' => 2,
                'conditions' => array_merge(ShippingMethod::emptyConditions(), ['min_order' => 75]),
            ],
        ];

        foreach ($rows as $row) {
            $method = ShippingMethod::query()->withoutGlobalScope('shop')->updateOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'description' => $row['description'],
                    'type' => $row['type'],
                    'driver' => 'offline',
                    'price' => $row['price'],
                    'is_enabled' => true,
                    'position' => $row['position'],
                    'conditions' => $row['conditions'],
                    'rate_config' => ['tiers' => []],
                ]
            );
            $method->attachToCurrentShop();
        }
    }

    protected function cms(): void
    {
        $helpCat = CmsCategory::query()->firstOrCreate(
            ['slug' => 'info'],
            ['name' => 'Info', 'is_enabled' => true, 'position' => 1]
        );
        $legalCat = CmsCategory::query()->firstOrCreate(
            ['slug' => 'legal'],
            ['name' => 'Legal', 'is_enabled' => true, 'position' => 2]
        );

        $categoryId = [
            'info' => $helpCat->id,
            'legal' => $legalCat->id,
        ];

        $urls = app(UrlRewriteService::class);
        foreach (DemoCmsContent::pages() as $row) {
            $existing = CmsPage::query()
                ->whereHas('translations', fn ($t) => $t->where('slug', $row['slug'])->where('language_code', 'en'))
                ->first();
            $page = $existing ?: CmsPage::query()->create([
                'is_enabled' => true,
                'status' => 'published',
                'is_visible' => true,
                'template' => $row['template'],
                'cms_category_id' => $categoryId[$row['category']] ?? $helpCat->id,
                'robots' => 'index,follow',
            ]);
            if ($existing) {
                $page->fill([
                    'template' => $row['template'],
                    'cms_category_id' => $categoryId[$row['category']] ?? $page->cms_category_id,
                    'is_enabled' => true,
                    'status' => 'published',
                    'is_visible' => true,
                ])->save();
            }
            $page->translations()->updateOrCreate(
                ['language_code' => 'en'],
                [
                    'title' => $row['title'],
                    'slug' => $row['slug'],
                    'short_description' => $row['short'],
                    'body' => $row['body'],
                    'meta_title' => $row['title'],
                    'meta_description' => $row['short'],
                ]
            );
            $urls->sync('cms', $page->id, 'en', $row['slug']);
        }

        CmsBlock::query()->updateOrCreate(
            ['code' => 'home_hero'],
            ['title' => 'Smart Electronics Designed Modern Lifestyle', 'location' => 'home', 'content' => '<p>Electronics is the study of electricity the flow of electrons and how to use that to build things like computers it uses circuits that are made with parts describe anything using electronic equipment from electronic games and gadgets to electronic banking and journalism the field of electronics is a branch of physics and electrical engineering concerned with manipulating electrons to perform tasks called components and connecting wires to do useful things the science behind electronics comes from the study of physics and gets applied.</p>', 'is_enabled' => true, 'position' => 1]
        );
        CmsBlock::query()->updateOrCreate(
            ['code' => 'header_promo'],
            ['title' => 'Header promo', 'location' => 'header', 'content' => 'Free shipping over $75 — use SAVE10 for extra 10% off', 'is_enabled' => true, 'position' => 1]
        );
    }

    protected function discounts(): void
    {
        $fashion = Category::query()
            ->whereHas('translations', fn ($t) => $t->where('slug', 'fashion')->where('language_code', 'en'))
            ->first();
        $audio = Category::query()
            ->whereHas('translations', fn ($t) => $t->where('slug', 'audio')->where('language_code', 'en'))
            ->first();
        $brand = Brand::query()
            ->whereHas('translations', fn ($t) => $t->where('slug', 'volt')->where('language_code', 'en'))
            ->first();
        $vip = CustomerGroup::query()->where('code', 'vip')->first();
        DemoInstallMeter::current()?->expect('Discounts', 3 + ($fashion ? 1 : 0) + ($audio ? 1 : 0) + ($brand ? 1 : 0) + ($vip ? 1 : 0));

        $cart = $this->rule('Cart $5 off $50', 'cart', 'amount', 5, ['min_cart' => 50, 'priority' => 30]);
        $this->condition($cart, 'catalog', []);
        DemoInstallMeter::current()?->advance('Discounts');

        $coupon = $this->rule('SAVE10 coupon', 'coupon', 'percent', 10, ['code' => 'SAVE10', 'priority' => 10, 'max_discount' => 40]);
        $this->condition($coupon, 'catalog', []);
        DemoInstallMeter::current()?->advance('Discounts');

        $welcome = $this->rule('WELCOME15 coupon', 'coupon', 'amount', 15, ['code' => 'WELCOME15', 'priority' => 11, 'min_cart' => 80]);
        $this->condition($welcome, 'catalog', []);
        DemoInstallMeter::current()?->advance('Discounts');

        if ($fashion) {
            $rule = $this->rule('Fashion 15% off', 'catalog', 'percent', 15, ['priority' => 15]);
            $this->condition($rule, 'category', [(string) $fashion->id]);
            DemoInstallMeter::current()?->advance('Discounts');
        }

        if ($audio) {
            $rule = $this->rule('Audio 15% off', 'catalog', 'percent', 15, ['priority' => 16]);
            $this->condition($rule, 'category', [(string) $audio->id]);
            DemoInstallMeter::current()?->advance('Discounts');
        }

        if ($brand) {
            $rule = $this->rule('Volt $8 off', 'catalog', 'amount', 8, ['priority' => 25]);
            $this->condition($rule, 'brand', [(string) $brand->id]);
            DemoInstallMeter::current()?->advance('Discounts');
        }

        if ($vip) {
            $rule = $this->rule('VIP extra 5%', 'cart', 'percent', 5, ['priority' => 40]);
            $this->condition($rule, 'customer_group', [(string) $vip->id]);
            DemoInstallMeter::current()?->advance('Discounts');
        }
    }

    protected function rule(string $name, string $applyAs, string $action, float $value, array $extra = []): DiscountRule
    {
        $payload = array_merge([
            'apply_as' => $applyAs,
            'action' => $action,
            'value' => $value,
            'is_enabled' => true,
            'priority' => 100,
        ], $extra);

        if (! empty($payload['code'])) {
            return DiscountRule::query()->updateOrCreate(['code' => $payload['code']], array_merge($payload, ['name' => $name]));
        }

        return DiscountRule::query()->updateOrCreate(['name' => $name], $payload);
    }

    protected function condition(DiscountRule $rule, string $type, array $ids): void
    {
        DiscountRuleCondition::query()->updateOrCreate(
            ['discount_rule_id' => $rule->id, 'type' => $type],
            ['value_ids' => $ids]
        );
    }

    protected function menu(): void
    {
        $menu = Menu::query()->updateOrCreate(['code' => 'main'], ['name' => 'Main menu']);
        $items = [
            ['label' => 'Home', 'url' => '/', 'position' => 1],
            ['label' => 'Shop', 'url' => '/catalog', 'position' => 2],
            ['label' => 'Electronics', 'url' => '/electronics', 'position' => 3],
            ['label' => 'Fashion', 'url' => '/fashion', 'position' => 4],
            ['label' => 'Deals', 'url' => '/search?q=sale', 'position' => 5],
            ['label' => 'Help', 'url' => '/help', 'position' => 6],
        ];
        foreach ($items as $item) {
            MenuItem::query()->updateOrCreate(
                ['menu_id' => $menu->id, 'label' => $item['label']],
                ['url' => $item['url'], 'position' => $item['position']]
            );
        }
    }

    protected function usesMerchantPack(): bool
    {
        $root = base_path(MerchantStoreBuilder::ASSET_ROOT.'/products');

        return is_dir($root) && count(glob($root.'/*.png') ?: []) >= 30;
    }

    protected function merchantMenu(): void
    {
        $menu = Menu::query()->updateOrCreate(['code' => 'main'], ['name' => 'Main menu']);
        $items = [
            ['label' => 'Home', 'url' => '/', 'position' => 1],
            ['label' => 'Catalog', 'url' => '/catalog', 'position' => 2],
            ['label' => 'Gaming', 'url' => '/gaming-accessories', 'position' => 3],
            ['label' => 'Audio', 'url' => '/audio', 'position' => 4],
            ['label' => 'Office', 'url' => '/office-tech', 'position' => 5],
            ['label' => 'Help', 'url' => '/help', 'position' => 6],
        ];
        foreach ($items as $item) {
            MenuItem::query()->updateOrCreate(
                ['menu_id' => $menu->id, 'label' => $item['label']],
                ['url' => $item['url'], 'position' => $item['position']]
            );
        }
    }

    protected function catalog(): void
    {
        $urls = app(UrlRewriteService::class);
        $tax = TaxClass::query()->firstOrCreate(['name' => 'Standard']);
        $supplier = Supplier::query()->first() ?: Supplier::query()->create([
            'email' => 'buying@wento.test',
            'phone' => '+1 555 0100',
            'is_enabled' => true,
        ]);
        if (! $supplier->translations()->exists()) {
            $supplier->translations()->create([
                'language_code' => 'en',
                'name' => 'Wento Supply Co',
                'slug' => 'wento-supply',
                'description' => 'Default demo supplier.',
            ]);
            $urls->sync('supplier', $supplier->id, 'en', 'wento-supply', 'supplier');
        }

        $brands = $this->brands($urls);
        $categories = $this->categories($urls);
        $features = $this->features();

        $service = app(ProductService::class);
        $defs = array_slice($this->productDefs(), 0, self::TARGET_PRODUCTS);
        $created = [];

        foreach ($defs as $index => $def) {
            $sku = $def['sku'];
            if (Product::query()->where('sku', $sku)->exists()) {
                $created[] = Product::query()->where('sku', $sku)->first();

                continue;
            }

            $cat = $categories[$def['category']] ?? $categories['catalog'];
            $parent = $cat->parent_id ? ($categories[$this->parentSlug($cat)] ?? $cat) : $cat;
            $brand = $brands[$def['brand']] ?? $brands['wento'];
            $catalogRoot = $categories['catalog'];
            $price = $def['price'];
            $compare = $def['compare'] ?? null;
            $onSale = $compare && $compare > $price;
            $productPayload = [
                'type' => 'simple',
                'sku' => $sku,
                'price' => $compare ?: $price,
                'compare_price' => $compare,
                'sale_price_type' => 'fixed',
                'stock_qty' => 20 + ($index * 3) % 180,
                'tax_class_id' => $tax->id,
                'brand_id' => $brand->id,
                'supplier_id' => $supplier->id,
                'default_category_id' => $cat->id,
                'visibility' => 'both',
                'is_enabled' => true,
                'is_new' => $index < 12,
                'is_on_sale' => $onSale,
                'is_best_seller' => $index % 7 === 0,
                'weight' => round(0.2 + ($index % 9) * 0.15, 2),
                'available_for_order' => true,
                'show_price' => true,
                'manage_stock' => true,
                'available_all_carriers' => true,
            ];
            if ($onSale) {
                $productPayload['sale_price'] = $price;
            }

            $product = $service->save([
                'product' => $productPayload,
                'translation' => [
                    'language_code' => 'en',
                    'name' => $def['name'],
                    'slug' => Str::slug($def['name']),
                    'short_description' => $def['short'],
                    'description' => '<p>'.$def['short'].'</p><p>Demo product for the WentoCart Classic storefront. Add to cart, check out, and review from the customer account.</p>',
                    'meta_title' => $def['name'],
                    'meta_description' => $def['short'],
                ],
                'category_ids' => array_values(array_unique([$cat->id, $parent->id, $catalogRoot->id])),
                'supplier_ids' => [$supplier->id],
                'feature_value_ids' => $this->pickFeatures($features, $index),
            ]);
            $created[] = $product->fresh();
        }

        $this->syncDemoMedia($defs, $brands, $categories);

        $ids = collect($created)->filter()->pluck('id')->values();
        foreach ($created as $i => $product) {
            if (! $product) {
                continue;
            }
            $related = $ids->slice(($i + 1) % max(1, $ids->count()), 3)->all();
            $cross = $ids->slice(($i + 4) % max(1, $ids->count()), 2)->all();
            DB::table('product_relations')->where('product_id', $product->id)->delete();
            foreach ($related as $rid) {
                DB::table('product_relations')->insert(['product_id' => $product->id, 'related_product_id' => $rid, 'type' => 'related']);
            }
            foreach ($cross as $rid) {
                DB::table('product_relations')->insert(['product_id' => $product->id, 'related_product_id' => $rid, 'type' => 'crosssell']);
            }
        }
    }

    /**
     * Attach category-aligned cover photos + category/brand images for judging the theme.
     *
     * @param  list<array<string, mixed>>  $defs
     * @param  array<string, Brand>  $brands
     * @param  array<string, Category>  $categories
     */
    protected function syncDemoMedia(array $defs, array $brands, array $categories): void
    {
        foreach ($defs as $index => $def) {
            $product = Product::query()->where('sku', $def['sku'])->first();
            if (! $product) {
                continue;
            }
            $path = $this->writeAlignedProductCover($product, $def, $index);
            InstallSchemaGuard::write($product, ['cover_path' => $path]);
            $image = ProductImage::query()->where('product_id', $product->id)->orderBy('position')->first();
            if ($image) {
                $image->update(['path' => $path, 'alt' => $def['name']]);
            } else {
                ProductImage::query()->create([
                    'product_id' => $product->id,
                    'path' => $path,
                    'alt' => $def['name'],
                    'position' => 1,
                ]);
            }

            $hoverPath = $this->writeProductAlternate($product, $def, $index);
            if ($hoverPath) {
                ProductImage::query()->updateOrCreate(
                    ['product_id' => $product->id, 'position' => 2],
                    ['path' => $hoverPath, 'alt' => $def['name'].' alternate view']
                );
            }
        }

        $this->ensureCoreDemoProductCover();

        foreach ($categories as $slug => $category) {
            $name = $category->translation('en')?->name ?: $slug;
            $path = $this->writeAlignedCategoryCover($category, $slug, $name);
            InstallSchemaGuard::write($category, [
                'image_path' => $path,
                'thumbnail_path' => $path,
                'banner_path' => $path,
                'cover_path' => $path,
            ]);
        }

        foreach ($brands as $slug => $brand) {
            InstallSchemaGuard::write($brand, ['logo_path' => $this->brandLogoUrl($slug)]);
        }
    }

    protected function ensureCoreDemoProductCover(): void
    {
        $product = Product::query()->where('sku', 'DEMO-001')->first();
        if (! $product) {
            return;
        }

        $def = [
            'sku' => 'DEMO-001',
            'name' => 'Demo Cotton Tee',
            'category' => 'fashion',
            'accent' => '#1C523B',
        ];
        $path = $this->writeAlignedProductCover($product, $def, 12);
        InstallSchemaGuard::write($product, ['cover_path' => $path]);
        ProductImage::query()->updateOrCreate(
            ['product_id' => $product->id, 'position' => 1],
            ['path' => $path, 'alt' => 'Demo Cotton Tee']
        );
    }

    protected function writeAlignedProductCover(Product $product, array $def, int $index): string
    {
        $sku = (string) ($def['sku'] ?? '');
        $category = (string) ($def['category'] ?? 'catalog');
        $directory = 'catalog/products/'.$product->id;
        $path = $directory.'/cover.jpg';

        // 1) Offline HD pack (shipped under themes/classic/assets/demo)
        if ($sku !== '' && $this->copyLocalDemoFile(DemoMediaPack::productFile($sku), $path)) {
            Storage::disk('public')->delete([
                $directory.'/cover.webp',
                $directory.'/cover.svg',
            ]);

            return $path;
        }

        // 2) Optional live download (dev machines / wento:demo-media)
        if (filter_var(env('WENTO_DEMO_MEDIA_REMOTE', false), FILTER_VALIDATE_BOOL)) {
            $url = $this->realPhotoUrl($category, $sku !== '' ? $sku : 'product-'.$product->id);
            if ($this->downloadRealImage($url, $path)) {
                Storage::disk('public')->delete([
                    $directory.'/cover.webp',
                    $directory.'/cover.svg',
                ]);

                return $path;
            }
        }

        // 3) Classic bundled webp rotation
        return $this->writeBundledProductCover($product, $index)
            ?? $this->writeCover(
                $product,
                $def['accent'] ?? '#c4b5fd',
                $def['name'] ?? 'Product',
                $category,
                $sku
            );
    }

    protected function writeAlignedCategoryCover(Category $category, string $slug, string $name): string
    {
        $directory = 'catalog/categories/'.$category->id;
        $path = $directory.'/banner.jpg';

        if ($this->copyLocalDemoFile(DemoMediaPack::categoryFile($slug), $path)
            || $this->copyLocalDemoFile(DemoMediaPack::categoryFile('catalog'), $path)) {
            Storage::disk('public')->delete([
                $directory.'/cover.jpg',
                $directory.'/cover.webp',
                $directory.'/cover.svg',
            ]);

            return $path;
        }

        if (filter_var(env('WENTO_DEMO_MEDIA_REMOTE', false), FILTER_VALIDATE_BOOL)) {
            $id = DemoMediaPack::unsplashId('cat-'.$slug)
                ?: DemoMediaPack::unsplashId('cat-catalog')
                ?: '1441986300917-64674bd600d8';
            $url = DemoMediaPack::unsplashDownloadUrl($id, 1400, 700);
            if ($this->downloadRealImage($url, $path)) {
                Storage::disk('public')->delete([
                    $directory.'/cover.jpg',
                    $directory.'/cover.webp',
                    $directory.'/cover.svg',
                ]);

                return $path;
            }
        }

        static $categoryIndex = 0;

        return $this->writeBundledCategoryCover($category, $categoryIndex++)
            ?? $this->writeCategoryCover($category, $slug, $name);
    }

    protected function copyLocalDemoFile(string $source, string $destPath): bool
    {
        if (! is_file($source) || filesize($source) < 2000) {
            return false;
        }
        $bytes = @file_get_contents($source);
        if ($bytes === false || strlen($bytes) < 2000) {
            return false;
        }
        Storage::disk('public')->put($destPath, $bytes);

        return true;
    }

    protected function writeBundledProductCover(Product $product, int $index): ?string
    {
        $skuKeys = array_values(array_filter(
            array_keys(DemoMediaPack::unsplashMap()),
            fn ($key) => ! str_contains($key, '/') && ! str_starts_with($key, 'cat-') && ! str_ends_with($key, '-2')
        ));
        $sku = $skuKeys[$index % max(count($skuKeys), 1)] ?? 'EL-HP-01';
        $source = DemoMediaPack::productFile($sku);
        if (! is_file($source)) {
            return null;
        }

        $bytes = file_get_contents($source);
        if ($bytes === false) {
            return null;
        }

        $directory = 'catalog/products/'.$product->id;
        $path = $directory.'/cover.jpg';
        Storage::disk('public')->put($path, $bytes);
        Storage::disk('public')->delete([$directory.'/cover.webp', $directory.'/cover.svg']);

        return $path;
    }

    protected function writeBundledCategoryCover(Category $category, int $index): ?string
    {
        $slugs = ['electronics', 'fashion', 'home-living', 'beauty', 'sports', 'grocery', 'toys', 'automotive', 'office', 'catalog'];
        $source = DemoMediaPack::categoryFile($slugs[$index % count($slugs)]);
        if (! is_file($source)) {
            $source = DemoMediaPack::categoryFile('catalog');
        }
        if (! is_file($source)) {
            return null;
        }

        $bytes = file_get_contents($source);
        if ($bytes === false) {
            return null;
        }

        $directory = 'catalog/categories/'.$category->id;
        $path = $directory.'/banner.jpg';
        Storage::disk('public')->put($path, $bytes);
        Storage::disk('public')->delete([$directory.'/cover.jpg', $directory.'/cover.webp', $directory.'/cover.svg']);

        return $path;
    }

    protected function writeProductAlternate(Product $product, array $def, int $index): ?string
    {
        $sku = (string) ($def['sku'] ?? '');
        $path = 'catalog/products/'.$product->id.'/alternate.jpg';

        if ($sku !== '' && $this->copyLocalDemoFile(DemoMediaPack::productAltFile($sku), $path)) {
            return $path;
        }

        return $this->writeBundledProductHover($product, $index);
    }

    protected function writeBundledProductHover(Product $product, int $index): ?string
    {
        $skuKeys = array_values(array_filter(
            array_keys(DemoMediaPack::unsplashMap()),
            fn ($key) => ! str_contains($key, '/') && ! str_starts_with($key, 'cat-') && ! str_ends_with($key, '-2')
        ));
        $sku = $skuKeys[$index % max(count($skuKeys), 1)] ?? 'EL-HP-01';
        $source = DemoMediaPack::productAltFile($sku);
        if (! is_file($source)) {
            $source = DemoMediaPack::productFile($sku);
        }
        if (! is_file($source)) {
            return null;
        }

        $bytes = file_get_contents($source);
        if ($bytes === false) {
            return null;
        }

        $path = 'catalog/products/'.$product->id.'/alternate.jpg';
        Storage::disk('public')->put($path, $bytes);

        return $path;
    }

    protected function writeCover(Product $product, string $accent, string $name, string $category = 'catalog', string $sku = ''): string
    {
        // Install must stay offline-fast: use bundled Classic samples or SVG (no CDN waits).
        $bundled = $this->copyBundledProductImage((int) $product->id);
        if ($bundled) {
            return $bundled;
        }

        $seed = $sku !== '' ? $sku : 'product-'.$product->id;

        return $this->writeSvgFallback('catalog/products/'.$product->id.'/cover.svg', $name, $seed, $accent, $category);
    }

    protected function writeCategoryCover(Category $category, string $slug, string $name): string
    {
        $bundled = $this->copyBundledProductImage((int) $category->id, 'catalog/categories/'.$category->id.'/cover.webp');
        if ($bundled) {
            return $bundled;
        }

        return $this->writeSvgFallback('catalog/categories/'.$category->id.'/cover.svg', $name, $slug, '#94a3b8', $slug);
    }

    /**
     * Copy a pre-shipped Classic catalog photo (themes/classic/assets/catalog/products/*.webp).
     */
    protected function copyBundledProductImage(int $id, ?string $destPath = null): ?string
    {
        $dir = base_path('themes/classic/assets/catalog/products');
        if (! is_dir($dir)) {
            return null;
        }

        $files = glob($dir.DIRECTORY_SEPARATOR.'*.webp') ?: [];
        if ($files === []) {
            return null;
        }

        sort($files);
        $source = $files[($id - 1) % count($files)];
        $bytes = @file_get_contents($source);
        if ($bytes === false || strlen($bytes) < 100) {
            return null;
        }

        $path = $destPath ?: ('catalog/products/'.$id.'/cover.webp');
        Storage::disk('public')->put($path, $bytes);

        return $path;
    }

    protected function realPhotoUrl(string $category, string $seed): string
    {
        // Unique curated Unsplash photo per seed (real photography, no shared category image).
        $id = $this->uniqueUnsplashId($seed);
        if ($id) {
            return 'https://images.unsplash.com/photo-'.$id.'?auto=format&fit=crop&w=800&h=800&q=80';
        }

        // Category-aligned Flickr photo, locked per seed so each SKU stays unique.
        $keyword = $this->categoryKeyword($category);
        $lock = abs(crc32($seed)) % 100000;

        return 'https://loremflickr.com/800/800/'.$keyword.'/all?lock='.$lock;
    }

    protected function categoryKeyword(string $category): string
    {
        return match ($category) {
            'audio' => 'headphones,earbuds',
            'phones' => 'smartphone,mobile',
            'laptops' => 'laptop,notebook',
            'wearables' => 'smartwatch,watch',
            'women' => 'womens,fashion',
            'men' => 'mens,fashion',
            'shoes' => 'sneakers,shoes',
            'accessories' => 'handbag,accessory',
            'furniture', 'home-living' => 'furniture,sofa',
            'kitchen' => 'kitchen,cookware',
            'bedding' => 'bedroom,bedding',
            'decor' => 'homedecor,interior',
            'skincare', 'makeup', 'hair', 'fragrance', 'beauty' => 'cosmetics,beauty',
            'fitness', 'yoga', 'sports' => 'fitness,gym',
            'outdoor' => 'hiking,outdoor',
            'cycling' => 'bicycle,cycling',
            'pantry', 'beverages', 'snacks', 'organic', 'grocery' => 'food,grocery',
            'stem', 'action-figures', 'board-games', 'baby', 'toys' => 'toys,kids',
            'interior', 'car-care', 'automotive' => 'car,automobile',
            'desks', 'chairs', 'stationery', 'office' => 'office,desk',
            'electronics' => 'electronics,gadgets',
            'fashion' => 'fashion,clothing',
            'catalog' => 'shopping,retail',
            default => 'product,shopping',
        };
    }

    protected function uniqueUnsplashId(string $seed): ?string
    {
        return DemoMediaPack::unsplashId($seed);
    }

    protected function downloadRealImage(string $url, string $path): bool
    {
        try {
            $response = Http::timeout(25)
                ->withoutVerifying() // demo seeder only — local Windows PHP often lacks CA bundle
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept' => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
                ])
                ->withOptions(['allow_redirects' => true])
                ->get($url);

            if (! $response->successful()) {
                return false;
            }

            $bytes = $response->body();
            if (strlen($bytes) < 2000) {
                return false;
            }

            $head = substr($bytes, 0, 32);
            if (str_contains($head, '<!DOCTYPE') || str_contains($head, '<html') || str_contains($head, '<?xml')) {
                return false;
            }

            // Accept JPEG / PNG / WebP / GIF magic bytes.
            $hex = substr(bin2hex($bytes), 0, 8);
            $ok = str_starts_with($hex, 'ffd8ff') // jpeg
                || str_starts_with($hex, '89504e47') // png
                || str_starts_with($hex, '47494638') // gif
                || str_starts_with($bytes, 'RIFF'); // webp
            if (! $ok) {
                return false;
            }

            Storage::disk('public')->put($path, $bytes);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function writeSvgFallback(string $path, string $name, string $sku, string $accent, string $category): string
    {
        $safe = htmlspecialchars(mb_strimwidth($name, 0, 28, '…'), ENT_XML1);
        $skuSafe = htmlspecialchars($sku, ENT_XML1);
        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 800">
  <rect width="800" height="800" fill="#f8fafc"/>
  <circle cx="400" cy="340" r="180" fill="{$accent}" opacity="0.35"/>
  <text x="400" y="640" text-anchor="middle" font-family="Inter, Arial, sans-serif" font-size="28" font-weight="700" fill="#111">{$safe}</text>
  <text x="400" y="680" text-anchor="middle" font-family="Inter, Arial, sans-serif" font-size="16" fill="#64748b">{$skuSafe}</text>
</svg>
SVG;
        $fallback = str_ends_with($path, '.svg') ? $path : preg_replace('/\.jpg$/', '.svg', $path);
        Storage::disk('public')->put($fallback, $svg);

        return $fallback;
    }

    protected function brandLogoUrl(string $slug): string
    {
        $label = match ($slug) {
            'wento' => 'Wento',
            'volt' => 'Volt',
            'northline' => 'Northline',
            'lumen' => 'Lumen',
            'peakform' => 'Peakform',
            'casa' => 'Casa',
            'bloom' => 'Bloom',
            'harbor' => 'Harbor',
            'paperfolk' => 'Paperfolk',
            'aura' => 'Aura',
            default => ucfirst($slug),
        };
        $color = [
            'wento' => '#1C523B',
            'volt' => '#6D3AED',
            'northline' => '#0F172A',
            'lumen' => '#D97706',
            'peakform' => '#059669',
            'casa' => '#B45309',
            'bloom' => '#DB2777',
            'harbor' => '#0284C7',
            'paperfolk' => '#475569',
            'aura' => '#7C3AED',
        ][$slug] ?? '#334155';

        $icon = match ($slug) {
            'wento' => '<path d="M18 8h20l-2 18H20L18 8Zm4 0V6a6 6 0 0 1 12 0v2" fill="none" stroke="'.$color.'" stroke-width="3" stroke-linecap="round"/><circle cx="28" cy="20" r="3" fill="'.$color.'"/>',
            'volt' => '<path d="M30 4 14 30h12l-4 18 20-28H30l4-16Z" fill="'.$color.'"/>',
            'northline' => '<path d="M8 40 28 8l20 32H8Zm20-18v14" fill="none" stroke="'.$color.'" stroke-width="3" stroke-linejoin="round"/><circle cx="28" cy="28" r="3" fill="'.$color.'"/>',
            'lumen' => '<circle cx="28" cy="24" r="8" fill="'.$color.'"/><g stroke="'.$color.'" stroke-width="3" stroke-linecap="round"><path d="M28 4v6M28 38v6M8 24h6M42 24h6M12 10l4 4M40 38l4 4M12 38l4-4M40 10l4-4"/></g>',
            'peakform' => '<path d="M6 40 22 12l8 14 8-10 12 24H6Z" fill="'.$color.'"/>',
            'casa' => '<path d="M8 22 28 6l20 16v18H8V22Z" fill="none" stroke="'.$color.'" stroke-width="3" stroke-linejoin="round"/><path d="M22 40V26h12v14" fill="none" stroke="'.$color.'" stroke-width="3"/>',
            'bloom' => '<circle cx="28" cy="24" r="7" fill="'.$color.'"/><circle cx="16" cy="18" r="6" fill="'.$color.'" opacity=".85"/><circle cx="40" cy="18" r="6" fill="'.$color.'" opacity=".85"/><circle cx="18" cy="32" r="6" fill="'.$color.'" opacity=".85"/><circle cx="38" cy="32" r="6" fill="'.$color.'" opacity=".85"/><path d="M28 31v13" stroke="'.$color.'" stroke-width="3" stroke-linecap="round"/>',
            'harbor' => '<path d="M28 6v10M18 44c2-10 6-16 10-16s8 6 10 16" fill="none" stroke="'.$color.'" stroke-width="3" stroke-linecap="round"/><path d="M10 30h36M14 36h28M18 42h20" stroke="'.$color.'" stroke-width="3" stroke-linecap="round"/>',
            'paperfolk' => '<path d="M14 8h18l10 10v26H14V8Z" fill="none" stroke="'.$color.'" stroke-width="3" stroke-linejoin="round"/><path d="M32 8v10h10M20 28h16M20 36h12" stroke="'.$color.'" stroke-width="3" stroke-linecap="round"/>',
            'aura' => '<circle cx="28" cy="24" r="14" fill="none" stroke="'.$color.'" stroke-width="3"/><circle cx="28" cy="24" r="6" fill="'.$color.'"/><path d="M28 4v4M28 40v4M8 24h4M44 24h4" stroke="'.$color.'" stroke-width="3" stroke-linecap="round"/>',
            default => '<rect x="10" y="10" width="36" height="28" rx="6" fill="'.$color.'"/>',
        };

        $safe = htmlspecialchars($label, ENT_XML1);
        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 280 88" fill="none">
  <g transform="translate(8,18)">{$icon}</g>
  <text x="78" y="52" font-family="Georgia, 'Times New Roman', serif" font-size="28" font-weight="700" fill="{$color}">{$safe}</text>
</svg>
SVG;
        $path = 'catalog/brands/'.$slug.'/logo.svg';
        Storage::disk('public')->put($path, $svg);

        return $path;
    }

    /** @return array<string, Brand> */
    protected function brands(UrlRewriteService $urls): array
    {
        $names = [
            'wento' => 'Wento',
            'volt' => 'Volt',
            'northline' => 'Northline',
            'lumen' => 'Lumen',
            'peakform' => 'Peakform',
            'casa' => 'Casa',
            'bloom' => 'Bloom',
            'harbor' => 'Harbor',
            'paperfolk' => 'Paperfolk',
            'aura' => 'Aura',
        ];
        $out = [];
        foreach ($names as $slug => $name) {
            $brand = Brand::query()->whereHas('translations', fn ($t) => $t->where('slug', $slug))->first();
            if (! $brand) {
                $brand = Brand::query()->create(['is_enabled' => true]);
                $brand->translations()->create(['language_code' => 'en', 'name' => $name, 'slug' => $slug]);
                $urls->sync('brand', $brand->id, 'en', $slug, 'brand');
            }
            $out[$slug] = $brand;
        }

        return $out;
    }

    /** @return array<string, Category> */
    protected function categories(UrlRewriteService $urls): array
    {
        $tree = [
            'catalog' => ['Sale', 'New arrivals', 'Clearance', 'Gift shop'],
            'electronics' => ['Phones', 'Laptops', 'Audio', 'Wearables'],
            'fashion' => ['Women', 'Men', 'Shoes', 'Accessories'],
            'home-living' => ['Furniture', 'Kitchen', 'Bedding', 'Decor'],
            'beauty' => ['Skincare', 'Makeup', 'Hair', 'Fragrance'],
            'sports' => ['Fitness', 'Outdoor', 'Cycling', 'Yoga'],
            'grocery' => ['Pantry', 'Beverages', 'Snacks', 'Organic'],
            'toys' => ['STEM', 'Action figures', 'Board games', 'Baby'],
            'automotive' => ['Interior', 'Car care', 'Tools', 'Car electronics'],
            'office' => ['Desks', 'Chairs', 'Stationery', 'Storage'],
        ];
        $parents = [
            'catalog' => 'Catalog',
            'electronics' => 'Electronics',
            'fashion' => 'Fashion',
            'home-living' => 'Home & Living',
            'beauty' => 'Beauty',
            'sports' => 'Sports',
            'grocery' => 'Grocery',
            'toys' => 'Toys',
            'automotive' => 'Automotive',
            'office' => 'Office',
        ];

        $out = [];
        $position = 1;
        foreach ($parents as $slug => $name) {
            $out[$slug] = $this->ensureCategory($urls, $slug, $name, null, $position++, $name.' for the demo shop.');
            $childPos = 1;
            foreach ($tree[$slug] as $childName) {
                $childSlug = Str::slug($childName);
                $out[$childSlug] = $this->ensureCategory($urls, $childSlug, $childName, $out[$slug]->id, $childPos++, $childName.' in '.$name.'.');
            }
        }

        return $out;
    }

    protected function ensureCategory(UrlRewriteService $urls, string $slug, string $name, ?int $parentId, int $position, string $description): Category
    {
        $category = Category::query()
            ->whereHas('translations', fn ($t) => $t->where('slug', $slug)->where('language_code', 'en'))
            ->first();
        if (! $category) {
            $category = Category::query()->create([
                'parent_id' => $parentId,
                'is_enabled' => true,
                'position' => $position,
            ]);
        } else {
            $category->fill(['parent_id' => $parentId ?: $category->parent_id, 'position' => $position, 'is_enabled' => true])->save();
        }
        $category->translations()->updateOrCreate(
            ['language_code' => 'en'],
            ['name' => $name, 'slug' => $slug, 'description' => $description, 'meta_title' => $name]
        );
        $urls->sync('category', $category->id, 'en', $slug);

        return $category->fresh(['translations', 'parent']);
    }

    protected function parentSlug(Category $category): string
    {
        return (string) $category->parent?->translation('en')?->slug;
    }

    /** @return array<string, array<string, FeatureValue>> */
    protected function features(): array
    {
        $defs = [
            'material' => ['Cotton', 'Leather', 'Metal', 'Plastic', 'Wood'],
            'color' => ['Black', 'White', 'Blue', 'Red', 'Green'],
            'size' => ['S', 'M', 'L', 'XL'],
        ];
        $out = [];
        $pos = 1;
        foreach ($defs as $code => $values) {
            $feature = Feature::query()->firstOrCreate(
                ['code' => $code],
                ['is_filterable' => true, 'used_for_combinations' => $code !== 'material', 'position' => $pos++]
            );
            $feature->translations()->updateOrCreate(['language_code' => 'en'], ['name' => Str::title($code)]);
            foreach ($values as $i => $label) {
                $value = FeatureValue::query()
                    ->where('feature_id', $feature->id)
                    ->whereHas('translations', fn ($t) => $t->where('value', $label))
                    ->first();
                if (! $value) {
                    $value = FeatureValue::query()->create(['feature_id' => $feature->id, 'position' => $i + 1]);
                    $value->translations()->create(['language_code' => 'en', 'value' => $label]);
                }
                $out[$code][$label] = $value;
            }
        }

        return $out;
    }

    /** @param  array<string, array<string, FeatureValue>>  $features */
    protected function pickFeatures(array $features, int $index): array
    {
        $colors = array_values($features['color'] ?? []);
        $sizes = array_values($features['size'] ?? []);
        $materials = array_values($features['material'] ?? []);
        $rows = [];
        foreach ([$colors, $sizes, $materials] as $set) {
            if ($set === []) {
                continue;
            }
            $value = $set[$index % count($set)];
            $rows[] = ['feature_id' => $value->feature_id, 'feature_value_id' => $value->id];
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    protected function productDefs(): array
    {
        $rows = [
            ['sku' => 'EL-HP-01', 'name' => 'Over Ear Stereo Wireless Headset 40H Playtime', 'category' => 'audio', 'brand' => 'volt', 'price' => 28, 'short' => 'The standard chunk of Lorem Ipsum used since the 1500s is reproduced below for those interested.', 'accent' => '#c4b5fd'],
            ['sku' => 'EL-HP-02', 'name' => 'Studio One Earphones', 'category' => 'audio', 'brand' => 'lumen', 'price' => 79, 'compare' => 99, 'short' => 'In-ear monitors with a clear midrange for commuting.', 'accent' => '#93c5fd'],
            ['sku' => 'EL-HP-03', 'name' => 'Harbor Noise Cancelling Buds', 'category' => 'audio', 'brand' => 'harbor', 'price' => 149, 'short' => 'Compact buds with a charging case and dual-mic ANC.', 'accent' => '#a7f3d0'],
            ['sku' => 'EL-PH-01', 'name' => 'Northline Pixel Case', 'category' => 'phones', 'brand' => 'northline', 'price' => 24, 'short' => 'Slim protective case with a raised camera ring.', 'accent' => '#fde68a'],
            ['sku' => 'EL-PH-02', 'name' => 'Volt Charge Brick', 'category' => 'phones', 'brand' => 'volt', 'price' => 39, 'compare' => 49, 'short' => '30W USB-C wall charger for phones and earbuds.', 'accent' => '#c4b5fd'],
            ['sku' => 'EL-PH-03', 'name' => 'Lumen Mag Stand', 'category' => 'phones', 'brand' => 'lumen', 'price' => 34, 'short' => 'Magnetic desktop stand that doubles as a night charger.', 'accent' => '#ddd6fe'],
            ['sku' => 'EL-PH-04', 'name' => 'Harbor Screen Guard', 'category' => 'phones', 'brand' => 'harbor', 'price' => 12, 'short' => 'Tempered glass with an easy-align tray.', 'accent' => '#bfdbfe'],
            ['sku' => 'EL-LP-01', 'name' => 'Northline 14 Sleeve', 'category' => 'laptops', 'brand' => 'northline', 'price' => 42, 'short' => 'Padded sleeve with a hidden pocket for dongles.', 'accent' => '#fecaca'],
            ['sku' => 'EL-LP-02', 'name' => 'Volt USB-C Hub', 'category' => 'laptops', 'brand' => 'volt', 'price' => 59, 'compare' => 79, 'short' => 'HDMI, SD, and two USB-A ports in one stick.', 'accent' => '#c4b5fd'],
            ['sku' => 'EL-LP-03', 'name' => 'Paperfolk Desk Mat', 'category' => 'laptops', 'brand' => 'paperfolk', 'price' => 28, 'short' => 'Large felt mat that keeps the desk quiet.', 'accent' => '#e5e7eb'],
            ['sku' => 'EL-LP-04', 'name' => 'Lumen Laptop Stand', 'category' => 'laptops', 'brand' => 'lumen', 'price' => 64, 'short' => 'Folding aluminum stand that lifts the screen to eye level.', 'accent' => '#fef3c7'],
            ['sku' => 'EL-WR-01', 'name' => 'Peakform Sport Band', 'category' => 'wearables', 'brand' => 'peakform', 'price' => 22, 'short' => 'Breathable watch band for runs and gym days.', 'accent' => '#bbf7d0'],
            ['sku' => 'FA-WO-01', 'name' => 'Aura Linen Shirt', 'category' => 'women', 'brand' => 'aura', 'price' => 68, 'compare' => 88, 'short' => 'Relaxed linen shirt for warm weather.', 'accent' => '#fbcfe8'],
            ['sku' => 'FA-WO-02', 'name' => 'Bloom Everyday Tote', 'category' => 'women', 'brand' => 'bloom', 'price' => 54, 'short' => 'Structured tote with an interior zip pocket.', 'accent' => '#fde68a'],
            ['sku' => 'FA-WO-03', 'name' => 'Harbor Wrap Cardigan', 'category' => 'women', 'brand' => 'harbor', 'price' => 72, 'short' => 'Soft knit wrap that layers over dresses.', 'accent' => '#ddd6fe'],
            ['sku' => 'FA-ME-01', 'name' => 'Northline Oxford Shirt', 'category' => 'men', 'brand' => 'northline', 'price' => 58, 'short' => 'Classic oxford that works with jeans or trousers.', 'accent' => '#bfdbfe'],
            ['sku' => 'FA-ME-02', 'name' => 'Peakform Training Tee', 'category' => 'men', 'brand' => 'peakform', 'price' => 32, 'compare' => 40, 'short' => 'Moisture-wicking tee cut for movement.', 'accent' => '#a7f3d0'],
            ['sku' => 'FA-ME-03', 'name' => 'Wento Merino Crew', 'category' => 'men', 'brand' => 'wento', 'price' => 86, 'short' => 'Fine merino crewneck for travel days.', 'accent' => '#e5e7eb'],
            ['sku' => 'FA-SH-01', 'name' => 'Harbor Court Sneaker', 'category' => 'shoes', 'brand' => 'harbor', 'price' => 96, 'compare' => 120, 'short' => 'Court sneaker with a cushioned footbed.', 'accent' => '#fecaca'],
            ['sku' => 'FA-SH-02', 'name' => 'Peakform Runner', 'category' => 'shoes', 'brand' => 'peakform', 'price' => 118, 'short' => 'Daily trainer with a responsive foam midsole.', 'accent' => '#bbf7d0'],
            ['sku' => 'FA-AC-01', 'name' => 'Aura Silk Scarf', 'category' => 'accessories', 'brand' => 'aura', 'price' => 36, 'short' => 'Printed silk scarf that doubles as a wrap.', 'accent' => '#fbcfe8'],
            ['sku' => 'FA-AC-02', 'name' => 'Northline Leather Belt', 'category' => 'accessories', 'brand' => 'northline', 'price' => 44, 'short' => 'Full-grain belt with a brushed buckle.', 'accent' => '#fde68a'],
            ['sku' => 'HM-FU-01', 'name' => 'Casa Oak Side Table', 'category' => 'furniture', 'brand' => 'casa', 'price' => 189, 'compare' => 229, 'short' => 'Round oak table for the sofa arm.', 'accent' => '#fed7aa'],
            ['sku' => 'HM-FU-02', 'name' => 'Lumen Floor Lamp', 'category' => 'furniture', 'brand' => 'lumen', 'price' => 149, 'short' => 'Arc lamp with a linen shade and dimmer.', 'accent' => '#fef3c7'],
            ['sku' => 'HM-KI-01', 'name' => 'Harbor Pour-Over Kettle', 'category' => 'kitchen', 'brand' => 'harbor', 'price' => 64, 'short' => 'Gooseneck kettle for slow coffee.', 'accent' => '#bfdbfe'],
            ['sku' => 'HM-KI-02', 'name' => 'Casa Stoneware Mug', 'category' => 'kitchen', 'brand' => 'casa', 'price' => 18, 'short' => 'Speckled mug that holds a full pour-over.', 'accent' => '#e5e7eb'],
            ['sku' => 'HM-BE-01', 'name' => 'Aura Percale Sheets', 'category' => 'bedding', 'brand' => 'aura', 'price' => 128, 'compare' => 158, 'short' => 'Crisp percale set that sleeps cool.', 'accent' => '#ddd6fe'],
            ['sku' => 'HM-DE-01', 'name' => 'Paperfolk Wall Print', 'category' => 'decor', 'brand' => 'paperfolk', 'price' => 46, 'short' => 'Giclee print of a coastal line drawing.', 'accent' => '#e0e7ff'],
            ['sku' => 'BE-SK-01', 'name' => 'Bloom Daily Cleanser', 'category' => 'skincare', 'brand' => 'bloom', 'price' => 22, 'short' => 'Gentle gel cleanser for morning and night.', 'accent' => '#a7f3d0'],
            ['sku' => 'BE-SK-02', 'name' => 'Aura Vitamin C Serum', 'category' => 'skincare', 'brand' => 'aura', 'price' => 38, 'compare' => 48, 'short' => 'Brightening serum in a tinted glass dropper.', 'accent' => '#fde68a'],
            ['sku' => 'BE-MK-01', 'name' => 'Bloom Soft Blush', 'category' => 'makeup', 'brand' => 'bloom', 'price' => 24, 'short' => 'Buildable cream blush in a warm rose.', 'accent' => '#fecaca'],
            ['sku' => 'BE-HA-01', 'name' => 'Harbor Hair Oil', 'category' => 'hair', 'brand' => 'harbor', 'price' => 26, 'short' => 'Light oil for ends, no heavy residue.', 'accent' => '#bfdbfe'],
            ['sku' => 'BE-FR-01', 'name' => 'Aura Cedar Eau', 'category' => 'fragrance', 'brand' => 'aura', 'price' => 82, 'short' => 'Cedar and citrus cologne in a travel bottle.', 'accent' => '#c4b5fd'],
            ['sku' => 'SP-FI-01', 'name' => 'Peakform Resistance Bands', 'category' => 'fitness', 'brand' => 'peakform', 'price' => 29, 'short' => 'Set of three bands with a carry pouch.', 'accent' => '#bbf7d0'],
            ['sku' => 'SP-FI-02', 'name' => 'Peakform Yoga Block', 'category' => 'yoga', 'brand' => 'peakform', 'price' => 16, 'short' => 'High-density block for supported poses.', 'accent' => '#a7f3d0'],
            ['sku' => 'SP-OU-01', 'name' => 'Northline Daypack', 'category' => 'outdoor', 'brand' => 'northline', 'price' => 74, 'compare' => 94, 'short' => '20L pack with a bottle pocket and laptop sleeve.', 'accent' => '#fed7aa'],
            ['sku' => 'SP-CY-01', 'name' => 'Volt Bike Lights', 'category' => 'cycling', 'brand' => 'volt', 'price' => 36, 'short' => 'USB-C front and rear lights with a helmet clip.', 'accent' => '#fde68a'],
            ['sku' => 'SP-YO-01', 'name' => 'Aura Studio Mat', 'category' => 'yoga', 'brand' => 'aura', 'price' => 58, 'short' => 'Natural rubber mat with alignment marks.', 'accent' => '#fbcfe8'],
            ['sku' => 'GR-PA-01', 'name' => 'Harbor Olive Oil', 'category' => 'pantry', 'brand' => 'harbor', 'price' => 19, 'short' => 'Extra virgin oil from a small coastal mill.', 'accent' => '#fef3c7'],
            ['sku' => 'GR-BE-01', 'name' => 'Bloom House Coffee', 'category' => 'beverages', 'brand' => 'bloom', 'price' => 16, 'short' => 'Medium roast beans for filter or espresso.', 'accent' => '#fed7aa'],
            ['sku' => 'GR-SN-01', 'name' => 'Casa Sesame Crackers', 'category' => 'snacks', 'brand' => 'casa', 'price' => 7, 'short' => 'Thin crackers that pair with cheese.', 'accent' => '#fde68a'],
            ['sku' => 'GR-OR-01', 'name' => 'Bloom Honey Jar', 'category' => 'organic', 'brand' => 'bloom', 'price' => 14, 'short' => 'Wildflower honey in a reusable glass jar.', 'accent' => '#fef3c7'],
            ['sku' => 'TY-ST-01', 'name' => 'Paperfolk Marble Run', 'category' => 'stem', 'brand' => 'paperfolk', 'price' => 42, 'short' => 'Wooden marble run with interchangeable ramps.', 'accent' => '#e0e7ff'],
            ['sku' => 'TY-AC-01', 'name' => 'Volt Mini Rover', 'category' => 'action-figures', 'brand' => 'volt', 'price' => 28, 'short' => 'Remote rover that climbs furniture edges.', 'accent' => '#c4b5fd'],
            ['sku' => 'TY-BG-01', 'name' => 'Harbor Strategy Deck', 'category' => 'board-games', 'brand' => 'harbor', 'price' => 32, 'short' => 'Two-player card game that finishes in 20 minutes.', 'accent' => '#bfdbfe'],
            ['sku' => 'TY-BA-01', 'name' => 'Aura Soft Blocks', 'category' => 'baby', 'brand' => 'aura', 'price' => 24, 'short' => 'Fabric blocks with crinkle sides and numbers.', 'accent' => '#fbcfe8'],
            ['sku' => 'AU-IN-01', 'name' => 'Casa Seat Organizer', 'category' => 'interior', 'brand' => 'casa', 'price' => 21, 'short' => 'Back-seat pockets for tablets and bottles.', 'accent' => '#e5e7eb'],
            ['sku' => 'AU-CA-01', 'name' => 'Harbor Car Soap', 'category' => 'car-care', 'brand' => 'harbor', 'price' => 11, 'short' => 'pH-neutral wash that leaves a light gloss.', 'accent' => '#a7f3d0'],
            ['sku' => 'OF-DE-01', 'name' => 'Paperfolk Walnut Tray', 'category' => 'desks', 'brand' => 'paperfolk', 'price' => 48, 'short' => 'Desktop tray for pens, keys, and a notepad.', 'accent' => '#fed7aa'],
            ['sku' => 'OF-CH-01', 'name' => 'Casa Task Cushion', 'category' => 'chairs', 'brand' => 'casa', 'price' => 39, 'compare' => 49, 'short' => 'Memory-foam cushion that clips to a dining chair.', 'accent' => '#ddd6fe'],
            ['sku' => 'OF-ST-01', 'name' => 'Paperfolk Notebook Set', 'category' => 'stationery', 'brand' => 'paperfolk', 'price' => 18, 'short' => 'Three A5 notebooks with dotted pages.', 'accent' => '#e0e7ff'],
        ];

        return $rows;
    }

    /**
     * @param  callable(): void  $work
     */
    protected function demoStep(string $label, callable $work): void
    {
        $progress = config('wento.install_progress');
        if (is_callable($progress)) {
            InstallProgress::note($progress, $label);
        }
        $work();
    }

    protected function customers(): void
    {
        $retail = CustomerGroup::query()->where('code', 'retailer')->value('id');
        $vip = CustomerGroup::query()->where('code', 'vip')->value('id');
        $needed = self::TARGET_CUSTOMERS - Customer::query()->count();
        $addDemo = ! Customer::query()->where('email', 'demo@wentocart.test')->exists();
        $total = max(0, $needed) + ($addDemo ? 1 : 0);
        if ($total < 1) {
            return;
        }
        DemoInstallMeter::current()?->expect('Customers', $total);

        if ($addDemo) {
            $this->ensureDemoCustomer($retail);
            DemoInstallMeter::current()?->advance('Customers');
        }
        if ($needed <= 0) {
            return;
        }

        $first = ['Ava', 'Noah', 'Mia', 'Liam', 'Isha', 'Omar', 'Elena', 'Kai', 'Priya', 'Mateo', 'Sofia', 'Arjun', 'Chloe', 'Hassan', 'Nina', 'Leo'];
        $last = ['Bennett', 'Shah', 'Costa', 'Nguyen', 'Patel', 'Khan', 'Silva', 'Park', 'Rossi', 'Okoye', 'Meyer', 'Tanaka', 'Hughes', 'Ali', 'Berg', 'Diaz'];
        $cities = [
            ['Mumbai', 'Maharashtra', '400001', 'IN'],
            ['Austin', 'TX', '78701', 'US'],
            ['London', 'England', 'SW1A 1AA', 'GB'],
            ['Berlin', 'BE', '10115', 'DE'],
            ['Toronto', 'ON', 'M5V 2T6', 'CA'],
            ['Sydney', 'NSW', '2000', 'AU'],
            ['Dubai', 'DU', '00000', 'AE'],
            ['Singapore', 'SG', '018956', 'SG'],
        ];

        $existing = Customer::query()->count();
        $password = Hash::make('password');
        for ($i = 0; $i < $needed; $i++) {
            $n = $existing + $i;
            $fn = $first[$n % count($first)];
            $ln = $last[($n * 3) % count($last)];
            $email = strtolower($fn.'.'.$ln.$n.'@demo.wentocart.test');
            if (Customer::query()->where('email', $email)->exists()) {
                continue;
            }
            $customer = Customer::createTrusted([
                'name' => $fn.' '.$ln,
                'first_name' => $fn,
                'last_name' => $ln,
                'email' => $email,
                'phone' => '+1 555 '.str_pad((string) (1000 + $n), 4, '0', STR_PAD_LEFT),
                'password' => $password,
                'is_enabled' => true,
                'customer_group' => $n % 17 === 0 ? 'vip' : 'retailer',
                'customer_group_id' => $n % 17 === 0 ? $vip : $retail,
                'newsletter' => $n % 3 === 0,
            ]);
            $city = $cities[$n % count($cities)];
            $customer->addresses()->create([
                'label' => 'Home',
                'first_name' => $fn,
                'last_name' => $ln,
                'phone' => $customer->phone,
                'line1' => (10 + $n).' Market Street',
                'city' => $city[0],
                'state' => $city[1],
                'postal_code' => $city[2],
                'country_code' => $city[3],
                'is_default_billing' => true,
                'is_default_shipping' => true,
            ]);
            DemoInstallMeter::current()?->advance('Customers');
        }
    }

    protected function ensureDemoCustomer(?int $groupId): Customer
    {
        $customer = Customer::query()->updateOrCreate(
            ['email' => 'demo@wentocart.test'],
            [
                'name' => 'Demo Shopper',
                'first_name' => 'Demo',
                'last_name' => 'Shopper',
                'phone' => '+1 555 0101',
                'password' => Hash::make('password'),
                'is_enabled' => true,
                'customer_group' => 'retailer',
                'customer_group_id' => $groupId,
                'newsletter' => true,
            ]
        );
        if (! $customer->addresses()->exists()) {
            $customer->addresses()->create([
                'label' => 'Home',
                'first_name' => 'Demo',
                'last_name' => 'Shopper',
                'phone' => '+1 555 0101',
                'line1' => '100 Demo Avenue',
                'line2' => 'Suite 4',
                'city' => 'Austin',
                'state' => 'TX',
                'postal_code' => '78701',
                'country_code' => 'US',
                'is_default_billing' => true,
                'is_default_shipping' => true,
            ]);
            $customer->addresses()->create([
                'label' => 'Office',
                'first_name' => 'Demo',
                'last_name' => 'Shopper',
                'phone' => '+1 555 0199',
                'line1' => '500 Congress Ave',
                'city' => 'Austin',
                'state' => 'TX',
                'postal_code' => '78701',
                'country_code' => 'US',
            ]);
        }

        return $customer;
    }

    protected function orders(): void
    {
        $storeId = app(StoreContext::class)->id() ?: StoreBootstrap::ensure()->id;
        Order::withoutGlobalScopes()->whereNull('store_id')->update(['store_id' => $storeId]);

        $needed = self::TARGET_ORDERS - Order::withoutGlobalScopes()->count();
        if ($needed <= 0) {
            return;
        }

        $customers = Customer::query()->with('addresses')->orderBy('id')->get();
        $products = Product::query()->with('translations')->orderBy('id')->get();
        $payments = PaymentMethod::query()->where('is_enabled', true)->orderBy('position')->get();
        $shipping = ShippingMethod::query()->where('is_enabled', true)->orderBy('position')->get();
        if ($customers->isEmpty() || $products->isEmpty() || $payments->isEmpty() || $shipping->isEmpty()) {
            return;
        }
        DemoInstallMeter::current()?->expect('Orders', $needed);

        $statuses = ['pending', 'processing', 'shipped', 'completed', 'cancelled', 'refunded'];
        $start = max(10001, (int) preg_replace('/\D/', '', (string) Order::query()->max('number')) + 1);

        Order::withoutEvents(function () use ($needed, $customers, $products, $payments, $shipping, $statuses, $start, $storeId) {
            for ($i = 0; $i < $needed; $i++) {
                $customer = $customers[$i % $customers->count()];
                $address = $customer->addresses->first()?->only([
                    'first_name', 'last_name', 'phone', 'line1', 'line2', 'city', 'state', 'postal_code', 'country_code',
                ]) ?: ['first_name' => $customer->first_name ?: $customer->name, 'line1' => '1 Main', 'city' => 'Austin', 'country_code' => 'US'];
                $status = $statuses[$i % count($statuses)];
                $pay = $payments[$i % $payments->count()];
                $ship = $shipping[$i % $shipping->count()];
                $lines = $products->slice($i % max(1, $products->count()), ($i % 3) + 1);
                if ($lines->isEmpty()) {
                    $lines = $products->take(1);
                }
                $subtotal = 0;
                $created = now()->subDays($i % 90)->subMinutes($i * 13);

                $order = Order::createTrusted([
                    'number' => 'WENTO-'.($start + $i),
                    'customer_id' => $customer->id,
                    'email' => $customer->email,
                    'status' => $status,
                    'currency_code' => 'USD',
                    'currency_rate' => 1,
                    'subtotal' => 0,
                    'tax_total' => 0,
                    'shipping_total' => (float) $ship->price,
                    'discount_total' => $i % 5 === 0 ? 5 : 0,
                    'grand_total' => 0,
                    'payment_method_id' => $pay->id,
                    'shipping_method_id' => $ship->id,
                    'payment_status' => in_array($status, ['completed', 'processing', 'shipped'], true) ? 'paid' : 'pending',
                    'billing_address' => $address,
                    'shipping_address' => $address,
                    'customer_note' => $i % 11 === 0 ? 'Please leave at the door.' : null,
                    'store_id' => $storeId,
                    'created_at' => $created,
                    'updated_at' => $created,
                ]);

                foreach ($lines as $product) {
                    $qty = ($i % 2) + 1;
                    $unit = (float) $product->price;
                    $total = $qty * $unit;
                    $subtotal += $total;
                    $order->items()->create([
                        'product_id' => $product->id,
                        'sku' => $product->sku,
                        'name' => $product->translation('en')?->name ?: $product->sku,
                        'qty' => $qty,
                        'unit_price' => $unit,
                        'tax_rate' => 0,
                        'tax_amount' => 0,
                        'total' => $total,
                    ]);
                }

                $grand = max(0, $subtotal + (float) $ship->price - (float) $order->discount_total);
                $order->update(['subtotal' => $subtotal, 'grand_total' => $grand]);
                $order->statusHistories()->create(['status' => $status, 'comment' => 'Seeded demo order']);
                DemoInstallMeter::current()?->advance('Orders');
            }
        });

        $demo = Customer::query()->where('email', 'demo@wentocart.test')->first();
        if ($demo) {
            Order::query()->orderBy('id')->limit(8)->update(['customer_id' => $demo->id, 'email' => $demo->email]);
        }

        $max = app(OrderNumberService::class)->maxExistingSequence();
        if ($max > 0) {
            Setting::setValue('order_sequence', $max);
        }
    }

    protected function reviews(): void
    {
        if (Review::query()->count() >= 40) {
            return;
        }
        $customers = Customer::query()->orderBy('id')->limit(40)->get();
        $products = Product::query()->orderBy('id')->get();
        if ($customers->isEmpty() || $products->isEmpty()) {
            return;
        }
        $titles = ['Exactly as described', 'Daily driver now', 'Gifted this', 'Solid build', 'Would buy again'];
        $bodies = [
            'Arrived on time and matches the listing. Using it every day.',
            'Better than the last version I owned. Packaging was careful.',
            'Bought this for a friend and they asked for the link.',
            'Finish feels premium. No complaints after two weeks.',
            'Checkout was simple and the tracking email showed up quickly.',
        ];
        foreach ($customers as $i => $customer) {
            $product = $products[$i % $products->count()];
            Review::query()->updateOrCreate(
                ['product_id' => $product->id, 'customer_id' => $customer->id],
                [
                    'rating' => 4 + ($i % 2),
                    'title' => $titles[$i % count($titles)],
                    'body' => $bodies[$i % count($bodies)],
                    'status' => 'approved',
                    'is_approved' => true,
                    'is_verified_purchase' => $i % 3 === 0,
                ]
            );
        }

        foreach ($customers->take(5) as $i => $customer) {
            $product = $products[($i + 7) % $products->count()];
            Review::query()->updateOrCreate(
                ['product_id' => $product->id, 'customer_id' => $customer->id],
                [
                    'rating' => 3,
                    'title' => 'Needs a second look',
                    'body' => 'The listing looks right but I want a moderator to confirm the fit notes.',
                    'status' => 'pending',
                    'is_approved' => false,
                    'is_verified_purchase' => false,
                ]
            );
        }

        $demo = Customer::query()->where('email', 'demo@wentocart.test')->first();
        $first = $products->first();
        if ($demo && $first) {
            $demo->wishlist()->syncWithoutDetaching([$first->id]);
        }
    }

    protected function questions(): void
    {
        if (ProductQuestion::query()->count() >= 8) {
            return;
        }
        $customers = Customer::query()->orderBy('id')->limit(12)->get();
        $products = Product::query()->orderBy('id')->limit(8)->get();
        if ($customers->isEmpty() || $products->isEmpty()) {
            return;
        }
        $prompts = [
            'Does this ship with a carry case?',
            'Is the size true to the chart?',
            'Can I use this with a European plug adapter?',
            'What is the warranty coverage on the battery?',
            'How long does delivery usually take?',
            'Is this item returnable if the size does not fit?',
        ];
        $target = min(12, $customers->count());
        DemoInstallMeter::current()?->expect('Questions', $target);
        $created = ProductQuestion::query()->count();
        foreach ($customers as $i => $customer) {
            if ($created >= $target) {
                break;
            }
            $product = $products[$i % $products->count()];
            $body = $prompts[$i % count($prompts)];
            if (ProductQuestion::query()->where('product_id', $product->id)->where('customer_id', $customer->id)->where('body', $body)->exists()) {
                continue;
            }
            $question = ProductQuestion::query()->create([
                'product_id' => $product->id,
                'customer_id' => $customer->id,
                'body' => $body,
                'status' => $created < 8 ? 'approved' : 'pending',
                'is_approved' => $created < 8,
            ]);
            if ($created < 6) {
                $question->answers()->create([
                    'body' => 'Yes. Details are in the specifications tab, and our team ships a matching accessory in the box.',
                    'is_official' => true,
                    'is_approved' => true,
                ]);
            }
            $created++;
            DemoInstallMeter::current()?->advance('Questions');
        }
    }
}
