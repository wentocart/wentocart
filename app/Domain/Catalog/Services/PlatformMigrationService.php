<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\BrandTranslation;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\CategoryTranslation;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\ProductTranslation;
use App\Domain\Catalog\Support\CatalogCsvImport;
use App\Domain\Localization\Models\Language;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Catalog CSV import for common export layouts and a universal column map.
 */
class PlatformMigrationService
{
    public function __construct(
        protected UrlRewriteService $urls
    ) {}

    /**
     * Automatically detect source eCommerce platform from CSV header columns.
     *
     * @param  list<string>  $headers
     */
    public function detectPlatform(array $headers): string
    {
        $normalized = array_map(fn ($h) => strtolower(trim((string) $h)), $headers);

        // Store-style CSV (regular price / stock columns)
        if (in_array('regular price', $normalized, true)
            || in_array('regular_price', $normalized, true)
            || in_array('in stock?', $normalized, true)
            || in_array('is featured?', $normalized, true)
            || in_array('tax status', $normalized, true)) {
            return 'store_csv';
        }

        // Catalog-style CSV (price tax excluded / reference columns)
        if (in_array('price tax excluded', $normalized, true)
            || in_array('reference #', $normalized, true)
            || in_array('categories (x,y,z...)', $normalized, true)
            || in_array('on sale (0/1)', $normalized, true)) {
            return 'catalog_csv';
        }

        return 'universal';
    }

