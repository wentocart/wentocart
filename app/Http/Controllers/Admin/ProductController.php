<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Enums\ProductType;
use App\Domain\Catalog\Models\Attachment;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Feature;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\ProductTranslation;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Catalog\Models\Warehouse;
use App\Domain\Catalog\SaleUnit;
use App\Domain\Catalog\Services\AttachmentService;
use App\Domain\Catalog\Services\AttributeService;
use App\Domain\Catalog\Services\CategoryProductQuery;
use App\Domain\Catalog\Services\CombinationService;
use App\Domain\Catalog\Services\CompositionService;
use App\Domain\Catalog\Services\GiftCardService;
use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Catalog\Services\PreorderService;
use App\Domain\Catalog\Services\ProductService;
use App\Domain\Catalog\Services\RentalProductService;
use App\Domain\Catalog\Services\ServiceProductService;
use App\Domain\Catalog\Services\UrlRewriteService;
use App\Domain\Catalog\Support\CatalogSpreadsheet;
use App\Domain\Catalog\Support\ProductCsvSchema;
use App\Domain\Installer\Models\ShopUrl;
use App\Domain\Localization\Models\Currency;
use App\Domain\Localization\Models\Language;
use App\Domain\Ops\Operator;
use App\Domain\Ops\ShopStats;
use App\Domain\Ops\Timeline;
use App\Domain\Plugin\Support\ModuleBuses;
use App\Domain\Search\PrefixQuery;
use App\Domain\Seo\StorefrontStructuredData;
use App\Domain\Settings\PricingSettings;
use App\Domain\Settings\ProductSettings;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Domain\Store\ShopIdentity;
use App\Domain\Subscription\Models\SubscriptionPlan;
use App\Domain\Subscription\Services\SubscriptionService;
use App\Domain\Tax\Models\TaxClass;
use App\Domain\Tax\TaxEngine;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString() ?: 'all';
        $search = trim($request->string('q')->toString() ?: $request->string('search')->toString());
        $name = trim($request->string('name')->toString());
        $sku = trim($request->string('sku')->toString());
        $category = trim($request->string('category')->toString());
        $idMin = $request->input('id_min');
        $idMax = $request->input('id_max');
        $priceMin = $this->unsignedFilter($request->input('price_min'));
        $priceMax = $this->unsignedFilter($request->input('price_max'));
        $qtyMin = $this->unsignedFilter($request->input('qty_min'));
        $qtyMax = $this->unsignedFilter($request->input('qty_max'));
        $sort = $request->string('sort')->toString() ?: 'id';
        $dir = $request->string('dir')->toString() === 'asc' ? 'asc' : 'desc';
        $allowedSort = ['id', 'name', 'sku', 'category', 'price', 'stock_qty', 'is_enabled', 'created_at'];
        if (! in_array($sort, $allowedSort, true)) {
            $sort = 'id';
        }

        $counts = ShopStats::productTabs();

        $query = Product::query()
            ->with(['translations', 'categories.translations'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    PrefixQuery::applySkuContains($inner, $search);
                    $like = PrefixQuery::contains($search);
                    $inner->orWhereHas('translations', fn ($t) => $t->where('name', 'like', $like));
                });
            })
            ->when($name !== '', function ($query) use ($name) {
                $like = PrefixQuery::contains($name);
                $query->whereHas('translations', fn ($t) => $t->where('name', 'like', $like));
            })
            ->when($sku !== '', function ($query) use ($sku) {
                PrefixQuery::applySkuContains($query, $sku);
            })
            ->when($category !== '', function ($query) use ($category) {
                if (ctype_digit($category)) {
                    CategoryProductQuery::constrain($query, [(int) $category]);

                    return;
                }
                $like = PrefixQuery::contains($category);
                $query->whereHas('categories.translations', fn ($t) => $t->where('name', 'like', $like));
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

        $moduleList = ModuleBuses::applyList('catalog.products', $query);

        if ($sort === 'name') {
            $query->orderBy(
                ProductTranslation::query()
                    ->select('name')
                    ->whereColumn('product_id', 'products.id')
                    ->limit(1),
                $dir
            )->orderBy('products.id');
        } elseif ($sort === 'category') {
            $locale = Language::defaultCode();
            $query->orderBy(
                \App\Domain\Catalog\Models\CategoryTranslation::query()
                    ->select('name')
                    ->join('category_product', 'category_product.category_id', '=', 'category_translations.category_id')
                    ->whereColumn('category_product.product_id', 'products.id')
                    ->where('category_product.is_default', 1)
                    ->where('category_translations.language_code', $locale)
                    ->limit(1),
                $dir
            )->orderBy('products.id');
        } else {
            $query->orderBy('products.'.$sort, $dir)->orderBy('products.id');
        }

        $products = $query
            ->paginate(\App\Support\AdminPagination::perPage(request(), ProductSettings::adminPerPage()))
            ->withQueryString();
        $locale = Language::defaultCode();
        $urls = app(UrlRewriteService::class);
        $products->getCollection()->transform(function (Product $product) use ($moduleList, $locale, $urls) {
            $product->setAttribute('module_values', ModuleBuses::rowValues($product, $moduleList['columns']));
            $defaultCategory = $product->categories->firstWhere('pivot.is_default', true)
                ?? $product->categories->first();
            $product->setAttribute(
                'category_name',
                $defaultCategory?->translation($locale)?->name
                    ?? $defaultCategory?->translation()?->name
            );
            $slug = $product->translation($locale)?->slug
                ?? $product->translation()?->slug;
            $path = $urls->pathFor('product', (int) $product->id, $locale) ?: $slug;
            $product->setAttribute(
                'preview_url',
                $path ? '/'.ltrim((string) $path, '/') : null
            );

            return $product;
        });

        return Inertia::render('Catalog/Products/Index', [
            'products' => $products,
            'counts' => $counts,
            'stats' => Inertia::defer(fn () => ShopStats::productStats()),
            'filters' => [
                'status' => $status,
                'q' => $search,
                'name' => $name,
                'sku' => $sku,
                'category' => $category,
                'id_min' => $idMin !== null && $idMin !== '' ? (string) $idMin : '',
                'id_max' => $idMax !== null && $idMax !== '' ? (string) $idMax : '',
                'price_min' => $priceMin !== null && $priceMin !== '' ? (string) $priceMin : '',
                'price_max' => $priceMax !== null && $priceMax !== '' ? (string) $priceMax : '',
                'qty_min' => $qtyMin !== null && $qtyMin !== '' ? (string) $qtyMin : '',
                'qty_max' => $qtyMax !== null && $qtyMax !== '' ? (string) $qtyMax : '',
                'sort' => $sort,
                'dir' => $dir,
            ],
            'moduleList' => $moduleList,
            'rowHighlight' => ProductSettings::listPayload(),
            'catalogSettings' => ProductSettings::catalogPayload(),
            'productDefaults' => ProductSettings::defaultsPayload(),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Catalog/Products/Form', $this->inertiaCreateProps($request));
    }

    public function store(Request $request, ProductService $products, AttachmentService $attachments): RedirectResponse
    {
        $maxAttachmentKb = (int) config('wento.upload.max_attachment_kb');
        $request->validate([
            'images' => ['nullable', 'array'],
            'images.*' => ['file'],
            'cover_index' => ['nullable', 'integer', 'min:0'],
            'attachment_files' => ['nullable', 'array'],
            'attachment_files.*' => ['file', 'max:'.$maxAttachmentKb],
            'attachment_labels' => ['nullable', 'array'],
            'attachment_labels.*' => ['nullable', 'string', 'max:255'],
            'attachment_descriptions' => ['nullable', 'array'],
            'attachment_descriptions.*' => ['nullable', 'string'],
            'attachment_ids' => ['nullable', 'array'],
            'attachment_ids.*' => ['integer', 'exists:attachments,id'],
            'attachment_roles' => ['nullable', 'array'],
            'attachment_roles.*' => ['nullable', 'string', 'in:document,paid'],
        ]);

        try {
            $product = $products->save($this->validated($request));
            $files = array_values($request->file('images', []) ?: []);
            $coverIndex = $request->input('cover_index');
            $coverIndex = ($coverIndex === null || $coverIndex === '') ? null : (int) $coverIndex;

            foreach ($files as $index => $file) {
                $isCover = $coverIndex === null ? $index === 0 : $index === $coverIndex;
                $products->storeImage($product, $file, null, $isCover);
            }

            $attachmentFiles = array_values($request->file('attachment_files', []) ?: []);
            $labels = $request->input('attachment_labels', []);
            $descriptions = $request->input('attachment_descriptions', []);
            $fileRoles = $request->input('attachment_roles', []);

            foreach ($attachmentFiles as $index => $file) {
                $attachments->createAndAttach(
                    $product,
                    $file,
                    is_string($labels[$index] ?? null) ? $labels[$index] : null,
                    is_string($descriptions[$index] ?? null) ? $descriptions[$index] : null,
                    is_string($fileRoles[$index] ?? null) ? $fileRoles[$index] : null,
                );
            }

            if ($request->has('attachment_ids')) {
                $attachments->syncProductAttachments(
                    $product,
                    $request->input('attachment_ids', []),
                    $request->input('attachment_roles', []),
                );
            }

            ShopStats::flushProducts();

            return $this->productSavedRedirect($product);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Could not save the product.')->with('toast', true);
        }
    }

    /** @return array<string, mixed> */
    public function inertiaCreateProps(Request $request): array
    {
        $type = $request->string('type')->toString();
        $preorderEnabledDefault = false;
        if ($type === 'preorder') {
            $type = 'simple';
            $preorderEnabledDefault = true;
        } elseif ($type === '' || ! in_array($type, $this->selectableTypes(), true)) {
            $type = ProductSettings::defaultType();
            if (! in_array($type, $this->selectableTypes(), true)) {
                $type = 'simple';
            }
        }

        return [
            ...$this->formProps(),
            'productType' => $type,
            'preorderEnabledDefault' => $preorderEnabledDefault,
            'relatedProducts' => [],
            'assignedSubscriptions' => [],
            'componentItems' => [],
            'combinationAttributeCatalog' => app(AttributeService::class)->combinationAttributeCatalog(),
            'combinationAxes' => [],
            'combinationVariants' => [],
            'giftCardConfig' => null,
            'serviceConfig' => null,
            'rentalConfig' => null,
            'preorderConfig' => null,
        ];
    }

    /** @return array<string, mixed> */
    public function inertiaEditProps(Product $product, InventoryService $inventory): array
    {
        $product->load([
            'translations', 'categories', 'tags.translations', 'images', 'attachments.translations',
            'featureValues.translations', 'warehouseStocks', 'qtyPrices', 'shippingMethods', 'suppliers.translations',
            'customizationFields', 'productSubscriptionPlans.plan.translations', 'components.child.translations',
            'combinationAxes.attribute.translations', 'combinationAxisValues', 'variants.attributeValues.translations',
        ]);
        $default = Language::defaultCode();
        $product->setRelation(
            'translations',
            $product->translations->sortBy(fn ($row) => $row->language_code === $default ? 0 : 1)->values()
        );

        $related = $product->relationsOfType('related')->with('translations')->get();
        $combinations = app(CombinationService::class);
        $attachmentService = app(AttachmentService::class);
        // Present roles so Media vs Digital Files tabs can split the same pivot library.
        // Plain arrays must be merged onto the product payload — Eloquent drops non-model relations.
        $presentedAttachments = $product->attachments
            ->map(fn (Attachment $row) => $attachmentService->presentForProduct($row, $product))
            ->values()
            ->all();
        $productPayload = $product->toArray();
        $productPayload['attachments'] = $presentedAttachments;

        return [
            ...$this->formProps($product),
            'product' => $productPayload,
            'inventoryHistory' => $inventory->history($product),
            'relatedProducts' => $related->map(fn (Product $item) => [
                'id' => $item->id,
                'sku' => $item->sku,
                'name' => $item->translation()?->name ?? $item->sku,
            ]),
            'selectedSuppliers' => $product->suppliers->map(fn (Supplier $supplier) => [
                'id' => $supplier->id,
                'name' => $supplier->displayName(),
            ])->values()->all(),
            'moduleSlots' => ModuleBuses::collectSlots('admin.product.edit', ['id' => $product->id, 'sku' => $product->sku]),
            'assignedSubscriptions' => $this->presentAssignedSubscriptions($product),
            'componentItems' => app(CompositionService::class)->present($product),
            'combinationAttributeCatalog' => app(AttributeService::class)->combinationAttributeCatalog(),
            'combinationAxes' => $combinations->presentConfig($product),
            'combinationVariants' => $combinations->presentVariants($product),
            'giftCardConfig' => app(GiftCardService::class)->presentConfig($product),
            'serviceConfig' => app(ServiceProductService::class)->presentConfig($product),
            'rentalConfig' => app(RentalProductService::class)->presentConfig($product),
            'rentalInventorySummary' => $product->type === 'rental'
                ? app(RentalProductService::class)->inventorySummary($product)
                : null,
            'preorderConfig' => app(PreorderService::class)->presentConfig($product),
            'timeline' => app(Timeline::class)->for('product', (int) $product->id),
            'productUrl' => $this->publicProductUrl($product),
        ];
    }

    /** @return array<string, mixed> */
    public function validateProductPayload(Request $request, ?Product $product = null): array
    {
        return $this->validated($request, $product);
    }

    public function edit(Product $product, InventoryService $inventory): Response
    {
        return Inertia::render('Catalog/Products/Form', $this->inertiaEditProps($product, $inventory));
    }

    public function update(Request $request, Product $product, ProductService $products, AttachmentService $attachments): RedirectResponse
    {
        $request->validate([
            'cover_image_id' => ['nullable', 'integer'],
            'image_order' => ['nullable', 'array'],
            'image_order.*' => ['integer'],
            'attachment_ids' => ['nullable', 'array'],
            'attachment_ids.*' => ['integer', 'exists:attachments,id'],
            'attachment_roles' => ['nullable', 'array'],
            'attachment_roles.*' => ['nullable', 'string', 'in:document,paid'],
        ]);

        try {
            $products->save($this->validated($request, $product), $product);

            if ($request->has('image_order')) {
                $products->reorderImages($product, $request->input('image_order', []));
            }

            if ($request->filled('cover_image_id')) {
                $image = $product->images()->whereKey($request->integer('cover_image_id'))->first();
                if ($image) {
                    $product->update(['cover_path' => $image->path]);
                }
            }

            if ($request->has('attachment_ids')) {
                $attachments->syncProductAttachments(
                    $product,
                    $request->input('attachment_ids', []),
                    $request->input('attachment_roles', []),
                );
            }

            ShopStats::flushProducts();

            return $this->productSavedRedirect($product);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Could not save the product.')->with('toast', true);
        }
    }

    public function updateAssignedSubscription(Request $request, Product $product, int $plan): JsonResponse
    {
        $data = $request->validate([
            'price' => ['nullable', 'numeric', 'min:0'],
            'discount_type' => ['nullable', 'in:percent,fixed'],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'is_enabled' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        try {
            app(SubscriptionService::class)->updateProductAssignment($product, $plan, $data);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Could not save the subscription plan.',
            ], 500);
        }
        $product->load(['productSubscriptionPlans.plan.translations']);

        return response()->json([
            'message' => 'Subscription plan saved.',
            'plans' => $this->presentAssignedSubscriptions($product),
        ]);
    }

    public function destroy(Product $product, ProductService $products): RedirectResponse
    {
        $products->delete($product);
        ShopStats::flushProducts();

        return back()->with('success', 'Product deleted.');
    }

    public function toggle(Product $product): RedirectResponse
    {
        $enabling = ! $product->is_enabled;
        if ($enabling) {
            if (ProductSettings::requireCoverToPublish() && ! filled($product->cover_path)) {
                return back()->with('error', 'A cover image is required before publishing this product.');
            }
            if (ProductSettings::requireBrandToPublish() && ! $product->brand_id) {
                return back()->with('error', 'A brand is required before publishing this product.');
            }
            if (ProductSettings::requireMetaTitleToPublish()) {
                $meta = trim((string) ($product->translation(Language::defaultCode())?->meta_title ?? ''));
                if ($meta === '') {
                    return back()->with('error', 'A meta title is required before publishing this product.');
                }
            }
        }

        $product->update(['is_enabled' => $enabling]);
        ShopStats::flushProducts();

        return back()->with('success', $product->is_enabled ? 'Product enabled.' : 'Product disabled.');
    }

    public function bulkDestroy(Request $request, ProductService $products): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
        ]);
        $ids = array_values(array_unique(array_map('intval', $data['ids'])));

        $deleted = 0;
        foreach (Product::query()->whereIn('id', $ids)->get() as $product) {
            $products->delete($product);
            $deleted++;
        }

        if ($deleted === 0) {
            return back()->with('error', 'No products were deleted.');
        }

        ShopStats::flushProducts();

        return back()->with('success', $deleted === 1 ? 'Product deleted.' : $deleted.' products deleted.');
    }

    public function bulkDuplicate(Request $request, ProductService $products): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
        ]);
        $ids = array_values(array_unique(array_map('intval', $data['ids'])));

        $copied = 0;
        foreach (Product::query()->whereIn('id', $ids)->get() as $product) {
            $products->duplicate($product->load(['translations', 'categories', 'tags']));
            $copied++;
        }

        if ($copied === 0) {
            return back()->with('error', 'No products were duplicated.');
        }

        ShopStats::flushProducts();

        return back()->with('success', $copied === 1 ? 'Product duplicated.' : $copied.' products duplicated.');
    }

    public function bulkStatus(Request $request): RedirectResponse
    {
        $request->merge([
            'ids' => array_values(array_filter(array_map('intval', (array) $request->input('ids', [])))),
            'enabled' => $this->toBool($request->input('enabled')),
        ]);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
            'enabled' => ['required', 'boolean'],
        ]);

        $enabled = (bool) $data['enabled'];
        $query = Product::query()->whereIn('id', $data['ids']);

        if ($enabled) {
            if (ProductSettings::requireCoverToPublish()) {
                $blocked = (clone $query)->where(function ($q) {
                    $q->whereNull('cover_path')->orWhere('cover_path', '');
                })->count();
                if ($blocked > 0) {
                    return back()->with('error', $blocked === 1
                        ? '1 product needs a cover image before it can be published.'
                        : $blocked.' products need a cover image before they can be published.');
                }
            }
            if (ProductSettings::requireBrandToPublish()) {
                $blocked = (clone $query)->where(function ($q) {
                    $q->whereNull('brand_id')->orWhere('brand_id', 0);
                })->count();
                if ($blocked > 0) {
                    return back()->with('error', $blocked === 1
                        ? '1 product needs a brand before it can be published.'
                        : $blocked.' products need a brand before they can be published.');
                }
            }
            if (ProductSettings::requireMetaTitleToPublish()) {
                $locale = Language::defaultCode();
                $blocked = (clone $query)->whereDoesntHave('translations', function ($q) use ($locale) {
                    $q->where('language_code', $locale)->whereNotNull('meta_title')->where('meta_title', '!=', '');
                })->count();
                if ($blocked > 0) {
                    return back()->with('error', $blocked === 1
                        ? '1 product needs a meta title before it can be published.'
                        : $blocked.' products need a meta title before they can be published.');
                }
            }
        }

        try {
            $operation = app(Operator::class)->execute('catalog.product.bulk_status', [
                'ids' => $data['ids'],
                'is_enabled' => $enabled,
            ], ['source' => 'admin', 'reason' => $enabled ? 'Bulk enable products' : 'Bulk disable products']);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        $updated = $operation->items()->count();
        if ($updated === 0) {
            return back()->with('error', 'No products were updated.');
        }

        ShopStats::flushProducts();

        return back()->with('success', $enabled
            ? ($updated === 1 ? 'Product enabled.' : $updated.' products enabled.')
            : ($updated === 1 ? 'Product disabled.' : $updated.' products disabled.'));
    }

    protected function toBool(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === '1' || $value === 'true' || $value === 'on') {
            return true;
        }
        if ($value === 0 || $value === '0' || $value === 'false' || $value === 'off') {
            return false;
        }

        return $value;
    }

    protected function productSavedRedirect(Product $product): RedirectResponse
    {
        return redirect()
            ->route('admin.products.edit', $product)
            ->with('success', 'Product saved successfully.')
            ->with('toast', true);
    }

    public function duplicate(Product $product, ProductService $products): RedirectResponse
    {
        $copy = $products->duplicate($product->load(['translations', 'categories', 'tags']));

        return redirect()->route('admin.products.edit', $copy)->with('success', 'Product duplicated.');
    }

    public function export(Request $request, ProductService $products): StreamedResponse
    {
        $csv = $products->exportCsv(Language::defaultCode(), $request);

        return response()->streamDownload(fn () => print ($csv), 'products.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function import(Request $request, ProductService $products): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xls,xlsx', 'max:16384'],
            'language' => ['nullable', 'string', 'max:16'],
            'delimiter' => ['nullable', 'string', 'max:8'],
            'multivalue_separator' => ['nullable', 'string', 'max:8'],
        ]);

        $locale = $data['language'] ?? Language::defaultCode();
        $delimiter = $data['delimiter'] ?? ProductCsvSchema::DEFAULT_DELIMITER;
        $multi = $data['multivalue_separator'] ?? ProductCsvSchema::DEFAULT_MULTI_SEPARATOR;

        $count = $products->importCsv(
            CatalogSpreadsheet::fromUpload($request->file('file')),
            $locale,
            $delimiter,
            $multi,
        );

        return back()->with('success', "Imported {$count} products.");
    }

    public function importSample(Request $request, ProductService $products)
    {
        $delimiter = (string) $request->input('delimiter', ProductCsvSchema::DEFAULT_DELIMITER);
        $multi = (string) $request->input('multivalue_separator', ProductCsvSchema::DEFAULT_MULTI_SEPARATOR);
        $csv = $products->sampleCsv($delimiter, $multi);

        return response()->streamDownload(fn () => print ($csv), 'products-sample.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function search(Request $request)
    {
        $q = trim($request->string('q')->toString());
        $exclude = $request->integer('exclude') ?: null;
        $forComponents = $request->string('for')->toString() === 'components';

        $products = Product::query()
            ->with('translations')
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('sku', 'like', "%{$q}%")
                        ->orWhereHas('translations', fn ($t) => $t->where('name', 'like', "%{$q}%"));
                });
            })
            ->when($exclude, fn ($query, $id) => $query->where('id', '!=', $id))
            ->when($forComponents, function ($query) {
                $query->where('is_enabled', true)->whereNotIn('type', ['group', 'bundle']);
            })
            ->latest()
            ->limit(12)
            ->get()
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->translation()?->name ?? $product->sku,
                'price' => (float) $product->price,
                'selling_price' => $product->sellingPrice(),
                'stock_qty' => (int) $product->availableStock(),
                'stock_status' => $product->stock_status,
                'is_enabled' => (bool) $product->is_enabled,
                'type' => $product->type,
            ]);

        return response()->json($products);
    }

    public function upload(Request $request, Product $product, ProductService $products): RedirectResponse
    {
        $request->validate(['image' => ['required', 'file']]);
        $products->storeImage($product, $request->file('image'), $request->string('alt')->toString(), $request->boolean('cover'));

        return back()->with('success', 'Image uploaded.');
    }

    public function destroyImage(Product $product, ProductImage $image, ProductService $products): RedirectResponse
    {
        $products->deleteImage($product, $image);

        return back()->with('success', 'Image removed.');
    }

    public function syncAttachments(Request $request, Product $product, AttachmentService $attachments): RedirectResponse
    {
        $data = $request->validate([
            'attachment_ids' => ['nullable', 'array'],
            'attachment_ids.*' => ['integer', 'exists:attachments,id'],
            'attachment_roles' => ['nullable', 'array'],
            'attachment_roles.*' => ['nullable', 'string', 'in:document,paid'],
        ]);

        $attachments->syncProductAttachments(
            $product,
            $data['attachment_ids'] ?? [],
            $data['attachment_roles'] ?? [],
        );

        return back()->with('success', 'Product files updated.');
    }

    public function detachAttachment(Product $product, Attachment $attachment, AttachmentService $attachments): RedirectResponse
    {
        if (! $product->attachments()->where('attachments.id', $attachment->id)->exists()) {
            abort(404);
        }

        $attachments->detachFromProduct($product, $attachment);

        return back()->with('success', 'File removed from product.');
    }

    protected function validated(Request $request, ?Product $product = null): array
    {
        $data = $request->validate([
            'type' => ['nullable', 'string', 'in:standard,combinations,pack,virtual,service,booking'],
            'sku' => ['nullable', 'string', 'max:64'],
            'price' => ['required', 'numeric', 'min:0'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'sale_price_type' => ['nullable', 'in:fixed,percent'],
            'sale_starts_at' => ['nullable', 'date'],
            'sale_ends_at' => ['nullable', 'date', 'after_or_equal:sale_starts_at'],
            'compare_price' => ['nullable', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'stock_qty' => ['nullable', 'numeric', 'min:0'],
            'reserved_qty' => ['nullable', 'numeric', 'min:0'],
            'low_stock_alert' => ['nullable', 'integer', 'min:0'],
            'critical_stock' => ['nullable', 'integer', 'min:0'],
            'backorder_status' => ['nullable', 'in:allow,deny,hide,allow_notify'],
            'notify_low_stock' => ['nullable', 'boolean'],
            'notify_out_of_stock' => ['nullable', 'boolean'],
            'warehouse_stocks' => ['nullable', 'array'],
            'warehouse_stocks.*.warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'warehouse_stocks.*.qty' => ['nullable', 'numeric', 'min:0'],
            'warehouse_stocks.*.reserved_qty' => ['nullable', 'numeric', 'min:0'],
            'qty_prices' => ['nullable', 'array'],
            'qty_prices.*.min' => ['nullable', 'numeric', 'min:0'],
            'qty_prices.*.min_qty' => ['nullable', 'numeric', 'min:0'],
            'qty_prices.*.max' => ['nullable', 'numeric', 'min:0'],
            'qty_prices.*.max_qty' => ['nullable', 'numeric', 'min:0'],
            'qty_prices.*.price' => ['nullable', 'numeric', 'min:0'],
            'name' => ['nullable', 'string', 'max:255'],
            'slug' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'short_description' => ['nullable', 'string'],
            'meta_title' => ['nullable', 'string', 'max:60'],
            'meta_description' => ['nullable', 'string', 'max:160'],
            'meta_keywords' => ['nullable', 'string'],
            'focus_keyword' => ['nullable', 'string', 'max:255'],
            'canonical_url' => ['nullable', 'string', 'max:500'],
            'robots' => ['nullable', Rule::in(['index,follow', 'index,nofollow', 'noindex,follow', 'noindex,nofollow'])],
            'translations' => ['nullable', 'array'],
            'translations.*.language_code' => ['required_with:translations', 'string', Rule::exists('languages', 'code')->where('is_enabled', true)],
            'translations.*.name' => ['nullable', 'string', 'max:255'],
            'translations.*.slug' => ['nullable', 'string', 'max:180', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'translations.*.description' => ['nullable', 'string'],
            'translations.*.short_description' => ['nullable', 'string'],
            'translations.*.meta_title' => ['nullable', 'string', 'max:60'],
            'translations.*.meta_description' => ['nullable', 'string', 'max:160'],
            'translations.*.meta_keywords' => ['nullable', 'string'],
            'translations.*.focus_keyword' => ['nullable', 'string', 'max:255'],
            'translations.*.canonical_url' => ['nullable', 'string', 'max:500'],
            'translations.*.robots' => ['nullable', Rule::in(['index,follow', 'index,nofollow', 'noindex,follow', 'noindex,nofollow'])],
            'translations.*.subscription_message' => ['nullable', 'string', 'max:2000'],
            'visibility' => ['nullable', Rule::in(config('wento.visibility'))],
            'available_for_order' => ['nullable', 'boolean'],
            'show_price' => ['nullable', 'boolean'],
            'is_enabled' => ['nullable', 'boolean'],
            'is_new' => ['nullable', 'boolean'],
            'is_on_sale' => ['nullable', 'boolean'],
            'is_best_seller' => ['nullable', 'boolean'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'supplier_ids' => ['nullable', 'array'],
            'supplier_ids.*' => ['integer', 'exists:suppliers,id'],
            'tax_class_id' => ['nullable', 'integer'],
            'default_category_id' => ['required', 'integer', 'exists:categories,id'],
            'category_ids' => ['required', 'array', 'min:1'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'weight_class' => ['nullable', 'in:kg,g,lb'],
            'length' => ['nullable', 'numeric', 'min:0'],
            'width' => ['nullable', 'numeric', 'min:0'],
            'height' => ['nullable', 'numeric', 'min:0'],
            'length_class' => ['nullable', 'in:cm,inch,mm'],
            'processing_time' => ['nullable', 'integer', 'min:0'],
            'processing_time_unit' => ['nullable', 'in:days,weeks'],
            'delivery_from' => ['nullable', 'integer', 'min:0'],
            'delivery_to' => ['nullable', 'integer', 'min:0'],
            'additional_shipping_cost' => ['nullable', 'numeric', 'min:0'],
            'available_all_carriers' => ['nullable', 'boolean'],
            'shipping_method_ids' => ['nullable', 'array'],
            'shipping_method_ids.*' => ['integer', 'exists:shipping_methods,id'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'isbn' => ['nullable', 'string', 'max:64'],
            'upc' => ['nullable', 'string', 'max:64'],
            'mpn' => ['nullable', 'string', 'max:64'],
            'gtin' => ['nullable', 'string', 'max:64'],
            'video_url' => ['nullable', 'string', 'max:255'],
            'display_condition' => ['nullable', 'boolean'],
            'condition' => ['nullable', 'in:new,new_open_box,new_minor_defect,used,refurbished,damaged'],
            'min_qty' => ['nullable', 'numeric', 'min:0'],
            'max_qty' => ['nullable', 'numeric', 'min:0'],
            'qty_step' => ['nullable', 'numeric', 'min:0.001'],
            'sale_unit' => ['nullable', Rule::in(SaleUnit::TYPES)],
            'sale_unit_code' => ['nullable', 'string', 'max:8'],
            'allow_backorder' => ['nullable', 'boolean'],
            'manage_stock' => ['nullable', 'boolean'],
            'stock_status' => ['nullable', 'in:in_stock,out_of_stock,on_backorder'],
            'low_stock_threshold' => ['nullable', 'integer'],
            'feature_value_ids' => ['nullable', 'array'],
            'feature_value_ids.*.feature_id' => ['required', 'integer'],
            'feature_value_ids.*.feature_value_id' => ['required', 'integer'],
            'related_ids' => ['nullable', 'array'],
            'related_ids.*' => ['integer'],
            'upsell_ids' => ['nullable', 'array'],
            'crosssell_ids' => ['nullable', 'array'],
            'type' => ['nullable', 'in:'.implode(',', $this->acceptableTypes())],
            'customization_enabled' => ['nullable', 'boolean'],
            'customization_fields' => ['nullable', 'array'],
            'customization_fields.*.id' => ['nullable', 'integer'],
            'customization_fields.*.type' => ['required_with:customization_fields', 'string', Rule::in(config('wento.customization.field_types'))],
            'customization_fields.*.label' => ['required_with:customization_fields', 'string', 'max:255'],
            'customization_fields.*.is_required' => ['nullable', 'boolean'],
            'customization_fields.*.placeholder' => ['nullable', 'string', 'max:255'],
            'customization_fields.*.help_text' => ['nullable', 'string'],
            'customization_fields.*.default_value' => ['nullable', 'string'],
            'customization_fields.*.min_length' => ['nullable', 'integer', 'min:0'],
            'customization_fields.*.max_length' => ['nullable', 'integer', 'min:0'],
            'customization_fields.*.allowed_file_types' => ['nullable', 'string', 'max:255'],
            'customization_fields.*.max_file_size_kb' => ['nullable', 'integer', 'min:1'],
            'customization_fields.*.price_type' => ['nullable', Rule::in(config('wento.customization.price_types'))],
            'customization_fields.*.price_amount' => ['nullable', 'numeric', 'min:0'],
            'customization_fields.*.options' => ['nullable', 'array'],
            'customization_fields.*.options.*.label' => ['required_with:customization_fields.*.options', 'string', 'max:255'],
            'customization_fields.*.options.*.value' => ['nullable', 'string', 'max:255'],
            'customization_fields.*.options.*.price_type' => ['nullable', Rule::in(config('wento.customization.price_types'))],
            'customization_fields.*.options.*.price' => ['nullable', 'numeric', 'min:0'],
            'customization_fields.*.options.*.is_default' => ['nullable', 'boolean'],
            'subscription_enabled' => ['nullable', 'boolean'],
            'purchase_option' => ['nullable', Rule::in(config('wento.subscription.purchase_options'))],
            'subscription_plans' => ['nullable', 'array'],
            'subscription_plans.*.subscription_plan_id' => ['required', 'integer', 'exists:subscription_plans,id'],
            'subscription_plans.*.price' => ['nullable', 'numeric', 'min:0'],
            'subscription_plans.*.discount_type' => ['nullable', 'in:percent,fixed'],
            'subscription_plans.*.discount_value' => ['nullable', 'numeric', 'min:0'],
            'subscription_plans.*.is_enabled' => ['nullable', 'boolean'],
            'subscription_plans.*.is_default' => ['nullable', 'boolean'],
            'selection_mode' => ['nullable', 'in:all,customer'],
            'bundle_type' => ['nullable', 'in:fixed,configurable'],
            'pricing_mode' => ['nullable', 'in:sum,sum_discount,sum_adjustment,fixed'],
            'pricing_discount_type' => ['nullable', 'in:fixed,percent'],
            'pricing_discount_value' => ['nullable', 'numeric'],
            'inventory_mode' => ['nullable', 'in:derived,manual'],
            'shipping_mode' => ['nullable', 'in:derived,custom'],
            'components' => ['nullable', 'array'],
            'components.*.id' => ['required_with:components', 'integer', 'exists:products,id'],
            'components.*.quantity' => ['required_with:components', 'integer', 'min:1'],
            'components.*.min_quantity' => ['nullable', 'integer', 'min:1'],
            'components.*.max_quantity' => ['nullable', 'integer', 'min:1'],
            'components.*.qty_editable' => ['nullable', 'boolean'],
            'components.*.is_required' => ['nullable', 'boolean'],
            'components.*.default_selected' => ['nullable', 'boolean'],
            'components.*.option_group' => ['nullable', 'string', 'max:120'],
            'combination_axes' => ['nullable', 'array'],
            'combination_axes.*.attribute_id' => ['required_with:combination_axes', 'integer', 'exists:attributes,id'],
            'combination_axes.*.value_ids' => ['nullable', 'array'],
            'combination_axes.*.value_ids.*' => ['integer', 'exists:attribute_values,id'],
            'combination_variants' => ['nullable', 'array'],
            'combination_variants.*.id' => ['nullable', 'integer'],
            'combination_variants.*.attribute_value_ids' => ['nullable', 'array'],
            'combination_variants.*.attribute_value_ids.*' => ['integer', 'exists:attribute_values,id'],
            'combination_variants.*.sku' => ['nullable', 'string', 'max:64'],
            'combination_variants.*.barcode' => ['nullable', 'string', 'max:64'],
            'combination_variants.*.price' => ['nullable', 'numeric', 'min:0'],
            'combination_variants.*.sale_price' => ['nullable', 'numeric', 'min:0'],
            'combination_variants.*.sale_price_type' => ['nullable', 'in:fixed,percent'],
            'combination_variants.*.cost_price' => ['nullable', 'numeric', 'min:0'],
            'combination_variants.*.stock_qty' => ['nullable', 'numeric', 'min:0'],
            'combination_variants.*.manage_stock' => ['nullable', 'boolean'],
            'combination_variants.*.stock_status' => ['nullable', 'in:in_stock,out_of_stock,on_backorder'],
            'combination_variants.*.allow_backorder' => ['nullable', 'boolean'],
            'combination_variants.*.weight' => ['nullable', 'numeric', 'min:0'],
            'combination_variants.*.length' => ['nullable', 'numeric', 'min:0'],
            'combination_variants.*.width' => ['nullable', 'numeric', 'min:0'],
            'combination_variants.*.height' => ['nullable', 'numeric', 'min:0'],
            'combination_variants.*.cover_path' => ['nullable', 'string', 'max:255'],
            'combination_variants.*.cover_image_id' => ['nullable', 'integer', 'exists:product_images,id'],
            'combination_variants.*.is_enabled' => ['nullable', 'boolean'],
            'combination_variants.*.is_default' => ['nullable', 'boolean'],
            'combination_variants.*.position' => ['nullable', 'integer', 'min:0'],
            'remove_missing_combinations' => ['nullable', 'boolean'],
            'deleted_combination_variant_ids' => ['nullable', 'array'],
            'deleted_combination_variant_ids.*' => ['integer'],
            'gift_card' => ['nullable', 'array'],
            'gift_card.amount_type' => ['nullable', 'in:fixed,custom'],
            'gift_card.min_amount' => ['nullable', 'numeric', 'min:0'],
            'gift_card.max_amount' => ['nullable', 'numeric', 'min:0'],
            'gift_card.default_amount' => ['nullable', 'numeric', 'min:0'],
            'gift_card.amount_step' => ['nullable', 'numeric', 'min:0'],
            'gift_card.delivery_method' => ['nullable', 'in:email,account,both'],
            'gift_card.validity_type' => ['nullable', 'in:never,days,fixed_date'],
            'gift_card.validity_days' => ['nullable', 'integer', 'min:1'],
            'gift_card.expiry_date' => ['nullable', 'date'],
            'gift_card.usage_type' => ['nullable', 'in:single,multiple'],
            'gift_card.code_length' => ['nullable', 'integer', 'min:6', 'max:32'],
            'gift_card.code_prefix' => ['nullable', 'string', 'max:32'],
            'gift_card.code_charset' => ['nullable', 'in:alphanumeric,numeric,alpha'],
            'gift_card.restrictions' => ['nullable', 'array'],
            'gift_card.restrictions.category_ids' => ['nullable', 'array'],
            'gift_card.restrictions.category_ids.*' => ['integer', 'exists:categories,id'],
            'gift_card.restrictions.product_ids' => ['nullable', 'array'],
            'gift_card.restrictions.product_ids.*' => ['integer', 'exists:products,id'],
            'gift_card.restrictions.exclude_category_ids' => ['nullable', 'array'],
            'gift_card.restrictions.exclude_category_ids.*' => ['integer', 'exists:categories,id'],
            'gift_card.restrictions.exclude_product_ids' => ['nullable', 'array'],
            'gift_card.restrictions.exclude_product_ids.*' => ['integer', 'exists:products,id'],
            'gift_card_amounts' => ['nullable', 'array'],
            'gift_card_amounts.*.id' => ['nullable', 'integer'],
            'gift_card_amounts.*.value' => ['nullable', 'numeric', 'min:0'],
            'gift_card_amounts.*.selling_price' => ['nullable', 'numeric', 'min:0'],
            'gift_card_amounts.*.is_enabled' => ['nullable', 'boolean'],
            'gift_card_amounts.*.position' => ['nullable', 'integer', 'min:0'],
            'service' => ['nullable', 'array'],
            'service.service_type' => ['nullable', 'in:one_time,recurring,hourly,appointment'],
            'service.duration' => ['nullable', 'numeric', 'min:0'],
            'service.duration_unit' => ['nullable', 'in:minutes,hours,days'],
            'service.pricing_model' => ['nullable', 'in:fixed,per_hour,per_day,starting_from'],
            'service.delivery_method' => ['nullable', 'in:online,onsite,both'],
            'service.travel_fee' => ['nullable', 'numeric', 'min:0'],
            'rental' => ['nullable', 'array'],
            'rental.rental_unit' => ['nullable', 'in:hour,day,week,month'],
            'rental.min_duration' => ['nullable', 'integer', 'min:1'],
            'rental.max_duration' => ['nullable', 'integer', 'min:1'],
            'rental.default_duration' => ['nullable', 'integer', 'min:1'],
            'rental.pricing_model' => ['nullable', 'in:per_hour,per_day,per_week,per_month'],
            'rental.security_deposit_type' => ['nullable', 'in:none,fixed,percent'],
            'rental.inventory_mode' => ['nullable', 'in:quantity,asset'],
            'rental_pricing_tiers' => ['nullable', 'array'],
            'rental_pricing_tiers.*.id' => ['nullable', 'integer'],
            'rental_pricing_tiers.*.min_duration' => ['nullable', 'integer', 'min:1'],
            'rental_pricing_tiers.*.max_duration' => ['nullable', 'integer', 'min:1'],
            'rental_pricing_tiers.*.unit' => ['nullable', 'in:hour,day,week,month'],
            'rental_pricing_tiers.*.price' => ['nullable', 'numeric', 'min:0'],
            'rental_assets' => ['nullable', 'array'],
            'rental_assets.*.id' => ['nullable', 'integer'],
            'rental_assets.*.asset_id' => ['nullable', 'string', 'max:64'],
            'preorder_enabled' => ['nullable', 'boolean'],
            'preorder' => ['nullable', 'array'],
            'preorder.start_at' => ['nullable', 'date'],
            'preorder.end_at' => ['nullable', 'date'],
            'preorder.release_at' => ['nullable', 'date'],
            'preorder.fulfillment_at' => ['nullable', 'date'],
            'preorder.expected_delivery_at' => ['nullable', 'date'],
            'preorder.payment_type' => ['nullable', 'in:full,deposit'],
            'preorder.deposit_type' => ['nullable', 'in:fixed,percent'],
            'preorder.deposit_value' => ['nullable', 'numeric', 'min:0'],
            'preorder.min_quantity' => ['nullable', 'integer', 'min:1'],
            'preorder.max_quantity' => ['nullable', 'integer', 'min:1'],
            'preorder.total_limit' => ['nullable', 'integer', 'min:1'],
            'preorder.message' => ['nullable', 'string'],
            'preorder.show_badge' => ['nullable', 'boolean'],
            'preorder.show_release_date' => ['nullable', 'boolean'],
            'preorder.show_delivery_date' => ['nullable', 'boolean'],
            'preorder.show_countdown_pdp' => ['nullable', 'boolean'],
            'preorder.show_countdown_listing' => ['nullable', 'boolean'],
            'preorder.show_image_label' => ['nullable', 'boolean'],
            'preorder.notify_me_enabled' => ['nullable', 'boolean'],
            'preorder.fulfillment_rule' => ['nullable', 'in:auto,manual'],
            'preorder.preorder_price' => ['nullable', 'numeric', 'min:0'],
            'preorder.preorder_price_valid_until' => ['nullable', 'date'],
        ]);

        foreach (['gift_card', 'gift_card_amounts', 'service', 'rental', 'rental_pricing_tiers', 'rental_assets', 'preorder', 'preorder_enabled'] as $payloadKey) {
            if ($request->has($payloadKey)) {
                $data[$payloadKey] = $request->input($payloadKey);
            }
        }

        if (! empty($data['brand_id'])) {
            $brand = Brand::query()->find($data['brand_id']);
            if ($brand && ! $brand->is_enabled && (int) $brand->id !== (int) ($product?->brand_id)) {
                throw ValidationException::withMessages([
                    'brand_id' => 'This brand is disabled and cannot be assigned to products.',
                ]);
            }
        }

        $backorderStatus = $data['backorder_status']
            ?? (array_key_exists('allow_backorder', $data)
                ? (($data['allow_backorder'] ?? false) ? 'allow' : 'deny')
                : ($product?->backorder_status ?: ProductSettings::defaultBackorderStatus()));
        $backorderStatus = ProductSettings::normalizeStockBehavior(
            is_string($backorderStatus) ? $backorderStatus : null,
            ProductSettings::defaultBackorderStatus()
        );
        $translations = $this->normalizeTranslations($request, $data);
        $this->assertDescriptionLimits($translations, $data);

        $saveErrors = [];
        $defaultLocale = Language::defaultCode();
        if (trim((string) ($translations[$defaultLocale]['name'] ?? '')) === '') {
            $saveErrors["translations.$defaultLocale.name"] = 'Product name is required for the default language.';
        }

        try {
            $this->assertProductSettingsRules($request, $data, $translations, $product);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $key => $messages) {
                $saveErrors[$key] = is_array($messages) ? (string) ($messages[0] ?? '') : (string) $messages;
            }
        }

        $defaultTranslation = $translations[$defaultLocale] ?? null;

        $categoryIds = array_values(array_unique(array_map(
            'intval',
            array_filter($data['category_ids'] ?? [], fn ($id) => $id !== '' && $id !== null)
        )));
        if ($categoryIds === []) {
            $saveErrors['category_ids'] = 'Select at least one category.';
        }

        $defaultCategoryId = (int) ($data['default_category_id'] ?? 0);
        if ($defaultCategoryId < 1 || ! in_array($defaultCategoryId, $categoryIds, true)) {
            $saveErrors['default_category_id'] = 'Choose a default category from the selected categories.';
        }

        if ($saveErrors !== []) {
            throw ValidationException::withMessages($saveErrors);
        }

        $relatedIds = array_values(array_map('intval', $data['related_ids'] ?? []));
        $sale = SaleUnit::normalizeInput(
            $data['type'] ?? 'simple',
            $data['sale_unit'] ?? null,
            $data['sale_unit_code'] ?? null,
        );

        $out = [
            'product' => [
                'type' => $data['type'] ?? 'simple',
                'sku' => $this->resolveSku($data['sku'] ?? null, (string) ($defaultTranslation['name'] ?? $data['name'] ?? 'product')),
                'price' => $data['price'],
                'sale_price' => ($data['sale_price'] ?? '') === '' ? null : $data['sale_price'],
                'sale_price_type' => $data['sale_price_type'] ?? 'fixed',
                'sale_starts_at' => $data['sale_starts_at'] ?? null,
                'sale_ends_at' => $data['sale_ends_at'] ?? null,
                'compare_price' => ($data['compare_price'] ?? '') === '' ? null : $data['compare_price'],
                'cost_price' => ($data['cost_price'] ?? '') === '' ? null : $data['cost_price'],
                'stock_qty' => $request->exists('stock_qty') ? ($data['stock_qty'] ?? 0) : ($product ? $product->stock_qty : 0),
                'reserved_qty' => $request->exists('reserved_qty') ? ($data['reserved_qty'] ?? 0) : ($product ? $product->reserved_qty : 0),
                'low_stock_alert' => $data['low_stock_alert'] ?? ($data['low_stock_threshold'] ?? ProductSettings::defaultLowStockAlert()),
                'critical_stock' => $data['critical_stock'] ?? ProductSettings::defaultCriticalStock(),
                'backorder_status' => $backorderStatus !== '' && $backorderStatus !== null
                    ? $backorderStatus
                    : ProductSettings::defaultBackorderStatus(),
                'notify_low_stock' => $data['notify_low_stock'] ?? ProductSettings::defaultNotifyLowStock(),
                'notify_out_of_stock' => $data['notify_out_of_stock'] ?? ProductSettings::defaultNotifyOutOfStock(),
                'visibility' => $data['visibility'] ?? ProductSettings::defaultVisibility(),
                'available_for_order' => $data['available_for_order'] ?? ProductSettings::defaultAvailableForOrder(),
                'show_price' => $data['show_price'] ?? ProductSettings::defaultShowPrice(),
                'is_enabled' => array_key_exists('is_enabled', $data)
                    ? (bool) $data['is_enabled']
                    : ($product ? (bool) $product->is_enabled : ProductSettings::defaultIsEnabled()),
                'is_new' => $data['is_new'] ?? ($product ? false : ProductSettings::newProductDays() > 0),
                'is_on_sale' => ($data['sale_price'] ?? '') !== '' && $data['sale_price'] !== null,
                'is_best_seller' => $data['is_best_seller'] ?? false,
                'brand_id' => $data['brand_id'] ?? null,
                'supplier_id' => ($data['supplier_id'] ?? '') !== '' ? $data['supplier_id'] : null,
                'tax_class_id' => ($data['tax_class_id'] ?? '') !== ''
                    ? $data['tax_class_id']
                    : ($product ? null : ProductSettings::defaultTaxClassId()),
                'default_category_id' => $defaultCategoryId,
                'weight' => ($data['weight'] ?? '') === '' ? null : $data['weight'],
                'weight_class' => $data['weight_class'] ?? ProductSettings::shopWeightUnit(),
                'length' => ($data['length'] ?? '') === '' ? null : $data['length'],
                'width' => ($data['width'] ?? '') === '' ? null : $data['width'],
                'height' => ($data['height'] ?? '') === '' ? null : $data['height'],
                'length_class' => $data['length_class'] ?? ProductSettings::shopDimensionUnit(),
                'processing_time' => ($data['processing_time'] ?? '') === '' ? null : $data['processing_time'],
                'processing_time_unit' => $data['processing_time_unit'] ?? 'days',
                'delivery_from' => ($data['delivery_from'] ?? '') === '' ? null : $data['delivery_from'],
                'delivery_to' => ($data['delivery_to'] ?? '') === '' ? null : $data['delivery_to'],
                'additional_shipping_cost' => ($data['additional_shipping_cost'] ?? '') === '' ? 0 : $data['additional_shipping_cost'],
                'available_all_carriers' => $data['available_all_carriers'] ?? true,
                'barcode' => $data['barcode'] ?? null,
                'isbn' => $data['isbn'] ?? null,
                'upc' => $data['upc'] ?? null,
                'mpn' => $data['mpn'] ?? null,
                'gtin' => $data['gtin'] ?? null,
                'video_url' => trim((string) ($data['video_url'] ?? '')) ?: null,
                'display_condition' => $data['display_condition'] ?? false,
                'condition' => ($data['display_condition'] ?? false) ? ($data['condition'] ?? null) : null,
                'min_qty' => ($data['min_qty'] ?? '') === '' ? null : $data['min_qty'],
                'max_qty' => ($data['max_qty'] ?? '') === '' ? null : $data['max_qty'],
                'qty_step' => ($data['qty_step'] ?? '') === '' ? null : $data['qty_step'],
                'sale_unit' => $sale['sale_unit'],
                'sale_unit_code' => $sale['sale_unit_code'],
                'allow_backorder' => $backorderStatus === 'allow',
                'manage_stock' => $request->exists('manage_stock')
                    ? $request->boolean('manage_stock')
                    : (ProductType::tryFrom($data['type'] ?? 'simple')?->defaultManageStock()
                        ?? ProductSettings::defaultManageStock()),
                'low_stock_threshold' => $data['low_stock_threshold'] ?? ProductSettings::defaultLowStockAlert(),
                'stock_status' => $data['stock_status'] ?? ($product
                    ? $product->stock_status
                    : ((($data['stock_qty'] ?? 0) > 0) ? 'in_stock' : 'out_of_stock')),
                'selection_mode' => $data['selection_mode'] ?? null,
                'bundle_type' => $data['bundle_type'] ?? null,
                'pricing_mode' => $data['pricing_mode'] ?? null,
                'pricing_discount_type' => $data['pricing_discount_type'] ?? null,
                'pricing_discount_value' => ($data['pricing_discount_value'] ?? '') === '' ? null : $data['pricing_discount_value'],
                'inventory_mode' => $data['inventory_mode'] ?? null,
                'shipping_mode' => $data['shipping_mode'] ?? null,
            ],
            'translation' => $defaultTranslation,
            'translations' => $translations,
            'category_ids' => $categoryIds,
            'supplier_ids' => array_values(array_unique(array_map('intval', $data['supplier_ids'] ?? []))),
            'feature_value_ids' => $data['feature_value_ids'] ?? [],
            'available_all_carriers' => $data['available_all_carriers'] ?? true,
            'shipping_method_ids' => $data['shipping_method_ids'] ?? [],
            'relations' => [
                'related' => $relatedIds,
                'upsell' => $data['upsell_ids'] ?? [],
                'crosssell' => $data['crosssell_ids'] ?? [],
            ],
        ];

        if (array_key_exists('warehouse_stocks', $data) || $request->exists('warehouse_stocks')) {
            $out['warehouse_stocks'] = $data['warehouse_stocks'] ?? [];
        }

        if ($request->exists('qty_prices')) {
            $out['qty_prices'] = $data['qty_prices'] ?? [];
        }

        if ($request->exists('customization_enabled') || $request->exists('customization_fields')) {
            $enabled = $request->boolean('customization_enabled');
            $out['customization_enabled'] = $enabled;
            $out['customization_fields'] = $data['customization_fields'] ?? [];
            $out['product']['customization_enabled'] = $enabled;
        }

        if ($request->exists('subscription_enabled') || $request->exists('subscription_plans') || $request->exists('purchase_option')) {
            foreach ($data['subscription_plans'] ?? [] as $index => $row) {
                if (($row['discount_type'] ?? null) === 'percent' && (float) ($row['discount_value'] ?? 0) > 100) {
                    throw ValidationException::withMessages([
                        "subscription_plans.$index.discount_value" => 'Percentage discount cannot exceed 100%.',
                    ]);
                }
            }
            $out['subscription_enabled'] = $request->boolean('subscription_enabled');
            $out['purchase_option'] = $data['purchase_option'] ?? 'one_time';
            $out['subscription_plans'] = $data['subscription_plans'] ?? [];
            app(SubscriptionService::class)->assertProductHasRequiredPlans(
                $out['subscription_enabled'],
                (string) $out['purchase_option'],
                $out['subscription_plans'],
            );
            $out['product']['subscription_enabled'] = $out['subscription_enabled'];
            $out['product']['purchase_option'] = $out['purchase_option'];
        }

        $type = $out['product']['type'] ?? 'simple';
        if (in_array($type, ['group', 'bundle'], true)) {
            $out['product']['selection_mode'] = $out['product']['selection_mode'] ?: ($type === 'group' ? 'all' : $out['product']['selection_mode']);
            $out['product']['bundle_type'] = $out['product']['bundle_type'] ?: ($type === 'bundle' ? 'fixed' : $out['product']['bundle_type']);
            $out['product']['pricing_mode'] = $out['product']['pricing_mode'] ?: ($type === 'bundle' ? 'fixed' : 'sum');
            $out['product']['inventory_mode'] = $out['product']['inventory_mode'] ?: 'derived';
            $out['product']['shipping_mode'] = $out['product']['shipping_mode'] ?: 'derived';
            if ($out['product']['inventory_mode'] === 'derived') {
                $out['product']['manage_stock'] = false;
            }
            if (($out['product']['pricing_discount_type'] ?? null) === 'percent' && (float) ($out['product']['pricing_discount_value'] ?? 0) > 100) {
                throw ValidationException::withMessages([
                    'pricing_discount_value' => 'Percentage discount cannot exceed 100%.',
                ]);
            }
            $out['components'] = $data['components'] ?? [];
        }

        if (($out['product']['type'] ?? 'simple') === 'combination') {
            $out['combination_axes'] = $data['combination_axes'] ?? [];
            $out['combination_variants'] = $data['combination_variants'] ?? [];
            $out['remove_missing_combinations'] = $request->boolean('remove_missing_combinations');
            $out['deleted_combination_variant_ids'] = $data['deleted_combination_variant_ids'] ?? [];
            $out['product']['manage_stock'] = false;
        }

        if (($out['product']['type'] ?? 'simple') === 'virtual') {
            $out['product']['manage_stock'] = false;
        }

        if (($out['product']['type'] ?? 'simple') === 'gift_card') {
            $out['gift_card'] = $data['gift_card'] ?? [];
            $out['gift_card_amounts'] = $data['gift_card_amounts'] ?? [];
            $out['product']['manage_stock'] = false;
        }

        if (($out['product']['type'] ?? 'simple') === 'service') {
            $out['service'] = $data['service'] ?? [];
            $out['product']['manage_stock'] = false;
        }

        if (($out['product']['type'] ?? 'simple') === 'rental') {
            $out['rental'] = $data['rental'] ?? [];
            $out['rental_pricing_tiers'] = $data['rental_pricing_tiers'] ?? [];
            $out['rental_assets'] = $data['rental_assets'] ?? [];
        }

        if (($out['product']['type'] ?? 'simple') === 'preorder') {
            $out['product']['type'] = 'simple';
            $out['preorder_enabled'] = true;
            $out['product']['preorder_enabled'] = true;
        }

        if ($request->exists('preorder_enabled') || $request->exists('preorder')) {
            $out['preorder_enabled'] = $request->boolean('preorder_enabled');
            $out['preorder'] = $data['preorder'] ?? [];
            $out['product']['preorder_enabled'] = $out['preorder_enabled'];
        }

        $subscriptionEnabled = array_key_exists('subscription_enabled', $out)
            ? (bool) $out['subscription_enabled']
            : (bool) ($product?->subscription_enabled ?? false);
        $preorderEnabled = array_key_exists('preorder_enabled', $out)
            ? (bool) $out['preorder_enabled']
            : (bool) ($product?->preorder_enabled ?? false);
        \App\Domain\Catalog\Support\ProductModeConflict::assertExclusive($subscriptionEnabled, $preorderEnabled);

        return $out;
    }

    /** @return array<string, array<string, mixed>> */
    protected function normalizeTranslations(Request $request, array $data): array
    {
        $default = Language::defaultCode();
        $rows = [];

        foreach ((array) ($data['translations'] ?? []) as $key => $row) {
            if (! is_array($row)) {
                continue;
            }
            $language = (string) ($row['language_code'] ?? $key);
            if ($language === '') {
                continue;
            }
            $rows[$language] = [
                'language_code' => $language,
                'name' => trim((string) ($row['name'] ?? '')),
                'slug' => trim((string) ($row['slug'] ?? '')),
                'description' => $row['description'] ?? null,
                'short_description' => $row['short_description'] ?? null,
                'meta_title' => trim((string) ($row['meta_title'] ?? '')),
                'meta_description' => trim((string) ($row['meta_description'] ?? '')),
                'meta_keywords' => trim((string) ($row['meta_keywords'] ?? '')),
                'focus_keyword' => trim((string) ($row['focus_keyword'] ?? '')),
                'canonical_url' => trim((string) ($row['canonical_url'] ?? '')),
                'robots' => $row['robots'] ?? 'index,follow',
                'subscription_message' => $row['subscription_message'] ?? null,
            ];
        }

        if ($rows === []) {
            $rows[$default] = [
                'language_code' => $default,
                'name' => trim((string) ($data['name'] ?? '')),
                'slug' => trim((string) ($data['slug'] ?? '')),
                'description' => $data['description'] ?? null,
                'short_description' => $data['short_description'] ?? null,
                'meta_title' => trim((string) ($data['meta_title'] ?? '')),
                'meta_description' => trim((string) ($data['meta_description'] ?? '')),
                'meta_keywords' => trim((string) ($data['meta_keywords'] ?? '')),
                'focus_keyword' => trim((string) ($data['focus_keyword'] ?? '')),
                'canonical_url' => trim((string) ($data['canonical_url'] ?? '')),
                'robots' => $data['robots'] ?? 'index,follow',
                'subscription_message' => $data['subscription_message'] ?? null,
            ];
        }

        $slugPairs = [];
        foreach ($rows as $language => $row) {
            $hasContent = collect($row)
                ->except(['language_code', 'robots'])
                ->contains(fn ($value) => is_string($value) ? trim($value) !== '' : $value !== null);

            if ($language !== $default && $hasContent && $row['name'] === '') {
                throw ValidationException::withMessages([
                    "translations.$language.name" => 'Product name is required when saving a translation.',
                ]);
            }

            if ($row['slug'] !== '') {
                $pair = $language.'|'.$row['slug'];
                if (isset($slugPairs[$pair])) {
                    throw ValidationException::withMessages([
                        "translations.$language.slug" => 'This slug is duplicated in the current request.',
                    ]);
                }
                $slugPairs[$pair] = true;
            }
        }

        return $rows;
    }

    /**
     * Tax classes with shop-country layers so the product form can preview excl/incl prices.
     *
     * @return list<array{id: int, name: string, tax_layers: list<array{rate: float, name: ?string, is_compound: bool}>}>
     */
    protected function taxClassesForForm(): array
    {
        $country = strtoupper(trim((string) (ShopIdentity::get()['country_code'] ?? ''))) ?: null;
        $engine = app(TaxEngine::class);

        return TaxClass::query()
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(function (TaxClass $class) use ($engine, $country) {
                $layers = $engine->resolveLayers((int) $class->id, $country, null);

                return [
                    'id' => (int) $class->id,
                    'name' => (string) $class->name,
                    'tax_layers' => array_map(static fn (array $layer) => [
                        'rate' => (float) ($layer['rate'] ?? 0),
                        'name' => $layer['name'] ?? null,
                        'is_compound' => (bool) ($layer['is_compound'] ?? false),
                    ], $layers),
                ];
            })
            ->values()
            ->all();
    }

    protected function formProps(?Product $product = null): array
    {
        return [
            'categories' => Category::query()
                ->with('translations')
                ->where(function ($query) use ($product) {
                    $query->where('is_enabled', true);
                    if ($product) {
                        $assignedIds = $product->categories()->pluck('categories.id');
                        if ($assignedIds->isNotEmpty()) {
                            $query->orWhereIn('id', $assignedIds);
                        }
                    }
                })
                ->orderBy('position')
                ->orderBy('id')
                ->get(),
            'brands' => Brand::query()
                ->with('translations')
                ->where(function ($query) use ($product) {
                    $query->where('is_enabled', true);
                    if ($product?->brand_id) {
                        $query->orWhere('id', $product->brand_id);
                    }
                })
                ->orderBy('position')
                ->orderBy('id')
                ->get(),
            'suppliers' => Supplier::query()
                ->with('translations')
                ->where(function ($query) use ($product) {
                    $query->where('is_enabled', true);
                    if ($product) {
                        $assignedIds = $product->suppliers()->pluck('suppliers.id');
                        if ($assignedIds->isNotEmpty()) {
                            $query->orWhereIn('id', $assignedIds);
                        }
                    }
                })
                ->orderBy('position')
                ->orderBy('id')
                ->get()
                ->map(fn (Supplier $supplier) => [
                    'id' => $supplier->id,
                    'name' => $supplier->displayName(),
                    'is_enabled' => $supplier->is_enabled,
                ]),
            'selectedSuppliers' => [],
            'taxClasses' => $this->taxClassesForForm(),
            'pricingSettings' => PricingSettings::payload(),
            'features' => Feature::query()
                ->with([
                    'translations',
                    'values' => function ($query) use ($product) {
                        $assignedValueIds = $product?->featureValues()->pluck('feature_values.id') ?? collect();
                        $query->where(function ($inner) use ($assignedValueIds) {
                            $inner->where('is_enabled', true);
                            if ($assignedValueIds->isNotEmpty()) {
                                $inner->orWhereIn('id', $assignedValueIds);
                            }
                        })->orderBy('position')->orderBy('id');
                    },
                    'values.translations',
                ])
                ->where(function ($query) use ($product) {
                    $query->where('is_enabled', true);
                    $assignedFeatureIds = $product?->featureValues()->pluck('feature_values.feature_id') ?? collect();
                    if ($assignedFeatureIds->isNotEmpty()) {
                        $query->orWhereIn('id', $assignedFeatureIds);
                    }
                })
                ->orderBy('position')
                ->orderBy('id')
                ->get(),
            'warehouses' => Warehouse::query()->where('is_enabled', true)->orderByDesc('is_default')->orderBy('name')->orderBy('id')->get(['id', 'name', 'code', 'is_default']),
            'shippingMethods' => ShippingMethod::query()->where('is_enabled', true)->orderBy('position')->orderBy('name')->get(['id', 'name', 'price']),
            'currencySymbol' => Currency::query()->where('is_default', true)->value('symbol') ?: '$',
            'shopUrl' => ShopUrl::query()->where('is_primary', true)->first()?->root() ?: rtrim((string) config('app.url'), '/'),
            'types' => config('wento.product_types'),
            'catalogSettings' => ProductSettings::catalogPayload(),
            'contentSettings' => ProductSettings::contentPayload(),
            'productDefaults' => ProductSettings::defaultsPayload(),
            'availableSubscriptionPlans' => SubscriptionPlan::query()
                ->with('translations')
                ->where('is_enabled', true)
                ->orderBy('position')
                ->orderBy('id')
                ->get()
                ->map(fn (SubscriptionPlan $plan) => app(SubscriptionService::class)->presentPlan($plan)),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $translations
     * @param  array<string, mixed>  $data
     */
    protected function assertDescriptionLimits(array $translations, array $data): void
    {
        $shortMax = ProductSettings::shortDescriptionMax();
        $descMax = ProductSettings::descriptionMax();
        $metaTitleMax = ProductSettings::metaTitleMax();
        $metaDescMax = ProductSettings::metaDescriptionMax();
        $errors = [];

        $check = function (string $key, ?string $value, int $max, string $label) use (&$errors): void {
            if ($value === null || $value === '') {
                return;
            }
            $len = ProductSettings::plainLength($value);
            if ($len > $max) {
                $errors[$key] = "{$label} must be at most {$max} characters (currently {$len}).";
            }
        };

        $check('short_description', isset($data['short_description']) ? (string) $data['short_description'] : null, $shortMax, 'Short description');
        $check('description', isset($data['description']) ? (string) $data['description'] : null, $descMax, 'Description');
        $check('meta_title', isset($data['meta_title']) ? (string) $data['meta_title'] : null, $metaTitleMax, 'Meta title');
        $check('meta_description', isset($data['meta_description']) ? (string) $data['meta_description'] : null, $metaDescMax, 'Meta description');

        foreach ($translations as $locale => $row) {
            $check("translations.{$locale}.short_description", isset($row['short_description']) ? (string) $row['short_description'] : null, $shortMax, 'Short description');
            $check("translations.{$locale}.description", isset($row['description']) ? (string) $row['description'] : null, $descMax, 'Description');
            $check("translations.{$locale}.meta_title", isset($row['meta_title']) ? (string) $row['meta_title'] : null, $metaTitleMax, 'Meta title');
            $check("translations.{$locale}.meta_description", isset($row['meta_description']) ? (string) $row['meta_description'] : null, $metaDescMax, 'Meta description');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, array<string, mixed>>  $translations
     */
    protected function assertProductSettingsRules(Request $request, array $data, array $translations, ?Product $product): void
    {
        $errors = [];

        if (ProductSettings::requireSku()) {
            $sku = trim((string) ($data['sku'] ?? ''));
            if ($sku === '') {
                $errors['sku'] = 'SKU is required.';
            } elseif (Product::query()
                ->when($product, fn ($q) => $q->where('id', '!=', $product->id))
                ->where('sku', $sku)
                ->exists()) {
                $errors['sku'] = 'This SKU / reference is already used by another product.';
            }
        }

        if (ProductSettings::barcodeMode() === 'required') {
            $upc = trim((string) ($data['upc'] ?? ''));
            $gtin = trim((string) ($data['gtin'] ?? ''));
            if ($upc === '') {
                $errors['upc'] = 'UPC is required.';
            }
            if ($gtin === '') {
                $errors['gtin'] = 'GTIN is required.';
            }
        }

        $publishing = array_key_exists('is_enabled', $data)
            ? (bool) $data['is_enabled']
            : (bool) ($product?->is_enabled);

        if (ProductSettings::requireCoverToPublish()) {
            $hasCover = filled($product?->cover_path)
                || filled($request->input('cover_image_id'))
                || filled($request->input('cover_path'))
                || $request->hasFile('images')
                || (is_array($request->input('image_order')) && count($request->input('image_order')) > 0);
            if (! $hasCover) {
                $errors['cover_path'] = 'A cover image is required before publishing this product.';
            }
        }

        if (ProductSettings::requireBrandToPublish()) {
            $brandId = (int) ($data['brand_id'] ?? $product?->brand_id ?? 0);
            if ($brandId < 1) {
                $errors['brand_id'] = 'A brand is required before publishing this product.';
            }
        }

        if (ProductSettings::requireMetaTitleToPublish() && $publishing) {
            $locale = Language::defaultCode();
            $meta = trim((string) (
                $translations[$locale]['meta_title']
                ?? $data['meta_title']
                ?? $product?->translation($locale)?->meta_title
                ?? ''
            ));
            if ($meta === '') {
                $errors['meta_title'] = 'A meta title is required before publishing this product.';
            }
        }

        $type = (string) ($data['type'] ?? $product?->type ?? 'simple');

        if (in_array($type, ['group', 'bundle'], true)) {
            $components = $data['components'] ?? [];
            if (! is_array($components) || $components === []) {
                $errors['components'] = 'Add at least one product to this pack.';
            }
        }

        if ($type === 'combination' && ! $product) {
            $variants = $data['combination_variants'] ?? [];
            $axes = $data['combination_axes'] ?? [];
            if ((! is_array($variants) || $variants === []) && (! is_array($axes) || $axes === [])) {
                $errors['combination_variants'] = 'Add at least one combination before saving.';
            }
        }

        $regular = isset($data['price']) && $data['price'] !== '' && $data['price'] !== null
            ? (float) $data['price']
            : null;
        $saleRaw = $data['sale_price'] ?? null;
        if ($saleRaw !== null && $saleRaw !== '') {
            $saleType = (string) ($data['sale_price_type'] ?? 'fixed');
            $saleValue = (float) $saleRaw;
            if ($saleValue < 0) {
                $errors['sale_price'] = 'Sale price cannot be negative.';
            } elseif ($saleType === 'percent' && $saleValue > 100) {
                $errors['sale_price'] = 'Percentage sale price cannot exceed 100%.';
            } elseif ($saleType !== 'percent' && $regular !== null && $saleValue > $regular) {
                $errors['sale_price'] = 'Fixed sale price cannot exceed the regular price.';
            }
        }

        $compareRaw = $data['compare_price'] ?? null;
        if ($compareRaw !== null && $compareRaw !== '' && $regular !== null && (float) $compareRaw < $regular) {
            $errors['compare_price'] = 'Compare-at price should be greater than or equal to the regular price.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    protected function unsignedFilter(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }
        if (! is_numeric($value) || (float) $value < 0) {
            return 0;
        }

        return $value;
    }

    protected function resolveSku(?string $sku, string $nameHint): string
    {
        $sku = trim((string) $sku);
        if ($sku !== '') {
            return $sku;
        }

        if (ProductSettings::skuMode() === 'manual' || ProductSettings::requireSku()) {
            // assertProductSettingsRules already rejects empty manual SKUs; keep a unique fallback for safety.
            return ProductSettings::skuPrefix().Str::upper(Str::random(8));
        }

        $slug = Str::upper(Str::slug($nameHint) ?: 'PRODUCT');

        return ProductSettings::skuPrefix().$slug.'-'.Str::upper(Str::random(4));
    }

    protected function presentAssignedSubscriptions(Product $product): array
    {
        $svc = app(SubscriptionService::class);

        return $product->productSubscriptionPlans->map(function ($row) use ($svc) {
            $plan = $row->plan;
            $presented = $plan ? $svc->presentPlan($plan) : null;

            return [
                'subscription_plan_id' => $row->subscription_plan_id,
                'name' => $presented['name'] ?? 'Plan',
                'billing_summary' => $presented['billing_summary'] ?? '',
                'trial_summary' => $presented['trial_summary'] ?? 'No',
                'duration_summary' => $presented['duration_summary'] ?? '',
                'trial_enabled' => (bool) ($presented['trial_enabled'] ?? false),
                'trial_duration' => (int) ($presented['trial_duration'] ?? 0),
                'trial_cycle' => (int) ($presented['trial_cycle'] ?? 1),
                'trial_frequency' => $presented['trial_frequency'] ?? null,
                'cycle' => (int) ($presented['cycle'] ?? 1),
                'frequency' => $presented['frequency'] ?? null,
                'duration' => (int) ($presented['duration'] ?? 0),
                'plan_enabled' => $presented['is_enabled'] ?? false,
                'id' => $row->id,
                'price' => (float) $row->price,
                'discount_type' => $row->discount_type,
                'discount_value' => $row->discount_value !== null ? (float) $row->discount_value : '',
                'is_enabled' => (bool) $row->is_enabled,
                'is_default' => (bool) $row->is_default,
            ];
        })->values()->all();
    }

    protected function publicProductUrl(Product $product): ?string
    {
        $locale = Language::defaultCode();
        $path = app(UrlRewriteService::class)->pathFor('product', (int) $product->id, $locale);
        if (! is_string($path) || $path === '') {
            $slug = $product->translation($locale)?->slug ?? $product->translation()?->slug;
            $path = is_string($slug) && $slug !== '' ? $slug : null;
        }
        if (! is_string($path) || $path === '') {
            return null;
        }

        return StorefrontStructuredData::publicUrl('/'.ltrim($path, '/'), $locale);
    }

    protected function selectableTypes(): array
    {
        return ['simple', 'combination', 'group', 'virtual', 'bundle', 'gift_card', 'service', 'rental'];
    }

    /** @return list<string> */
    protected function acceptableTypes(): array
    {
        return [...$this->selectableTypes(), 'preorder'];
    }
}
