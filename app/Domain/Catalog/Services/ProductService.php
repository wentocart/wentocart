<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\ProductTranslation;
use App\Domain\Catalog\Support\CatalogCsvImport;
use App\Domain\Catalog\Support\CatalogSpreadsheet;
use App\Domain\Catalog\Support\ProductCsvSchema;
use App\Domain\Catalog\Support\ProductModeConflict;
use App\Domain\Security\CsvSafe;
use App\Domain\Security\RasterImageUpload;
use App\Domain\Security\SafeUrl;
use App\Domain\Localization\Models\Language;
use App\Domain\Settings\ObjectStorageSettings;
use App\Domain\Settings\ShopTools;
use App\Domain\Subscription\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductService
{
    public function __construct(
        protected UrlRewriteService $urls,
        protected InventoryService $inventory,
        protected ImageOptimizer $images,
        protected CustomizationService $customizations,
        protected SubscriptionService $subscriptions,
        protected CompositionService $composition,
        protected CombinationService $combinations,
        protected GiftCardService $giftCards,
        protected ServiceProductService $serviceProducts,
        protected RentalProductService $rentalProducts,
        protected PreorderService $preorders,
    ) {}

    public function save(array $data, ?Product $product = null): Product
    {
        return DB::transaction(function () use ($data, $product) {
            wento_action('catalog.product.save', 'before', [
                'id' => $product?->id,
                'sku' => $data['product']['sku'] ?? $product?->sku,
            ]);
            $previousQty = $product?->exists ? (float) $product->stock_qty : 0;
            $subscriptionEnabled = array_key_exists('subscription_enabled', $data)
                ? (bool) $data['subscription_enabled']
                : (bool) ($data['product']['subscription_enabled'] ?? $product?->subscription_enabled ?? false);
            $preorderEnabled = array_key_exists('preorder_enabled', $data)
                ? (bool) $data['preorder_enabled']
                : (bool) ($data['product']['preorder_enabled'] ?? $product?->preorder_enabled ?? false);
            ProductModeConflict::assertExclusive($subscriptionEnabled, $preorderEnabled);

            $product ??= new Product;
            $product->fill($data['product']);
            $product->save();

            if (array_key_exists('category_ids', $data)) {
                $product->categories()->sync($this->categorySync($data['category_ids'] ?? [], $data['product']['default_category_id'] ?? null));
            }
            $translations = $this->syncTranslations($product, $data);
            $locale = Language::defaultCode();
            $translation = $translations[$locale]
                ?? $product->translations()->where('language_code', $locale)->first()
                ?? $product->translation($locale);
            if (array_key_exists('tag_ids', $data)) {
                $product->tags()->sync($data['tag_ids'] ?? []);
            }
            $this->syncSuppliers($product, $data);

            if (isset($data['relations'])) {
                $product->relationsOfType('related')->sync($this->relationSync($data['relations']['related'] ?? [], 'related'));
                DB::table('product_relations')->where('product_id', $product->id)->delete();
                foreach (['related', 'upsell', 'crosssell'] as $type) {
                    foreach ($data['relations'][$type] ?? [] as $relatedId) {
                        DB::table('product_relations')->insert([
                            'product_id' => $product->id,
                            'related_product_id' => (int) $relatedId,
                            'type' => $type,
                        ]);
                    }
                }
            }

            if (isset($data['feature_value_ids'])) {
                DB::table('product_feature_values')->where('product_id', $product->id)->delete();
                foreach ($data['feature_value_ids'] as $row) {
                    DB::table('product_feature_values')->insert([
                        'product_id' => $product->id,
                        'feature_id' => $row['feature_id'],
                        'feature_value_id' => $row['feature_value_id'],
                    ]);
                }
            }

            if (array_key_exists('components', $data)) {
                $this->composition->sync($product, $data['components'] ?? []);
                $product->refresh();
                $pricingMode = $product->pricing_mode ?: ($product->type === 'bundle' ? 'fixed' : 'sum');
                if ($product->isComposite() && $pricingMode !== 'fixed') {
                    $product->update(['price' => $this->composition->sellingPrice($product)]);
                }
            }

            if ($product->isCombination() && array_key_exists('combination_axes', $data)) {
                $this->combinations->syncProduct($product, $data);
            }

            if ($product->isGiftCard() && array_key_exists('gift_card', $data)) {
                $this->giftCards->syncProduct(
                    $product,
                    $data['gift_card'] ?? [],
                    $data['gift_card_amounts'] ?? [],
                );
            }

            if ($product->isService() && array_key_exists('service', $data)) {
                $this->serviceProducts->syncProduct($product, $data['service'] ?? []);
            }

            if (in_array($product->type, ['virtual', 'gift_card', 'service'], true)) {
                $product->update(['manage_stock' => false]);
            }

            if ($product->isRental() && array_key_exists('rental', $data)) {
                $this->rentalProducts->syncProduct(
                    $product,
                    $data['rental'] ?? [],
                    $data['rental_pricing_tiers'] ?? [],
                    $data['rental_assets'] ?? [],
                );
            }

            if (array_key_exists('preorder_enabled', $data) || array_key_exists('preorder', $data)) {
                $this->preorders->syncProduct(
                    $product,
                    (bool) ($data['preorder_enabled'] ?? false),
                    $data['preorder'] ?? [],
                );
            }

            $derivedInventory = $product->isComposite() && ($product->inventory_mode ?: 'derived') === 'derived';
            if (! $derivedInventory) {
                $this->inventory->syncFromForm($product, $data, $previousQty);
            }

            if (array_key_exists('qty_prices', $data)) {
                $this->syncQtyPrices($product, is_array($data['qty_prices']) ? $data['qty_prices'] : []);
            }

            if (array_key_exists('available_all_carriers', $data) || array_key_exists('shipping_method_ids', $data)) {
                $all = (bool) ($data['available_all_carriers'] ?? true);
                $ids = $all ? [] : array_values(array_map('intval', $data['shipping_method_ids'] ?? []));
                $product->shippingMethods()->sync($ids);
            }

            if (array_key_exists('customization_fields', $data) || array_key_exists('customization_enabled', $data)) {
                $this->customizations->sync(
                    $product,
                    (bool) ($data['customization_enabled'] ?? false),
                    $data['customization_fields'] ?? [],
                );
            }

            if (array_key_exists('subscription_plans', $data) || array_key_exists('subscription_enabled', $data)) {
                $this->subscriptions->syncProduct(
                    $product,
                    (bool) ($data['subscription_enabled'] ?? false),
                    (string) ($data['purchase_option'] ?? 'one_time'),
                    $data['subscription_plans'] ?? [],
                );
            }

            wento_hook('catalog.product.saved', ['product' => $product]);
            wento_action('catalog.product.save', 'after', ['id' => $product->id]);
            app(PriceHistoryRecorder::class)->record($product);
            app(\App\Domain\Search\CatalogSearchIndex::class)->upsert($product->fresh(['translations']));

            return $product->fresh(['translations', 'categories', 'images', 'suppliers', 'customizationFields']);
        });
    }

    public function delete(Product $product): void
    {
        wento_action('catalog.product.delete', 'before', ['id' => $product->id, 'sku' => $product->sku]);
        $id = (int) $product->id;
        $product->delete();
        app(\App\Domain\Search\CatalogSearchIndex::class)->delete($id);
        wento_action('catalog.product.delete', 'after', ['id' => $id]);
    }

    public function duplicate(Product $product): Product
    {
        $product->loadMissing([
            'translations', 'categories', 'tags', 'suppliers', 'warehouseStocks', 'qtyPrices', 'shippingMethods',
            'components', 'combinationAxes', 'combinationAxisValues', 'variants.attributeValues',
        ]);
        $copy = $product->replicate(['sku', 'cover_path']);
        $copy->sku = $product->sku.'-copy-'.Str::lower(Str::random(4));
        $copy->is_enabled = false;
        $copy->save();

        $copy->categories()->sync($product->categories->pluck('id'));

        foreach ($product->translations as $translation) {
            $new = $translation->replicate();
            $new->product_id = $copy->id;
            $new->slug = $translation->slug.'-copy-'.Str::lower(Str::random(4));
            $new->name = $translation->name.' (copy)';
            $new->save();
            $this->urls->sync(
                'product',
                $copy->id,
                $new->language_code,
                $new->slug,
                null,
                $this->urls->productTokens($copy->fresh(['categories.translations']), $new->language_code, $new->slug)
            );
        }

        $copy->tags()->sync($product->tags->pluck('id'));
        $this->syncSuppliers($copy, [
            'supplier_ids' => $product->suppliers->pluck('id')->all(),
            'product' => ['supplier_id' => $product->supplier_id],
        ]);

        foreach ($product->warehouseStocks as $stock) {
            $copy->warehouseStocks()->create([
                'warehouse_id' => $stock->warehouse_id,
                'qty' => $stock->qty,
                'reserved_qty' => 0,
            ]);
        }

        foreach ($product->qtyPrices as $row) {
            $copy->qtyPrices()->create([
                'min_qty' => $row->min_qty,
                'max_qty' => $row->max_qty,
                'price' => $row->price,
                'position' => $row->position,
            ]);
        }

        $copy->shippingMethods()->sync($product->shippingMethods->pluck('id'));
        $this->customizations->duplicate($product->loadMissing('customizationFields'), $copy);
        $this->subscriptions->duplicateProduct($product->loadMissing('productSubscriptionPlans'), $copy);

        foreach ($product->components as $row) {
            $copy->components()->create([
                'child_product_id' => $row->child_product_id,
                'quantity' => $row->quantity,
                'min_quantity' => $row->min_quantity,
                'max_quantity' => $row->max_quantity,
                'qty_editable' => $row->qty_editable,
                'is_required' => $row->is_required,
                'default_selected' => $row->default_selected,
                'option_group' => $row->option_group,
                'position' => $row->position,
            ]);
        }

        if ($product->isCombination()) {
            foreach ($product->combinationAxes as $axis) {
                $copy->combinationAxes()->create([
                    'attribute_id' => $axis->attribute_id,
                    'position' => $axis->position,
                ]);
            }
            foreach ($product->combinationAxisValues as $row) {
                DB::table('product_combination_axis_values')->insert([
                    'product_id' => $copy->id,
                    'attribute_id' => $row->attribute_id,
                    'attribute_value_id' => $row->attribute_value_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            foreach ($product->variants as $variant) {
                $newVariant = $copy->variants()->create([
                    'combination_key' => $variant->combination_key,
                    'sku' => $variant->sku ? $variant->sku.'-copy' : null,
                    'barcode' => $variant->barcode,
                    'price' => $variant->price,
                    'sale_price' => $variant->sale_price,
                    'sale_price_type' => $variant->sale_price_type,
                    'cost_price' => $variant->cost_price,
                    'stock_qty' => 0,
                    'manage_stock' => $variant->manage_stock,
                    'stock_status' => $variant->stock_status,
                    'allow_backorder' => $variant->allow_backorder,
                    'weight' => $variant->weight,
                    'length' => $variant->length,
                    'width' => $variant->width,
                    'height' => $variant->height,
                    'cover_path' => $variant->cover_path,
                    'is_enabled' => $variant->is_enabled,
                    'is_default' => $variant->is_default,
                    'position' => $variant->position,
                ]);
                $newVariant->attributeValues()->sync($variant->attributeValues->pluck('id'));
            }
        }

        return $copy;
    }

    public function storeImage(Product $product, UploadedFile $file, ?string $alt = null, bool $cover = false): ProductImage
    {
        $path = RasterImageUpload::storePublic(
            $file,
            'catalog/products/'.$product->id,
            (int) config('wento.upload.max_image_kb', 5120)
        );

        $image = ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => $path,
            'alt' => $alt,
            'position' => (int) $product->images()->max('position') + 1,
        ]);

        if ($cover || ! $product->cover_path) {
            $product->update(['cover_path' => $path]);
        }

        $this->images->generate($path);

        return $image;
    }

    public function deleteImage(Product $product, ProductImage $image): void
    {
        if ((int) $image->product_id !== (int) $product->id) {
            abort(404);
        }

        $wasCover = $product->cover_path === $image->path;
        $path = (string) $image->path;

        $disk = ObjectStorageSettings::disk();
        $disk->delete($path);
        foreach (ShopTools::imageTypes() as $type) {
            $derived = $this->images->derivativePath($path, (string) $type['id']);
            if ($derived !== $path) {
                $disk->delete($derived);
            }
        }

        $image->delete();

        if ($wasCover) {
            $next = $product->images()->orderBy('position')->orderBy('id')->first();
            $product->update(['cover_path' => $next?->path]);
        }
    }

    public function reorderImages(Product $product, array $ids): void
    {
        $owned = $product->images()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $position = 1;
        foreach ($ids as $id) {
            $id = (int) $id;
            if (! in_array($id, $owned, true)) {
                continue;
            }
            ProductImage::query()->whereKey($id)->where('product_id', $product->id)->update(['position' => $position]);
            $position++;
        }
    }

    public function csvColumns(): array
    {
        return ProductCsvSchema::columns();
    }

    public function sampleCsv(
        string $delimiter = ProductCsvSchema::DEFAULT_DELIMITER,
        string $multiSeparator = ProductCsvSchema::DEFAULT_MULTI_SEPARATOR,
    ): string {
        return ProductCsvSchema::sampleCsv($delimiter, $multiSeparator);
    }

    public function importCsv(
        string $contents,
        string $locale = 'en',
        string $delimiter = ProductCsvSchema::DEFAULT_DELIMITER,
        string $multiSeparator = ProductCsvSchema::DEFAULT_MULTI_SEPARATOR,
    ): int {
        $delimiter = $this->normalizeCsvDelimiter($delimiter);
        $multiSeparator = $this->normalizeCsvMultiSeparator($multiSeparator);

        $contents = CatalogSpreadsheet::toCsv($contents);
        $delimiter = CatalogCsvImport::detectDelimiter($contents, $delimiter);
        $parsedFile = CatalogCsvImport::parse($contents, $delimiter);
        CatalogCsvImport::assertHeaders(
            $parsedFile['header'],
            ProductCsvSchema::columns(),
            'Product',
            ProductCsvSchema::requiredColumns(),
        );
        CatalogCsvImport::assertHasDataRows($parsedFile['rows'], 'Product');

        $errors = [];
        $parsed = [];
        foreach ($parsedFile['rows'] as $index => $row) {
            $rowNumber = CatalogCsvImport::rowNumber($index);
            $sku = trim((string) ($row['sku'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            if ($sku === '') {
                $errors[] = "Row {$rowNumber}: Product SKU is required.";
                continue;
            }
            if ($name === '') {
                $errors[] = "Row {$rowNumber}: Product name is required.";
                continue;
            }

            $parsed[] = [
                'row' => $row,
                'mapped' => ProductCsvSchema::mapRow($row, $locale, $multiSeparator),
            ];
        }

        CatalogCsvImport::throwRowErrors($errors);
        if ($parsed === []) {
            throw ValidationException::withMessages([
                'file' => 'Invalid CSV file. No valid Product rows to import.',
            ]);
        }

        return DB::transaction(function () use ($parsed, $locale, $multiSeparator) {
            $count = 0;
            $pendingImages = [];

            foreach ($parsed as $item) {
                $existing = Product::query()->where('sku', $item['mapped']['product']['sku'])->first();
                $mapped = $existing
                    ? $this->omitBlankCsvValues($item['mapped'], $item['row'])
                    : $this->applyNewProductCsvDefaults($item['mapped']);
                $payload = $this->csvBasePayload($item['row'], $mapped);
                $product = $this->save($payload, $existing);
                $this->applyCsvTypeExtras($product, $item['row'], $mapped);

                if (array_key_exists('images', $item['row']) && $mapped['image_urls'] !== []) {
                    $pendingImages[$product->id] = $mapped['image_urls'];
                }

                wento_action('catalog.product.imported', 'after', [
                    'product' => $product,
                    'row' => $item['row'],
                    'locale' => $locale,
                ]);

                $count++;
            }

            foreach ($parsed as $item) {
                $mapped = ProductCsvSchema::mapRow($item['row'], $locale, $multiSeparator);
                $product = Product::query()->where('sku', $mapped['product']['sku'])->first();
                if (! $product) {
                    continue;
                }

                $deferred = ['product' => ['sku' => $product->sku, 'type' => $product->type]];
                $hasDeferred = false;

                if (array_key_exists('components', $item['row']) && $mapped['components'] !== []) {
                    $deferred['components'] = $mapped['components'];
                    $hasDeferred = true;
                }
                if (
                    array_key_exists('related_skus', $item['row'])
                    || array_key_exists('upsell_skus', $item['row'])
                    || array_key_exists('crosssell_skus', $item['row'])
                ) {
                    $deferred['relations'] = $mapped['relations'];
                    $hasDeferred = true;
                }

                if ($hasDeferred) {
                    $this->save($deferred, $product);
                }
            }

            foreach ($pendingImages as $productId => $urls) {
                $product = Product::query()->find($productId);
                if (! $product) {
                    continue;
                }
                $this->importImagesFromUrls($product, $urls);
            }

            return $count;
        });
    }

    public function exportCsv(string $locale = 'en', ?Request $request = null): string
    {
        $delimiter = $this->normalizeCsvDelimiter(
            (string) ($request?->input('delimiter') ?: ProductCsvSchema::DEFAULT_DELIMITER)
        );
        $multiSeparator = $this->normalizeCsvMultiSeparator(
            (string) ($request?->input('multivalue_separator') ?: ProductCsvSchema::DEFAULT_MULTI_SEPARATOR)
        );

        $out = fopen('php://temp', 'r+');
        CsvSafe::put($out, $this->csvColumns(), $delimiter);

        $status = $request?->string('status')->toString() ?: 'all';
        $search = trim((string) ($request?->string('q')->toString() ?: $request?->string('search')->toString() ?: ''));
        $name = trim((string) ($request?->string('name')->toString() ?: ''));
        $sku = trim((string) ($request?->string('sku')->toString() ?: ''));
        $category = trim((string) ($request?->string('category')->toString() ?: ''));
        $idMin = $request?->input('id_min');
        $idMax = $request?->input('id_max');
        $priceMin = $request?->input('price_min');
        $priceMax = $request?->input('price_max');
        $qtyMin = $request?->input('qty_min');
        $qtyMax = $request?->input('qty_max');

        $query = Product::query()
            ->with([
                'translations',
                'brand.translations',
                'taxClass',
                'categories.translations',
                'suppliers.translations',
                'tags.translations',
                'featureValues.translations',
                'featureValues.feature.translations',
                'images',
                'shippingMethods',
                'components.child',
                'giftCardAmounts',
                'serviceSetting',
                'rentalSetting',
                'rentalPricingTiers',
                'preorderSetting',
            ])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('sku', 'like', "%{$search}%")
                        ->orWhereHas('translations', fn ($t) => $t->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($name !== '', function ($query) use ($name) {
                $query->whereHas('translations', fn ($t) => $t->where('name', 'like', "%{$name}%"));
            })
            ->when($sku !== '', fn ($query) => $query->where('sku', 'like', "%{$sku}%"))
            ->when($category !== '', function ($query) use ($category) {
                $query->whereHas('categories.translations', fn ($t) => $t->where('name', 'like', "%{$category}%"));
            })
            ->when($idMin !== null && $idMin !== '', fn ($query) => $query->where('products.id', '>=', (int) $idMin))
            ->when($idMax !== null && $idMax !== '', fn ($query) => $query->where('products.id', '<=', (int) $idMax))
            ->when($priceMin !== null && $priceMin !== '', fn ($query) => $query->where('products.price', '>=', (float) $priceMin))
            ->when($priceMax !== null && $priceMax !== '', fn ($query) => $query->where('products.price', '<=', (float) $priceMax))
            ->when($qtyMin !== null && $qtyMin !== '', fn ($query) => $query->where('products.stock_qty', '>=', (int) $qtyMin))
            ->when($qtyMax !== null && $qtyMax !== '', fn ($query) => $query->where('products.stock_qty', '<=', (int) $qtyMax))
            ->when($status === 'active', fn ($q) => $q->where('products.is_enabled', true))
            ->when($status === 'inactive', fn ($q) => $q->where('products.is_enabled', false))
            ->when($status === 'out_of_stock', fn ($q) => $q->where('products.stock_qty', '<=', 0));

        $query->orderBy('products.id')->chunk(100, function ($products) use ($out, $locale, $delimiter, $multiSeparator) {
            foreach ($products as $product) {
                CsvSafe::put($out, ProductCsvSchema::exportValues($product, $locale, $multiSeparator), $delimiter);
            }
        });

        rewind($out);

        return stream_get_contents($out) ?: '';
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $mapped
     * @return array<string, mixed>
     */
    /**
     * Re-import: empty cells keep the live product values.
     *
     * @param  array<string, mixed>  $mapped
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    protected function omitBlankCsvValues(array $mapped, array $row): array
    {
        $blank = static fn (string $key): bool => ! array_key_exists($key, $row) || trim((string) $row[$key]) === '';

        foreach (array_keys($mapped['product'] ?? []) as $field) {
            if ($field === 'sku') {
                continue;
            }
            $csvKey = match ($field) {
                'brand_id' => 'brand',
                'tax_class_id' => 'tax_class',
                'default_category_id', 'supplier_id' => $field === 'default_category_id' ? 'categories' : 'suppliers',
                default => $field,
            };
            if ($blank($csvKey)) {
                unset($mapped['product'][$field]);
            }
        }

        foreach (array_keys($mapped['translation'] ?? []) as $field) {
            if (in_array($field, ['language_code', 'name'], true)) {
                continue;
            }
            if ($blank($field)) {
                unset($mapped['translation'][$field]);
            }
        }

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $mapped
     * @return array<string, mixed>
     */
    protected function applyNewProductCsvDefaults(array $mapped): array
    {
        $mapped['product']['type'] = $mapped['product']['type'] ?? 'simple';
        $mapped['product']['price'] = $mapped['product']['price'] ?? 0;
        $mapped['product']['stock_qty'] = $mapped['product']['stock_qty'] ?? 0;
        $mapped['product']['is_enabled'] = $mapped['product']['is_enabled'] ?? true;
        $mapped['product']['visibility'] = $mapped['product']['visibility'] ?? 'both';
        $mapped['product']['available_for_order'] = $mapped['product']['available_for_order'] ?? true;
        $mapped['product']['show_price'] = $mapped['product']['show_price'] ?? true;

        return $mapped;
    }

    /** @return array<string, true> */
    protected function existingImageBasenames(Product $product): array
    {
        $names = [];
        foreach ($product->images as $image) {
            $base = strtolower(basename((string) $image->path));
            if ($base !== '') {
                $names[$base] = true;
            }
        }
        $cover = strtolower(basename((string) ($product->cover_path ?? '')));
        if ($cover !== '') {
            $names[$cover] = true;
        }

        return $names;
    }

    protected function imageUrlBasename(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        return strtolower(basename(is_string($path) ? $path : ''));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function csvCellFilled(array $row, string $key): bool
    {
        return array_key_exists($key, $row) && trim((string) $row[$key]) !== '';
    }

    protected function csvBasePayload(array $row, array $mapped): array
    {
        $payload = [
            'product' => $mapped['product'],
            'translation' => $mapped['translation'],
        ];

        if ($this->csvCellFilled($row, 'categories')) {
            $payload['category_ids'] = $mapped['category_ids'];
        }
        if ($this->csvCellFilled($row, 'suppliers')) {
            $payload['supplier_ids'] = $mapped['supplier_ids'];
        }
        if ($this->csvCellFilled($row, 'tags')) {
            $payload['tag_ids'] = $mapped['tag_ids'];
        }
        if ($this->csvCellFilled($row, 'features')) {
            $payload['feature_value_ids'] = $mapped['feature_value_ids'];
        }
        if (array_key_exists('available_all_carriers', $row) || array_key_exists('shipping_methods', $row)) {
            $payload['available_all_carriers'] = $mapped['available_all_carriers'];
            $payload['shipping_method_ids'] = $mapped['shipping_method_ids'];
        }
        if (array_key_exists('preorder_enabled', $row) || array_key_exists('preorder_payment_type', $row) || ! empty($mapped['preorder'])) {
            $payload['preorder_enabled'] = (bool) ($mapped['preorder_enabled'] ?? false);
            if ($payload['preorder_enabled']) {
                $payload['preorder'] = is_array($mapped['preorder'] ?? null)
                    ? $mapped['preorder']
                    : [
                        'payment_type' => 'full',
                        'min_quantity' => 1,
                    ];
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $mapped
     */
    protected function applyCsvTypeExtras(Product $product, array $row, array $mapped): void
    {
        try {
            if ($product->isGiftCard() && array_key_exists('gift_card_amounts', $row) && $mapped['gift_card']) {
                $this->giftCards->syncProduct($product, $mapped['gift_card'], $mapped['gift_card_amounts']);
            }

            if ($product->isService() && $mapped['service']) {
                $this->serviceProducts->syncProduct($product, $mapped['service']);
            }

            if ($product->isRental() && $mapped['rental']) {
                $this->rentalProducts->syncProduct(
                    $product,
                    $mapped['rental'],
                    $mapped['rental_pricing_tiers'] ?? [],
                    []
                );
            }

            if (
                $product->isCombination()
                && array_key_exists('attributes', $row)
                && $mapped['combination_axes'] !== []
            ) {
                $this->combinations->syncProduct($product, [
                    'combination_axes' => $mapped['combination_axes'],
                    'combination_variants' => $mapped['combination_variants'],
                    'remove_missing_combinations' => true,
                ]);
            }
        } catch (\Throwable) {
            // Keep the base product row; skip type-specific extras that cannot be resolved.
        }
    }

    protected function normalizeCsvDelimiter(string $delimiter): string
    {
        $delimiter = $delimiter === '' ? ProductCsvSchema::DEFAULT_DELIMITER : $delimiter;
        if (strlen($delimiter) !== 1) {
            return ProductCsvSchema::DEFAULT_DELIMITER;
        }

        return $delimiter;
    }

    protected function normalizeCsvMultiSeparator(string $separator): string
    {
        return $separator === '' ? ProductCsvSchema::DEFAULT_MULTI_SEPARATOR : $separator;
    }

    /**
     * @param  list<string>  $urls
     */
    protected function importImagesFromUrls(Product $product, array $urls): void
    {
        $isFirst = $product->images()->count() === 0;
        $known = $this->existingImageBasenames($product);
        foreach ($urls as $url) {
            if (! filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }
            $basename = $this->imageUrlBasename($url);
            if ($basename !== '' && isset($known[$basename])) {
                continue;
            }

            try {
                $response = SafeUrl::get($url, 8, false);
                if (! $response->successful()) {
                    continue;
                }
                $binary = $response->body();
                if ($binary === '' || strlen($binary) > 5_000_000) {
                    continue;
                }

                $tmp = tempnam(sys_get_temp_dir(), 'wento-csv-img-');
                if ($tmp === false) {
                    continue;
                }
                file_put_contents($tmp, $binary);
                $name = basename(parse_url($url, PHP_URL_PATH) ?: 'image.jpg') ?: 'image.jpg';
                $file = new UploadedFile($tmp, $name, $response->header('Content-Type') ?: null, null, true);
                $this->storeImage($product, $file, null, $isFirst);
                $isFirst = false;
                if ($basename !== '') {
                    $known[$basename] = true;
                }
                @unlink($tmp);
            } catch (\Throwable) {
                // Skip unreachable or invalid image URLs without failing the whole import.
            }
        }
    }

    protected function syncSuppliers(Product $product, array $data): void
    {
        if (! array_key_exists('supplier_ids', $data) && ! array_key_exists('supplier_id', $data['product'] ?? [])) {
            return;
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $data['supplier_ids'] ?? []))));
        $default = (int) ($data['product']['supplier_id'] ?? 0);

        if ($default > 0 && ! in_array($default, $ids, true)) {
            array_unshift($ids, $default);
        }

        if ($default <= 0) {
            $default = $ids[0] ?? 0;
        }

        $sync = [];
        foreach ($ids as $id) {
            $sync[$id] = [
                'is_default' => $id === $default,
                'supplier_reference' => null,
                'wholesale_price' => null,
            ];
        }

        $product->suppliers()->sync($sync);
        $product->update(['supplier_id' => $default > 0 ? $default : null]);
    }

    protected function categorySync(array $ids, mixed $defaultId): array
    {
        $sync = [];
        foreach ($ids as $id) {
            $sync[$id] = ['is_default' => (int) $id === (int) $defaultId];
        }

        return $sync;
    }

    protected function relationSync(array $ids, string $type): array
    {
        $sync = [];
        foreach ($ids as $id) {
            $sync[$id] = ['type' => $type];
        }

        return $sync;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, ProductTranslation>
     */
    protected function syncTranslations(Product $product, array $data): array
    {
        $default = Language::defaultCode();
        $rows = $data['translations'] ?? [];
        if ($rows === [] && isset($data['translation'])) {
            $translation = (array) $data['translation'];
            $language = (string) ($translation['language_code'] ?? $default);
            $rows = [$language => $translation];
        }

        $saved = [];
        $categoriesLoaded = $product->fresh(['categories.translations']);
        foreach ($rows as $key => $row) {
            $row = (array) $row;
            $language = (string) ($row['language_code'] ?? $key);
            if ($language === '') {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            $isBlank = $this->translationRowBlank($row);
            if ($language !== $default && $isBlank) {
                DB::table('url_rewrites')
                    ->where('entity_type', 'product')
                    ->where('entity_id', $product->id)
                    ->where('language_code', $language)
                    ->delete();
                ProductTranslation::query()
                    ->where('product_id', $product->id)
                    ->where('language_code', $language)
                    ->delete();

                continue;
            }

            $existing = ProductTranslation::query()
                ->where('product_id', $product->id)
                ->where('language_code', $language)
                ->first();

            if ($existing) {
                foreach ([
                    'slug', 'short_description', 'description', 'meta_title', 'meta_description',
                    'meta_keywords', 'focus_keyword', 'canonical_url', 'robots', 'subscription_message',
                ] as $field) {
                    if (! array_key_exists($field, $row)) {
                        $row[$field] = $existing->{$field};
                    }
                }
            }

            $slug = $this->uniqueTranslationSlug(
                (string) ($row['slug'] ?? ''),
                $name,
                $language,
                $existing?->id,
            );

            $saved[$language] = $product->translations()->updateOrCreate(
                ['language_code' => $language],
                [
                    'name' => $name,
                    'slug' => $slug,
                    'short_description' => $this->nullableString($row['short_description'] ?? null),
                    'description' => $row['description'] ?? null,
                    'meta_title' => $this->nullableString($row['meta_title'] ?? null),
                    'meta_description' => $this->nullableString($row['meta_description'] ?? null),
                    'meta_keywords' => $this->nullableString($row['meta_keywords'] ?? null),
                    'focus_keyword' => $this->nullableString($row['focus_keyword'] ?? null),
                    'canonical_url' => $this->nullableString($row['canonical_url'] ?? null),
                    'robots' => $row['robots'] ?? 'index,follow',
                    'subscription_message' => $this->nullableString($row['subscription_message'] ?? null),
                ]
            );

            $this->urls->sync(
                'product',
                $product->id,
                $language,
                $slug,
                null,
                $this->urls->productTokens($categoriesLoaded, $language, $slug)
            );
        }

        return $saved;
    }

    protected function translationRowBlank(array $row): bool
    {
        foreach ([
            'name', 'slug', 'short_description', 'description', 'meta_title', 'meta_description',
            'meta_keywords', 'focus_keyword', 'canonical_url', 'subscription_message',
        ] as $field) {
            $value = $row[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return false;
            }
            if (! is_string($value) && $value !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array{min?: mixed, max?: mixed, price?: mixed, min_qty?: mixed, max_qty?: mixed}>  $rows
     */
    protected function syncQtyPrices(Product $product, array $rows): void
    {
        $keep = [];
        $position = 0;
        foreach ($rows as $row) {
            $min = (float) ($row['min_qty'] ?? $row['min'] ?? 0);
            $price = $row['price'] ?? null;
            if ($min <= 0 || $price === null || $price === '') {
                continue;
            }
            $max = $row['max_qty'] ?? $row['max'] ?? null;
            $max = ($max === '' || $max === null) ? null : (float) $max;
            $record = $product->qtyPrices()->updateOrCreate(
                ['min_qty' => $min],
                [
                    'max_qty' => $max && $max >= $min ? $max : null,
                    'price' => round((float) $price, 2),
                    'position' => $position++,
                ]
            );
            $keep[] = $record->id;
        }
        $product->qtyPrices()->when($keep !== [], fn ($q) => $q->whereNotIn('id', $keep))->delete();
        if ($keep === []) {
            $product->qtyPrices()->delete();
        }
    }

    protected function uniqueTranslationSlug(string $slug, string $name, string $locale, ?int $ignoreTranslationId = null): string
    {
        $base = Str::slug($slug !== '' ? $slug : $name) ?: 'product';
        $candidate = $base;
        $i = 2;

        while (ProductTranslation::query()
            ->where('language_code', $locale)
            ->where('slug', $candidate)
            ->when($ignoreTranslationId, fn ($query) => $query->where('id', '!=', $ignoreTranslationId))
            ->exists()) {
            $candidate = "{$base}-{$i}";
            $i++;
        }

        return $candidate;
    }

    protected function nullableString(?string $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