    /**
     * Import a CSV or JSON catalog file.
     *
     * @return array{
     *     platform: string,
     *     total_rows: int,
     *     products_created: int,
     *     products_updated: int,
     *     categories_created: int,
     *     errors: list<string>
     * }
     */
    public function importFile(string $filePath, ?string $format = null, bool $dryRun = false, ?string $locale = null): array
    {
        if (! file_exists($filePath) || ! is_readable($filePath)) {
            throw new InvalidArgumentException("Migration file not found or not readable: {$filePath}");
        }

        $content = (string) file_get_contents($filePath);
        $delimiter = CatalogCsvImport::detectDelimiter($content, ',');
        $parsed = CatalogCsvImport::parse($content, $delimiter);

        $platform = $format ?: $this->detectPlatform($parsed['header']);
        $locale = $locale ?: Language::defaultCode();

        return $this->importRows($parsed['rows'], $platform, $dryRun, $locale);
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return array{
     *     platform: string,
     *     total_rows: int,
     *     products_created: int,
     *     products_updated: int,
     *     categories_created: int,
     *     errors: list<string>
     * }
     */
    public function importRows(array $rows, string $platform = 'universal', bool $dryRun = false, ?string $locale = null): array
    {
        $locale = $locale ?: Language::defaultCode();
        $created = 0;
        $updated = 0;
        $categoriesCreated = 0;
        $errors = [];

        $categoryCache = [];
        $brandCache = [];

        foreach ($rows as $index => $row) {
            $lineNum = $index + 2;
            try {
                $mapped = $this->mapRow($row, $platform);
                if ($mapped['sku'] === '' && $mapped['name'] === '') {
                    continue;
                }

                if ($dryRun) {
                    $created++;
                    continue;
                }

                DB::transaction(function () use (
                    $mapped,
                    $locale,
                    &$created,
                    &$updated,
                    &$categoriesCreated,
                    &$categoryCache,
                    &$brandCache
                ) {
                    // 1. Resolve Brand
                    $brandId = null;
                    if ($mapped['brand'] !== '') {
                        $brandName = $mapped['brand'];
                        if (! isset($brandCache[$brandName])) {
                            $brand = Brand::query()->whereHas('translations', fn ($q) => $q->where('name', $brandName))->first();
                            if (! $brand) {
                                $brand = Brand::query()->create([
                                    'code' => Str::slug($brandName),
                                    'is_enabled' => true,
                                ]);
                                BrandTranslation::query()->create([
                                    'brand_id' => $brand->id,
                                    'language_code' => $locale,
                                    'name' => $brandName,
                                    'slug' => Str::slug($brandName),
                                ]);
                            }
                            $brandCache[$brandName] = $brand->id;
                        }
                        $brandId = $brandCache[$brandName];
                    }

                    // 2. Find or Create Product
                    $product = Product::query()->where('sku', $mapped['sku'])->first();
                    $isNew = ! $product;

                    $productData = [
                        'type' => 'standard',
                        'sku' => $mapped['sku'],
                        'price' => $mapped['price'],
                        'compare_price' => $mapped['compare_price'],
                        'cost_price' => $mapped['cost_price'],
                        'stock_qty' => $mapped['stock_qty'],
                        'stock_status' => $mapped['stock_qty'] > 0 ? 'in_stock' : 'out_of_stock',
                        'is_enabled' => $mapped['is_enabled'],
                        'brand_id' => $brandId,
                        'weight' => $mapped['weight'],
                    ];

                    if ($isNew) {
                        $product = Product::query()->create($productData);
                        $created++;
                    } else {
                        $product->update($productData);
                        $updated++;
                    }

                    // 3. Product Translation & Rewrites
                    $slug = $mapped['slug'] !== '' ? $mapped['slug'] : Str::slug($mapped['name']);
                    if ($slug === '') {
                        $slug = 'product-'.$product->id;
                    }

                    ProductTranslation::query()->updateOrCreate(
                        ['product_id' => $product->id, 'language_code' => $locale],
                        [
                            'name' => $mapped['name'],
                            'slug' => $slug,
                            'short_description' => $mapped['short_description'],
                            'description' => $mapped['description'],
                            'meta_title' => $mapped['name'],
                            'meta_description' => Str::limit(strip_tags($mapped['short_description'] ?: $mapped['description']), 160, ''),
                        ]
                    );

                    $this->urls->sync('product', (int) $product->id, $locale, $slug);

                    // 4. Categories resolution
                    // 4. Categories resolution
                    if (! empty($mapped['categories'])) {
                        $categoryIds = [];
                        foreach ($mapped['categories'] as $catEntry) {
                            $catEntry = trim($catEntry);
                            if ($catEntry === '') {
                                continue;
                            }

                            if (str_contains($catEntry, '>')) {
                                $segments = array_map('trim', explode('>', $catEntry));
                                $parentId = null;
                                foreach ($segments as $segment) {
                                    if ($segment === '') {
                                        continue;
                                    }
                                    $cacheKey = $segment.'_p_'.($parentId ?? 0);
                                    if (! isset($categoryCache[$cacheKey])) {
                                        $cat = Category::query()
                                            ->when($parentId, fn ($q) => $q->where('parent_id', $parentId), fn ($q) => $q->whereNull('parent_id'))
                                            ->whereHas('translations', fn ($q) => $q->where('name', $segment))
                                            ->first();
                                        if (! $cat) {
                                            $cat = Category::query()->create(['parent_id' => $parentId, 'is_enabled' => true]);
                                            CategoryTranslation::query()->create([
                                                'category_id' => $cat->id,
                                                'language_code' => $locale,
                                                'name' => $segment,
                                                'slug' => Str::slug($segment),
                                            ]);
                                            $this->urls->sync('category', (int) $cat->id, $locale, Str::slug($segment));
                                            $categoriesCreated++;
                                        }
                                        $categoryCache[$cacheKey] = $cat->id;
                                    }
                                    $parentId = $categoryCache[$cacheKey];
                                    $categoryIds[] = $parentId;
                                }
                            } else {
                                $cacheKey = $catEntry.'_p_0';
                                if (! isset($categoryCache[$cacheKey])) {
                                    $cat = Category::query()->whereHas('translations', fn ($q) => $q->where('name', $catEntry))->first();
                                    if (! $cat) {
                                        $cat = Category::query()->create(['is_enabled' => true]);
                                        CategoryTranslation::query()->create([
                                            'category_id' => $cat->id,
                                            'language_code' => $locale,
                                            'name' => $catEntry,
                                            'slug' => Str::slug($catEntry),
                                        ]);
                                        $this->urls->sync('category', (int) $cat->id, $locale, Str::slug($catEntry));
                                        $categoriesCreated++;
                                    }
                                    $categoryCache[$cacheKey] = $cat->id;
                                }
                                $categoryIds[] = $categoryCache[$cacheKey];
                            }
                        }

                        $categoryIds = array_values(array_unique($categoryIds));
                        if (! empty($categoryIds)) {
                            $product->categories()->syncWithoutDetaching($categoryIds);
                            if (! $product->default_category_id) {
                                $product->update(['default_category_id' => $categoryIds[0]]);
                            }
                        }
                    }

                    // 5. Images assignment
                    if (! empty($mapped['images'])) {
                        $firstImage = $mapped['images'][0];
                        if (! $product->cover_path && filled($firstImage)) {
                            $product->update(['cover_path' => $firstImage]);
                        }
                        foreach ($mapped['images'] as $pos => $imgPath) {
                            if (filled($imgPath)) {
                                ProductImage::query()->firstOrCreate(
                                    ['product_id' => $product->id, 'path' => $imgPath],
                                    ['alt' => $mapped['name'], 'position' => $pos + 1]
                                );
                            }
                        }
                    }
                });
            } catch (\Throwable $e) {
                $errors[] = "Row {$lineNum}: {$e->getMessage()}";
            }
        }

        return [
            'platform' => $platform,
            'total_rows' => count($rows),
            'products_created' => $created,
            'products_updated' => $updated,
            'categories_created' => $categoriesCreated,
            'errors' => $errors,
        ];
    }

    /**
     * Normalize platform-specific row into standard eCommerce catalog structure.
     *
     * @param  array<string, string>  $row
     * @return array{
     *     sku: string,
     *     name: string,
     *     slug: string,
     *     description: string,
     *     short_description: string,
     *     price: float,
     *     compare_price: ?float,
     *     cost_price: ?float,
     *     stock_qty: int,
     *     is_enabled: bool,
     *     brand: string,
     *     categories: list<string>,
     *     images: list<string>,
     *     weight: float
     * }
     */
    protected function mapRow(array $row, string $platform): array
    {
        return match ($platform) {
            'store_csv' => $this->mapStoreCsvRow($row),
            'catalog_csv' => $this->mapCatalogCsvRow($row),
            default => $this->mapUniversalRow($row),
        };
    }

    /**
     * @param  array<string, string>  $row
     */
    protected function mapStoreCsvRow(array $row): array
    {
        $sku = trim((string) ($row['SKU'] ?? $row['sku'] ?? ''));
        if ($sku === '') {
            $sku = 'IMP-'.($row['ID'] ?? uniqid());
        }

        $name = trim((string) ($row['Name'] ?? $row['name'] ?? ''));
        $regularPrice = (float) ($row['Regular price'] ?? $row['regular_price'] ?? 0);
        $salePrice = isset($row['Sale price']) && trim((string) $row['Sale price']) !== ''
            ? (float) $row['Sale price']
            : null;

        $price = $salePrice !== null && $salePrice > 0 ? $salePrice : $regularPrice;
        $comparePrice = $salePrice !== null && $salePrice > 0 ? $regularPrice : null;

        $stock = (int) ($row['Stock'] ?? 0);
        if ($stock === 0 && in_array(strtolower((string) ($row['In stock?'] ?? '')), ['1', 'yes', 'instock'], true)) {
            $stock = 100;
        }

        $catsRaw = (string) ($row['Categories'] ?? '');
        $categories = array_values(array_unique(array_filter(
            array_map('trim', explode(',', $catsRaw)),
            fn ($c) => $c !== ''
        )));

        $imagesRaw = (string) ($row['Images'] ?? '');
        $images = array_values(array_unique(array_filter(
            array_map('trim', explode(',', $imagesRaw)),
            fn ($img) => $img !== ''
        )));

        return [
            'sku' => $sku,
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => (string) ($row['Description'] ?? ''),
            'short_description' => (string) ($row['Short description'] ?? ''),
            'price' => $price,
            'compare_price' => $comparePrice,
            'cost_price' => null,
            'stock_qty' => $stock,
            'is_enabled' => (string) ($row['Published'] ?? '1') !== '0',
            'brand' => (string) ($row['Brand'] ?? $row['Manufacturer'] ?? ''),
            'categories' => $categories,
            'images' => $images,
            'weight' => (float) ($row['Weight (kg)'] ?? 0),
        ];
    }

    /**
     * @param  array<string, string>  $row
     */
    protected function mapCatalogCsvRow(array $row): array
    {
        $sku = trim((string) ($row['Reference #'] ?? $row['reference'] ?? ''));
        if ($sku === '') {
            $sku = 'IMP-'.($row['Product ID'] ?? uniqid());
        }

        $name = trim((string) ($row['Name *'] ?? $row['name'] ?? ''));
        $priceExcl = (float) ($row['Price tax excluded'] ?? $row['Price tax included'] ?? 0);
        $wholesale = (float) ($row['Wholesale price'] ?? 0);

        $catsRaw = (string) ($row['Categories (x,y,z...)'] ?? $row['Categories'] ?? '');
        $categories = array_values(array_unique(array_filter(
            array_map('trim', explode(',', $catsRaw)),
            fn ($c) => $c !== ''
        )));

        $imagesRaw = (string) ($row['Image URLs (x,y,z...)'] ?? '');
        $images = array_values(array_unique(array_filter(
            array_map('trim', explode(',', $imagesRaw)),
            fn ($img) => $img !== ''
        )));

        $slug = trim((string) ($row['Rewritten URL'] ?? ''));
        if ($slug === '') {
            $slug = Str::slug($name);
        }

        return [
            'sku' => $sku,
            'name' => $name,
            'slug' => $slug,
            'description' => (string) ($row['Description'] ?? ''),
            'short_description' => (string) ($row['Summary'] ?? ''),
            'price' => $priceExcl,
            'compare_price' => null,
            'cost_price' => $wholesale > 0 ? $wholesale : null,
            'stock_qty' => (int) ($row['Quantity'] ?? 0),
            'is_enabled' => (string) ($row['Active (0/1)'] ?? '1') === '1',
            'brand' => (string) ($row['Manufacturer'] ?? ''),
            'categories' => $categories,
            'images' => $images,
            'weight' => (float) ($row['Weight'] ?? 0),
        ];
    }

    /**
     * @param  array<string, string>  $row
     */
    protected function mapUniversalRow(array $row): array
    {
        $sku = trim((string) ($row['sku'] ?? $row['SKU'] ?? $row['Reference'] ?? ''));
        if ($sku === '') {
            $sku = 'GEN-'.uniqid();
        }

        $name = trim((string) ($row['name'] ?? $row['Name'] ?? $row['Title'] ?? ''));
        $price = (float) ($row['price'] ?? $row['Price'] ?? 0);
        $stock = (int) ($row['stock_qty'] ?? $row['Stock'] ?? $row['quantity'] ?? 0);

        $catsRaw = (string) ($row['category'] ?? $row['categories'] ?? $row['Categories'] ?? '');
        $categories = array_values(array_unique(array_filter(
            array_map('trim', explode(',', $catsRaw)),
            fn ($c) => $c !== ''
        )));

        $imagesRaw = (string) ($row['image'] ?? $row['images'] ?? $row['Images'] ?? '');
        $images = array_values(array_unique(array_filter(
            array_map('trim', explode(',', $imagesRaw)),
            fn ($img) => $img !== ''
        )));

        return [
            'sku' => $sku,
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => (string) ($row['description'] ?? $row['Description'] ?? ''),
            'short_description' => (string) ($row['short_description'] ?? $row['Summary'] ?? ''),
            'price' => $price,
            'compare_price' => null,
            'cost_price' => null,
            'stock_qty' => $stock,
            'is_enabled' => (string) ($row['is_enabled'] ?? '1') !== '0',
            'brand' => (string) ($row['brand'] ?? $row['Brand'] ?? ''),
            'categories' => $categories,
            'images' => $images,
            'weight' => (float) ($row['weight'] ?? 0),
        ];
    }
}
