<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Models\Attachment;
use App\Domain\Catalog\Models\ProductTemplate;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Feature;
use App\Domain\Catalog\Models\FeatureValue;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCustomizationField;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\ProductQtyPrice;
use App\Domain\Catalog\Models\Warehouse;
use App\Domain\Catalog\Models\WarehouseStock;
use App\Domain\Api\Graphql\GraphqlProductComposition;
use App\Domain\Catalog\Models\AttributeValue;
use App\Domain\Catalog\Models\CatalogAttribute;
use App\Domain\Catalog\Models\ProductComponent;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Services\AttachmentService;
use App\Domain\Catalog\Services\CategoryService;
use App\Domain\Catalog\Services\CombinationService;
use App\Domain\Catalog\Services\CompositionService;
use App\Domain\Catalog\Services\CustomizationService;
use App\Domain\Catalog\Services\FeatureService;
use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Catalog\Services\ProductService;
use App\Domain\Catalog\Services\ServiceProductService;
use App\Domain\Localization\Models\Language;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Admin REST REST catalog tree: /api/v1/console/{products|categories|attributes…}
 */
class AdminCatalogRestController extends Controller
{
    public function productsIndex(Request $request): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        $perPage = min(100, max(1, (int) $request->query('per_page', $request->query('limit', 20))));
        $page = max(1, (int) $request->query('page', 1));
        $locale = app()->getLocale();
        $q = Product::query()->with('translations')->orderByDesc('id');
        $term = trim((string) ($request->query('q') ?? $request->query('search') ?? ''));
        if ($term !== '') {
            $like = '%'.$term.'%';
            $q->where(function ($inner) use ($like, $locale) {
                $inner->where('sku', 'like', $like)
                    ->orWhereHas('translations', fn ($t) => $t->where('language_code', $locale)->where('name', 'like', $like));
            });
        }
        $sku = trim((string) ($request->query('sku') ?? ''));
        if ($sku !== '') {
            $q->where('sku', 'like', '%'.$sku.'%');
        }
        if ($request->has('status')) {
            $q->where('is_enabled', ((int) $request->query('status')) === 1);
        }
        $type = trim((string) ($request->query('type') ?? ''));
        if ($type !== '') {
            $q->where('type', $type);
        }
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $perPage)->get()
            ->map(fn (Product $p) => $this->productRow($p))
            ->values()
            ->all();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
            ],
        ]);
    }

    public function productsShow(int $id): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        $product = Product::query()->with([
            'translations',
            'categories.translations',
            'attachments',
            'customizationFields',
        ])->find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'error' => 'not_found'], 404);
        }
        $row = $this->productRow($product);
        $row['images'] = ProductImage::query()->where('product_id', $product->id)->orderBy('position')->orderBy('id')->get()
            ->map(fn (ProductImage $img) => $this->imageRow($img, (int) $product->id))
            ->values()
            ->all();
        $video = trim((string) ($product->video_url ?? ''));
        $row['videos'] = $video === '' ? [] : [$this->videoRow($product)];
        $row['categories'] = $product->categories->map(fn (Category $c) => $this->categoryRow($c))->values()->all();
        $row['downloadableLinks'] = $product->attachments()
            ->wherePivot('role', AttachmentService::ROLE_PAID)
            ->orderBy('attachments.id')
            ->get()
            ->map(fn (Attachment $a) => $this->downloadableRow($a, (int) $product->id, 'link'))
            ->values()
            ->all();
        $row['downloadableSamples'] = $product->attachments()
            ->wherePivot('role', AttachmentService::ROLE_DOCUMENT)
            ->orderBy('attachments.id')
            ->get()
            ->map(fn (Attachment $a) => $this->downloadableRow($a, (int) $product->id, 'sample'))
            ->values()
            ->all();
        $row['customizableOptions'] = array_map(
            fn (array $opt) => $this->customizableOptionRow($opt, (int) $product->id),
            app(CustomizationService::class)->present($product)
        );
        if ($product->isCombination()) {
            $row['variantAxes'] = app(CombinationService::class)->presentConfig($product);
            $row['variants'] = app(CombinationService::class)->presentVariants($product);
        }
        if ($product->isComposite()) {
            $row['bundleOptions'] = $this->bundleOptionsIndex($id)->getData(true)['data'] ?? [];
            $row['groupedProducts'] = $this->groupedProductsIndex($id)->getData(true)['data'] ?? [];
        }
        if ($product->isService() || $product->isRental()) {
            $row['bookingProducts'] = $this->bookingProductsIndex($id)->getData(true)['data'] ?? [];
        }
        $row['warnings'] = [];

        return response()->json(['data' => $row]);
    }

    public function productsStore(Request $request): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        $sku = trim((string) ($request->input('sku') ?? ''));
        $name = trim((string) ($request->input('name') ?? ''));
        $price = (float) ($request->input('price') ?? 0);
        $stockQty = (float) ($request->input('stock_qty') ?? $request->input('stockQty') ?? 0);
        $type = $this->normalizeProductType(trim((string) ($request->input('type') ?? 'simple')) ?: 'simple');
        if ($sku === '' || $name === '') {
            return response()->json(['message' => 'sku and name are required.', 'error' => 'validation'], 422);
        }
        if (Product::query()->where('sku', $sku)->exists()) {
            return response()->json(['message' => 'A product with this SKU already exists.', 'error' => 'validation'], 422);
        }
        if ($type === 'combination') {
            $super = $request->input('variant_axes') ?? $request->input('variantAxes') ?? null;
            if (! is_array($super) || $super === []) {
                return response()->json([
                    'message' => 'variant_axes are required for configurable products.',
                    'error' => 'validation',
                ], 422);
            }
        }
        $categoryId = (int) ($request->input('category_id') ?? $request->input('categoryId') ?? 0);
        $category = $categoryId > 0
            ? Category::query()->whereKey($categoryId)->where('is_enabled', true)->first()
            : Category::query()->where('is_enabled', true)->orderBy('position')->orderBy('id')->first();
        if (! $category) {
            return response()->json(['message' => 'No enabled category available.', 'error' => 'validation'], 422);
        }
        $locale = Language::defaultCode();
        $slug = Str::slug($name) ?: ('product-'.Str::lower(Str::random(6)));
        $payload = [
            'product' => [
                'type' => $type,
                'sku' => $sku,
                'price' => max(0, $price),
                'stock_qty' => max(0, $stockQty),
                'default_category_id' => $category->id,
                'visibility' => 'both',
                'is_enabled' => $request->boolean('status', $request->boolean('enabled', true)),
            ],
            'category_ids' => [$category->id],
            'translations' => [
                $locale => [
                    'name' => $name,
                    'slug' => $slug,
                    'short_description' => (string) ($request->input('short_description') ?? $request->input('shortDescription') ?? ''),
                    'description' => (string) ($request->input('description') ?? ''),
                ],
            ],
        ];
        if ($type === 'combination') {
            $axes = $this->variantAxesToAxes($request->input('variant_axes') ?? $request->input('variantAxes') ?? []);
            if ($axes === []) {
                return response()->json(['message' => 'variant_axes must map attribute codes/ids to option ids.', 'error' => 'validation'], 422);
            }
            $payload['combination_axes'] = $axes;
            $payload['combination_variants'] = $request->input('combination_variants')
                ?? $request->input('variants')
                ?? [];
            $payload['remove_missing_combinations'] = true;
        }
        if ($request->has('categories') || $request->has('category_ids') || $request->has('categoryIds')) {
            $catIds = $this->normalizeCategoryIds($request);
            if ($catIds !== []) {
                $payload['category_ids'] = $catIds;
                $payload['product']['default_category_id'] = $catIds[0];
            }
        }
        if ($request->filled('product_template_id') || $request->filled('productTemplateId')) {
            $familyId = (int) ($request->input('product_template_id') ?? $request->input('productTemplateId'));
            if ($familyId > 0 && ! ProductTemplate::query()->whereKey($familyId)->exists()) {
                return response()->json(['message' => 'product_template_id is invalid.', 'error' => 'validation'], 422);
            }
        }
        try {
            $row = app(ProductService::class)->save($payload);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => 'create_error'], 422);
        }

        return response()->json(['data' => $this->productRow($row->fresh('translations') ?? $row)], 201);
    }

    public function productsUpdate(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        $product = Product::query()->find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'error' => 'not_found'], 404);
        }
        $payload = ['product' => []];
        foreach (['sku', 'price', 'weight', 'type'] as $field) {
            if ($request->has($field)) {
                $value = $request->input($field);
                if ($field === 'type') {
                    $value = $this->normalizeProductType((string) $value);
                }
                $payload['product'][$field] = $value;
            }
        }
        if ($request->has('variant_axes') || $request->has('variantAxes')
            || $request->has('combination_axes') || $request->has('variants') || $request->has('combination_variants')) {
            $axes = $request->input('combination_axes');
            if (! is_array($axes) || $axes === []) {
                $axes = $this->variantAxesToAxes(
                    $request->input('variant_axes') ?? $request->input('variantAxes') ?? []
                );
            }
            if (is_array($axes) && $axes !== []) {
                $payload['combination_axes'] = $axes;
            }
            $variants = $request->input('combination_variants') ?? $request->input('variants');
            if (is_array($variants)) {
                $payload['combination_variants'] = $variants;
            }
            $payload['remove_missing_combinations'] = (bool) ($request->input('remove_missing_combinations') ?? true);
        }
        if ($request->has('stock_qty') || $request->has('stockQty')) {
            $payload['product']['stock_qty'] = (float) ($request->input('stock_qty') ?? $request->input('stockQty'));
        }
        if ($request->has('status') || $request->has('enabled')) {
            $payload['product']['is_enabled'] = $request->boolean('status', $request->boolean('enabled'));
        }
        $name = trim((string) ($request->input('name') ?? ''));
        if ($name !== '') {
            $locale = Language::defaultCode();
            $payload['translations'] = [
                $locale => [
                    'name' => $name,
                    'slug' => Str::slug($name) ?: (string) $product->translation($locale)?->slug,
                    'short_description' => (string) ($request->input('short_description') ?? $request->input('shortDescription') ?? $product->translation($locale)?->short_description ?? ''),
                    'description' => (string) ($request->input('description') ?? $product->translation($locale)?->description ?? ''),
                ],
            ];
        }
        if ($request->has('categories') || $request->has('category_ids') || $request->has('categoryIds')) {
            $payload['category_ids'] = $this->normalizeCategoryIds($request);
            if ($payload['category_ids'] !== []) {
                $payload['product']['default_category_id'] = $payload['category_ids'][0];
            }
        }
        if ($request->filled('product_template_id') || $request->filled('productTemplateId')) {
            $familyId = (int) ($request->input('product_template_id') ?? $request->input('productTemplateId'));
            if ($familyId > 0 && ! ProductTemplate::query()->whereKey($familyId)->exists()) {
                return response()->json(['message' => 'product_template_id is invalid.', 'error' => 'validation'], 422);
            }
        }
        try {
            $row = app(ProductService::class)->save($payload, $product);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => 'update_error'], 422);
        }

        return response()->json(['data' => $this->productRow($row->fresh('translations') ?? $row)]);
    }

    public function productCategoriesIndex(int $id): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        $product = Product::query()->with('categories.translations')->find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'error' => 'not_found'], 404);
        }
        $rows = $product->categories->map(fn (Category $c) => $this->categoryRow($c))->values()->all();

        return response()->json(['data' => $rows]);
    }

    public function productCategoriesUpdate(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        $product = Product::query()->find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'error' => 'not_found'], 404);
        }
        $ids = $this->normalizeCategoryIds($request);
        try {
            app(ProductService::class)->save([
                'product' => [
                    'default_category_id' => $ids[0] ?? $product->default_category_id,
                ],
                'category_ids' => $ids,
            ], $product);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => 'validation'], 422);
        }

        return $this->productCategoriesIndex($id);
    }

    public function productsDestroy(int $id): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        $product = Product::query()->find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'error' => 'not_found'], 404);
        }
        try {
            app(ProductService::class)->delete($product);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => 'delete_error'], 422);
        }

        return response()->json(['message' => 'Product deleted successfully.', 'data' => ['id' => $id]]);
    }

    public function productsCopy(int $id): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        $product = Product::query()->find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'error' => 'not_found'], 404);
        }
        if ((string) ($product->type ?? '') === 'configurable' && $product->parent_id) {
            return response()->json(['message' => 'Configurable variants cannot be copied standalone.', 'error' => 'copy_error'], 422);
        }
        try {
            $copy = app(ProductService::class)->duplicate($product);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => 'copy_error'], 422);
        }

        return response()->json(['data' => $this->productRow($copy->fresh('translations') ?? $copy)], 201);
    }

    public function productsInventoriesIndex(int $id): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        $product = Product::query()->find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'error' => 'not_found'], 404);
        }
        $rows = WarehouseStock::query()
            ->with('warehouse')
            ->where('product_id', $product->id)
            ->orderBy('warehouse_id')
            ->get()
            ->map(fn (WarehouseStock $s) => [
                'id' => (int) $s->id,
                'productId' => (int) $product->id,
                'warehouseId' => (int) $s->warehouse_id,
                'sourceCode' => (string) ($s->warehouse?->code ?? ''),
                'sourceName' => (string) ($s->warehouse?->name ?? ''),
                'qty' => (float) $s->qty,
                'reservedQty' => (float) ($s->reserved_qty ?? 0),
            ])
            ->values()
            ->all();
        $totalQty = collect($rows)->sum('qty');

        return response()->json([
            'data' => $rows,
            'meta' => ['totalQty' => (float) $totalQty],
        ]);
    }

    public function productsInventories(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        $product = Product::query()->find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'error' => 'not_found'], 404);
        }
        $inventories = $request->input('inventories');
        if (! is_array($inventories) || $inventories === []) {
            return response()->json(['message' => 'inventories map is required.', 'error' => 'validation'], 422);
        }
        $before = (float) ($product->stock_qty ?? 0);
        $rows = [];
        foreach ($inventories as $warehouseId => $qty) {
            $wid = (int) $warehouseId;
            if ($wid < 1 || ! Warehouse::query()->whereKey($wid)->exists()) {
                continue;
            }
            $quantity = max(0, (float) $qty);
            app(InventoryService::class)->syncFromForm($product, [
                'warehouse_stocks' => [[
                    'warehouse_id' => $wid,
                    'qty' => $quantity,
                    'reserved_qty' => 0,
                ]],
            ], $before);
            $before = (float) ($product->fresh()->stock_qty ?? $quantity);
            $stock = WarehouseStock::query()
                ->where('product_id', $product->id)
                ->where('warehouse_id', $wid)
                ->first();
            if ($stock) {
                $rows[] = [
                    'id' => (int) $stock->id,
                    'productId' => (int) $product->id,
                    'warehouseId' => $wid,
                    'qty' => (float) $stock->qty,
                ];
            }
        }
        if ($rows === []) {
            return response()->json(['message' => 'No valid warehouses updated.', 'error' => 'validation'], 422);
        }

        return response()->json([
            'message' => 'Inventories updated successfully.',
            'data' => $rows,
        ]);
    }

    public function productsBulkUpdate(Request $request): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        $ids = collect($request->input('ids')  ?? [])
            ->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        if ($ids === []) {
            return response()->json(['message' => 'At least one product id is required.', 'error' => 'validation'], 422);
        }
        $value = $request->input('value', $request->input('status', $request->input('enabled')));
        $enabled = is_bool($value) ? $value : ((int) $value === 1);
        $updated = Product::query()->whereIn('id', $ids)->update(['is_enabled' => $enabled]);

        return response()->json([
            'message' => 'Products updated successfully.',
            'data' => ['updated_count' => (int) $updated],
        ]);
    }

    public function productsBulkDestroy(Request $request): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        $ids = collect($request->input('ids')  ?? [])
            ->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        if ($ids === []) {
            return response()->json(['message' => 'At least one product id is required.', 'error' => 'validation'], 422);
        }
        $deleted = 0;
        $service = app(ProductService::class);
        foreach (Product::query()->whereIn('id', $ids)->get() as $row) {
            try {
                $service->delete($row);
                $deleted++;
            } catch (\Throwable) {
            }
        }

        return response()->json([
            'message' => 'Products deleted successfully.',
            'data' => ['deleted_count' => $deleted],
        ]);
    }

    public function productImagesIndex(int $id): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        $product = Product::query()->find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'error' => 'not_found'], 404);
        }
        $rows = ProductImage::query()->where('product_id', $product->id)->orderBy('position')->orderBy('id')->get()
            ->map(fn (ProductImage $img) => $this->imageRow($img, (int) $product->id))
            ->values()
            ->all();

        return response()->json(['data' => $rows]);
    }

    public function productImagesStore(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        $product = Product::query()->find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'error' => 'not_found'], 404);
        }
        $file = $request->file('image') ?? $request->file('file');
        if (! $file) {
            return response()->json(['message' => 'image file is required.', 'error' => 'validation'], 422);
        }
        $alt = trim((string) ($request->input('alt_text') ?? $request->input('altText') ?? $request->input('alt') ?? '')) ?: null;
        try {
            $image = app(ProductService::class)->storeImage($product, $file, $alt, $request->boolean('cover'));
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => 'upload_error'], 422);
        }
        if ($request->filled('position')) {
            $image->position = max(0, (int) $request->input('position'));
            $image->save();
        }

        return response()->json($this->imageRow($image->fresh() ?? $image, (int) $product->id), 201);
    }

    public function productImagesUpdate(Request $request, int $id, int $imageId): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        $image = ProductImage::query()->where('product_id', $id)->whereKey($imageId)->first();
        if (! $image) {
            return response()->json(['message' => 'Product image not found.', 'error' => 'not_found'], 404);
        }
        if ($request->has('alt_text') || $request->has('altText') || $request->has('alt')) {
            $image->alt = trim((string) ($request->input('alt_text') ?? $request->input('altText') ?? $request->input('alt') ?? '')) ?: null;
        }
        if ($request->filled('position')) {
            $image->position = max(0, (int) $request->input('position'));
        }
        $image->save();

        return response()->json(['data' => $this->imageRow($image, $id)]);
    }

    public function productImagesDestroy(int $id, int $imageId): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        $product = Product::query()->find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'error' => 'not_found'], 404);
        }
        $image = ProductImage::query()->where('product_id', $id)->whereKey($imageId)->first();
        if (! $image) {
            return response()->json(['message' => 'Product image not found.', 'error' => 'not_found'], 404);
        }
        try {
            app(ProductService::class)->deleteImage($product, $image);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => 'delete_error'], 422);
        }

        return response()->json(['message' => 'Product image deleted successfully.', 'data' => ['id' => $imageId]]);
    }

    public function productImagesReorder(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        if (! Product::query()->whereKey($id)->exists()) {
            return response()->json(['message' => 'Product not found.', 'error' => 'not_found'], 404);
        }
        $orders = $request->input('orders') ?? $request->input('images') ?? $request->input('data') ?? [];
        if (! is_array($orders) || $orders === []) {
            return response()->json(['message' => 'orders list is required.', 'error' => 'validation'], 422);
        }
        $updated = [];
        foreach ($orders as $row) {
            if (! is_array($row)) {
                continue;
            }
            $imageId = (int) ($row['id'] ?? $row['imageId'] ?? $row['image_id'] ?? 0);
            $position = (int) ($row['position'] ?? 0);
            if ($imageId < 1) {
                continue;
            }
            $image = ProductImage::query()->where('product_id', $id)->whereKey($imageId)->first();
            if (! $image) {
                return response()->json(['message' => 'Image '.$imageId.' does not belong to this product.', 'error' => 'validation'], 422);
            }
            $image->position = max(0, $position);
            $image->save();
            $updated[] = $this->imageRow($image, $id);
        }

        return response()->json([
            'message' => 'Images reordered successfully.',
            'data' => $updated,
        ]);
    }

    public function productVideosIndex(int $id): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        $product = Product::query()->find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'error' => 'not_found'], 404);
        }
        $video = trim((string) ($product->video_url ?? ''));
        $rows = $video === '' ? [] : [$this->videoRow($product)];

        return response()->json(['data' => $rows]);
    }

    public function productVideosStore(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        $product = Product::query()->find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'error' => 'not_found'], 404);
        }
        $url = trim((string) ($request->input('video_url') ?? $request->input('url') ?? $request->input('path') ?? $request->input('video') ?? ''));
        if ($url === '' && $request->hasFile('video')) {
            $file = $request->file('video');
            $stored = $file->store('products/videos', 'public');
            $url = '/storage/'.ltrim((string) $stored, '/');
        }
        if ($url === '') {
            return response()->json(['message' => 'video_url or video file is required.', 'error' => 'validation'], 422);
        }
        $product->video_url = $url;
        $product->save();

        return response()->json(['data' => $this->videoRow($product->fresh() ?? $product)], 201);
    }

    public function productVideosUpdate(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        $product = Product::query()->find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'error' => 'not_found'], 404);
        }
        $url = trim((string) ($request->input('video_url') ?? $request->input('url') ?? $request->input('path') ?? $request->input('video') ?? ''));
        if ($url === '') {
            return response()->json(['message' => 'video_url is required.', 'error' => 'validation'], 422);
        }
        $product->video_url = $url;
        $product->save();

        return response()->json(['data' => $this->videoRow($product)]);
    }

    public function productVideosDestroy(int $id): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        $product = Product::query()->find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'error' => 'not_found'], 404);
        }
        $product->video_url = null;
        $product->save();

        return response()->json(['message' => 'Product video deleted successfully.', 'data' => ['id' => $id]]);
    }

    public function downloadableLinksIndex(int $id): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        $product = Product::query()->with('attachments.translations')->find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'error' => 'not_found'], 404);
        }
        $rows = $product->attachments()
            ->wherePivot('role', AttachmentService::ROLE_PAID)
            ->orderBy('attachments.id')
            ->get()
            ->map(fn (Attachment $a) => $this->downloadableRow($a, (int) $product->id, 'link'))
            ->values()
            ->all();

        return response()->json(['data' => $rows]);
    }

    public function downloadableLinksStore(Request $request, int $id): JsonResponse
    {
        return $this->storeDownloadable($request, $id, AttachmentService::ROLE_PAID, 'link');
    }

    public function downloadableLinksUpdate(Request $request, int $id, int $linkId): JsonResponse
    {
        return $this->updateDownloadable($request, $id, $linkId, AttachmentService::ROLE_PAID, 'link');
    }

    public function downloadableLinksDestroy(int $id, int $linkId): JsonResponse
    {
        return $this->destroyDownloadable($id, $linkId, AttachmentService::ROLE_PAID);
    }

    public function downloadableSamplesIndex(int $id): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        $product = Product::query()->find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'error' => 'not_found'], 404);
        }
        $rows = $product->attachments()
            ->wherePivot('role', AttachmentService::ROLE_DOCUMENT)
            ->orderBy('attachments.id')
            ->get()
            ->map(fn (Attachment $a) => $this->downloadableRow($a, (int) $product->id, 'sample'))
            ->values()
            ->all();

        return response()->json(['data' => $rows]);
    }

    public function downloadableSamplesStore(Request $request, int $id): JsonResponse
    {
        return $this->storeDownloadable($request, $id, AttachmentService::ROLE_DOCUMENT, 'sample');
    }

    public function downloadableSamplesUpdate(Request $request, int $id, int $sampleId): JsonResponse
    {
        return $this->updateDownloadable($request, $id, $sampleId, AttachmentService::ROLE_DOCUMENT, 'sample');
    }

    public function downloadableSamplesDestroy(int $id, int $sampleId): JsonResponse
    {
        return $this->destroyDownloadable($id, $sampleId, AttachmentService::ROLE_DOCUMENT);
    }

    public function customizableOptionsIndex(int $id): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        $product = Product::query()->with('customizationFields')->find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'error' => 'not_found'], 404);
        }
        $rows = app(CustomizationService::class)->present($product);
        $mapped = array_map(fn (array $row) => $this->customizableOptionRow($row, (int) $product->id), $rows);

        return response()->json(['data' => $mapped]);
    }

    public function customizableOptionsStore(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        $product = Product::query()->find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'error' => 'not_found'], 404);
        }
        $label = trim((string) ($request->input('label') ?? $request->input('title') ?? $request->input('name') ?? ''));
        if ($label === '') {
            return response()->json(['message' => 'label is required.', 'error' => 'validation'], 422);
        }
        $field = app(CustomizationService::class)->createField($product, $request->all());
        $presented = app(CustomizationService::class)->presentField($field);

        return response()->json(['data' => $this->customizableOptionRow($presented, (int) $product->id)], 201);
    }

    public function customizableOptionsUpdate(Request $request, int $id, int $optionId): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        $field = ProductCustomizationField::query()->where('product_id', $id)->whereKey($optionId)->first();
        if (! $field) {
            return response()->json(['message' => 'Customizable option not found.', 'error' => 'not_found'], 404);
        }
        $updated = app(CustomizationService::class)->updateField($field, $request->all());
        $presented = app(CustomizationService::class)->presentField($updated);

        return response()->json(['data' => $this->customizableOptionRow($presented, $id)]);
    }

    public function customizableOptionsDestroy(int $id, int $optionId): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        $field = ProductCustomizationField::query()->where('product_id', $id)->whereKey($optionId)->first();
        if (! $field) {
            return response()->json(['message' => 'Customizable option not found.', 'error' => 'not_found'], 404);
        }
        app(CustomizationService::class)->deleteField($field);

        return response()->json([
            'message' => 'Customizable option deleted successfully.',
            'data' => ['id' => $optionId],
        ]);
    }

    public function relatedProductsIndex(int $id): JsonResponse
    {
        return $this->relationsIndex($id, 'related');
    }

    public function relatedProductsUpdate(Request $request, int $id): JsonResponse
    {
        return $this->relationsReplace($request, $id, 'related');
    }

    public function upSellsIndex(int $id): JsonResponse
    {
        return $this->relationsIndex($id, 'upsell');
    }

    public function upSellsUpdate(Request $request, int $id): JsonResponse
    {
        return $this->relationsReplace($request, $id, 'upsell');
    }

    public function crossSellsIndex(int $id): JsonResponse
    {
        return $this->relationsIndex($id, 'crosssell');
    }

    public function crossSellsUpdate(Request $request, int $id): JsonResponse
    {
        return $this->relationsReplace($request, $id, 'crosssell');
    }

    public function bundleOptionsIndex(int $id): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        $product = Product::query()->find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'error' => 'not_found'], 404);
        }
        $components = ProductComponent::query()
            ->where('parent_product_id', $product->id)
            ->with('child.translations')
            ->orderBy('position')
            ->orderBy('id')
            ->get();
        $groups = [];
        foreach ($components as $c) {
            $key = (string) ($c->option_group ?: 'default');
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'id' => (int) $c->id,
                    'label' => $key === 'default' ? 'Options' : $key,
                    'type' => $c->is_required ? 'checkbox' : 'select',
                    'isRequired' => $c->is_required ? 1 : 0,
                    'sortOrder' => (int) ($c->position ?? 0),
                    'products' => [],
                ];
            }
            $groups[$key]['products'][] = [
                'id' => (int) $c->id,
                'productId' => (int) $c->child_product_id,
                'qty' => (int) ($c->quantity ?? 1),
                'isUserDefined' => $c->qty_editable ? 1 : 0,
                'isDefault' => $c->default_selected ? 1 : 0,
                'sortOrder' => (int) ($c->position ?? 0),
                'sku' => (string) ($c->child?->sku ?? ''),
                'name' => (string) ($c->child?->translation()?->name ?? $c->child?->sku ?? ''),
            ];
        }

        return response()->json(['data' => array_values($groups)]);
    }

    public function bundleOptionsUpdate(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        $product = Product::query()->find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'error' => 'not_found'], 404);
        }
        if (! in_array($product->type, ['bundle', 'group'], true)) {
            return response()->json(['message' => 'Bundle options require a bundle or group product.', 'error' => 'validation'], 422);
        }
        $rows = $this->normalizeCompositionRows($request);
        try {
            app(CompositionService::class)->sync($product, $rows);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => 'validation'], 422);
        }

        return $this->bundleOptionsIndex($id);
    }

    public function groupedProductsIndex(int $id): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        $product = Product::query()->find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'error' => 'not_found'], 404);
        }
        $rows = app(CompositionService::class)->present($product);
        $mapped = array_map(fn (array $row) => [
            'id' => (int) ($row['id'] ?? 0),
            'associatedProductId' => (int) ($row['id'] ?? 0),
            'qty' => (int) ($row['quantity'] ?? 1),
            'sortOrder' => 0,
            'sku' => (string) ($row['sku'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'price' => (float) ($row['selling_price'] ?? $row['price'] ?? 0),
            'isRequired' => ! empty($row['is_required']) ? 1 : 0,
        ], $rows);

        return response()->json(['data' => $mapped]);
    }

    public function groupedProductsUpdate(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        $product = Product::query()->find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'error' => 'not_found'], 404);
        }
        if ($product->type !== 'group') {
            if ($product->type !== 'bundle') {
                return response()->json(['message' => 'Grouped links require a group product.', 'error' => 'validation'], 422);
            }
        }
        $rows = $this->normalizeCompositionRows($request);
        try {
            app(CompositionService::class)->sync($product, $rows);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => 'validation'], 422);
        }

        return $this->groupedProductsIndex($id);
    }

    public function bookingProductsIndex(int $id): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        $product = Product::query()->find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'error' => 'not_found'], 404);
        }
        $relay = GraphqlProductComposition::bookingProducts($id, ['first' => 20]);
        $rows = collect($relay['edges'] ?? [])->map(function (array $edge) {
            $n = $edge['node'] ?? [];

            return [
                'id' => (int) ($n['_id'] ?? $n['id'] ?? 0),
                'type' => (string) ($n['type'] ?? 'default'),
                'qty' => (int) ($n['qty'] ?? 0),
                'location' => $n['location'] ?? null,
                'availableFrom' => $n['availableFrom'] ?? null,
                'availableTo' => $n['availableTo'] ?? null,
                'duration' => $n['duration'] ?? null,
                'slots' => $n['appointmentSlot'] ?? $n['rentalSlot'] ?? $n['defaultSlot'] ?? null,
                'startingPrice' => $n['startingPrice'] ?? null,
            ];
        })->values()->all();

        return response()->json(['data' => $rows]);
    }

    public function variantsIndex(int $id): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        $product = Product::query()->find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'error' => 'not_found'], 404);
        }
        $rows = app(CombinationService::class)->presentVariants($product);

        return response()->json(['data' => $rows]);
    }

    public function variantsUpdate(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        $product = Product::query()->find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'error' => 'not_found'], 404);
        }
        if (! $product->isCombination()) {
            return response()->json(['message' => 'Variants require a configurable/combination product.', 'error' => 'validation'], 422);
        }
        $variants = $request->input('variants') ?? $request->input('combination_variants') ?? $request->input('data') ?? [];
        if (! is_array($variants)) {
            return response()->json(['message' => 'variants list is required.', 'error' => 'validation'], 422);
        }
        $axes = app(CombinationService::class)->presentConfig($product);
        try {
            app(ProductService::class)->save([
                'product' => [],
                'combination_axes' => array_map(fn (array $axis) => [
                    'attribute_id' => $axis['attribute_id'],
                    'value_ids' => $axis['value_ids'],
                ], $axes),
                'combination_variants' => $variants,
                'remove_missing_combinations' => (bool) ($request->input('remove_missing_combinations') ?? false),
            ], $product);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => 'validation'], 422);
        }

        return $this->variantsIndex($id);
    }

    public function variantAxesIndex(int $id): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        $product = Product::query()->find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'error' => 'not_found'], 404);
        }
        $rows = app(CombinationService::class)->presentConfig($product);

        return response()->json(['data' => $rows]);
    }

    public function variantAxesUpdate(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        $product = Product::query()->find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'error' => 'not_found'], 404);
        }
        if (! $product->isCombination()) {
            return response()->json(['message' => 'Super attributes require a configurable/combination product.', 'error' => 'validation'], 422);
        }
        $axes = $request->input('combination_axes');
        if (! is_array($axes) || $axes === []) {
            $axes = $this->variantAxesToAxes(
                $request->input('variant_axes') ?? $request->input('variantAxes') ?? $request->input('data') ?? []
            );
        }
        if ($axes === []) {
            return response()->json(['message' => 'variant_axes are required.', 'error' => 'validation'], 422);
        }
        try {
            app(ProductService::class)->save([
                'product' => [],
                'combination_axes' => $axes,
                'combination_variants' => $request->input('combination_variants') ?? $request->input('variants') ?? [],
                'remove_missing_combinations' => (bool) ($request->input('remove_missing_combinations') ?? true),
            ], $product);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => 'validation'], 422);
        }

        return $this->variantAxesIndex($id);
    }

    public function bookingProductsUpdate(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        $product = Product::query()->find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'error' => 'not_found'], 404);
        }
        if (! $product->isService() && ! $product->isRental()) {
            return response()->json(['message' => 'Booking config requires a service or rental product.', 'error' => 'validation'], 422);
        }
        $booking = $request->input('booking') ?? $request->all();
        if (! is_array($booking)) {
            return response()->json(['message' => 'booking payload is required.', 'error' => 'validation'], 422);
        }
        $type = trim((string) ($booking['type'] ?? 'appointment'));
        if ($product->isService()) {
            $slots = is_array($booking['slots'] ?? null) ? $booking['slots'] : [];
            $availability = $booking['availability_config'] ?? null;
            if (! is_array($availability) || $availability === []) {
                $availability = $slots;
            }
            if (($type === 'appointment' || ($booking['service_type'] ?? '') === 'appointment')
                && (! is_array($availability['available_days'] ?? null) || $availability['available_days'] === [])) {
                $availability['available_days'] = ['mon', 'tue', 'wed', 'thu', 'fri'];
            }
            $config = [
                'service_type' => $type === 'appointment' ? 'appointment' : ($type === 'default' ? 'one_time' : $type),
                'duration' => $booking['duration'] ?? $slots['duration'] ?? null,
                'duration_unit' => $booking['duration_unit'] ?? 'minutes',
                'service_area' => $booking['location'] ?? $booking['service_area'] ?? null,
                'availability_config' => $availability,
            ];
            try {
                app(ServiceProductService::class)->syncProduct($product, array_filter(
                    $config,
                    fn ($v) => $v !== null && $v !== ''
                ));
            } catch (\Throwable $e) {
                return response()->json(['message' => $e->getMessage(), 'error' => 'validation'], 422);
            }
        }

        return $this->bookingProductsIndex($id);
    }

    public function customerGroupPricesIndex(int $id): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        if (! Product::query()->whereKey($id)->exists()) {
            return response()->json(['message' => 'Product not found.', 'error' => 'not_found'], 404);
        }
        $rows = ProductQtyPrice::query()
            ->where('product_id', $id)
            ->orderBy('position')
            ->orderBy('min_qty')
            ->get()
            ->map(fn (ProductQtyPrice $row) => $this->qtyPriceRow($row))
            ->values()
            ->all();

        return response()->json(['data' => $rows]);
    }

    public function customerGroupPricesStore(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        $product = Product::query()->find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'error' => 'not_found'], 404);
        }
        $qty = (float) ($request->input('qty') ?? $request->input('min_qty') ?? $request->input('minQty') ?? 0);
        if ($qty < 1) {
            return response()->json(['message' => 'qty must be greater than or equal to 1.', 'error' => 'validation'], 422);
        }
        $valueType = strtolower(trim((string) ($request->input('value_type') ?? $request->input('valueType') ?? 'fixed')));
        $value = (float) ($request->input('value') ?? $request->input('price') ?? -1);
        if ($value < 0) {
            return response()->json(['message' => 'value must be zero or greater.', 'error' => 'validation'], 422);
        }
        $price = $value;
        if ($valueType === 'discount') {
            $base = (float) ($product->price ?? 0);
            $price = max(0, round($base * (1 - ($value / 100)), 2));
        }
        $row = ProductQtyPrice::query()->create([
            'product_id' => $product->id,
            'min_qty' => $qty,
            'max_qty' => $request->input('max_qty', $request->input('maxQty')),
            'price' => $price,
            'position' => (int) ($request->input('position') ?? 0),
        ]);

        return response()->json(['data' => $this->qtyPriceRow($row, $valueType, $value)], 201);
    }

    public function customerGroupPricesUpdate(Request $request, int $id, int $priceId): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        $row = ProductQtyPrice::query()->where('product_id', $id)->whereKey($priceId)->first();
        if (! $row) {
            return response()->json(['message' => 'Customer group price not found.', 'error' => 'not_found'], 404);
        }
        if ($request->has('qty') || $request->has('min_qty') || $request->has('minQty')) {
            $qty = (float) ($request->input('qty') ?? $request->input('min_qty') ?? $request->input('minQty'));
            if ($qty < 1) {
                return response()->json(['message' => 'qty must be greater than or equal to 1.', 'error' => 'validation'], 422);
            }
            $row->min_qty = $qty;
        }
        if ($request->has('price') || $request->has('value')) {
            $row->price = max(0, (float) ($request->input('price') ?? $request->input('value')));
        }
        if ($request->has('position')) {
            $row->position = (int) $request->input('position');
        }
        $row->save();

        return response()->json(['data' => $this->qtyPriceRow($row)]);
    }

    public function customerGroupPricesDestroy(int $id, int $priceId): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        $row = ProductQtyPrice::query()->where('product_id', $id)->whereKey($priceId)->first();
        if (! $row) {
            return response()->json(['message' => 'Customer group price not found.', 'error' => 'not_found'], 404);
        }
        $row->delete();

        return response()->json(['message' => 'Customer group price deleted successfully.', 'data' => ['id' => $priceId]]);
    }

    public function categoriesIndex(Request $request): JsonResponse
    {
        if ($denied = $this->guardCategories()) {
            return $denied;
        }
        $limit = min(100, max(1, (int) $request->query('limit', $request->query('per_page', 50))));
        $rows = Category::query()->with('translations')->orderBy('position')->orderBy('id')->limit($limit)->get()
            ->map(fn (Category $c) => $this->categoryRow($c))
            ->values()
            ->all();

        return response()->json(['data' => $rows]);
    }

    public function categoriesTree(Request $request): JsonResponse
    {
        if ($denied = $this->guardCategories()) {
            return $denied;
        }
        $status = $request->has('status') ? ((int) $request->query('status') === 1) : null;
        $rootId = (int) ($request->query('rootId') ?? $request->query('root_id') ?? 0);
        $q = Category::query()->with('translations')->orderBy('position')->orderBy('id');
        if ($status !== null) {
            $q->where('is_enabled', $status);
        }
        $all = $q->get();
        $byParent = [];
        foreach ($all as $cat) {
            $pid = $cat->parent_id ? (int) $cat->parent_id : 0;
            $byParent[$pid][] = $cat;
        }
        $build = function (int $parentId) use (&$build, &$byParent): array {
            $nodes = [];
            foreach ($byParent[$parentId] ?? [] as $cat) {
                $row = $this->categoryRow($cat);
                $row['children'] = $build((int) $cat->id);
                $nodes[] = $row;
            }

            return $nodes;
        };
        $roots = $rootId > 0 ? $build($rootId) : $build(0);

        return response()->json(['data' => $roots]);
    }

    public function categoriesShow(int $id): JsonResponse
    {
        if ($denied = $this->guardCategories()) {
            return $denied;
        }
        $category = Category::query()->with('translations')->find($id);
        if (! $category) {
            return response()->json(['message' => 'Category not found.', 'error' => 'not_found'], 404);
        }

        return response()->json(['data' => $this->categoryRow($category)]);
    }

    public function categoriesStore(Request $request): JsonResponse
    {
        if ($denied = $this->guardCategories()) {
            return $denied;
        }
        $name = trim((string) ($request->input('name') ?? ''));
        if ($name === '') {
            return response()->json(['message' => 'Name is required.', 'error' => 'validation'], 422);
        }
        $slug = trim((string) ($request->input('slug') ?? ''));
        if ($slug === '') {
            $slug = Str::slug($name) ?: ('category-'.Str::lower(Str::random(6)));
        }
        $parentId = $request->input('parent_id', $request->input('parentId'));
        try {
            $row = app(CategoryService::class)->save([
                'parent_id' => $parentId === null || $parentId === '' ? null : (int) $parentId,
                'is_enabled' => $request->boolean('status', $request->boolean('enabled', true)),
                'name' => $name,
                'slug' => $slug,
                'description' => $request->input('description'),
                'language_code' => Language::defaultCode(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => 'create_error'], 422);
        }

        return response()->json(['data' => $this->categoryRow($row->fresh('translations') ?? $row)], 201);
    }

    public function categoriesUpdate(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guardCategories()) {
            return $denied;
        }
        $category = Category::query()->find($id);
        if (! $category) {
            return response()->json(['message' => 'Category not found.', 'error' => 'not_found'], 404);
        }
        $locale = Language::defaultCode();
        $tr = $category->translation($locale);
        $name = trim((string) ($request->input('name') ?? $tr?->name ?? ''));
        if ($name === '') {
            return response()->json(['message' => 'Name is required.', 'error' => 'validation'], 422);
        }
        $slug = trim((string) ($request->input('slug') ?? $tr?->slug ?? ''));
        if ($slug === '') {
            $slug = Str::slug($name) ?: ('category-'.Str::lower(Str::random(6)));
        }
        try {
            $row = app(CategoryService::class)->save([
                'parent_id' => $category->parent_id,
                'is_enabled' => $request->has('status') || $request->has('enabled')
                    ? $request->boolean('status', $request->boolean('enabled'))
                    : (bool) $category->is_enabled,
                'name' => $name,
                'slug' => $slug,
                'description' => $request->has('description') ? $request->input('description') : $tr?->description,
                'language_code' => $locale,
            ], $category);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => 'update_error'], 422);
        }

        return response()->json(['data' => $this->categoryRow($row->fresh('translations') ?? $row)]);
    }

    public function categoriesDestroy(int $id): JsonResponse
    {
        if ($denied = $this->guardCategories()) {
            return $denied;
        }
        $category = Category::query()->find($id);
        if (! $category) {
            return response()->json(['message' => 'Category not found.', 'error' => 'not_found'], 404);
        }
        try {
            app(CategoryService::class)->delete($category);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => 'delete_error'], 422);
        }

        return response()->json(['message' => 'Category deleted successfully.', 'data' => ['id' => $id]]);
    }

    public function categoriesBulkUpdate(Request $request): JsonResponse
    {
        if ($denied = $this->guardCategories()) {
            return $denied;
        }
        $ids = collect($request->input('ids')  ?? [])
            ->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        if ($ids === []) {
            return response()->json(['message' => 'At least one category id is required.', 'error' => 'validation'], 422);
        }
        $value = $request->input('value', $request->input('status'));
        $enabled = is_bool($value) ? $value : ((int) $value === 1);
        $updated = Category::query()->whereIn('id', $ids)->update(['is_enabled' => $enabled]);

        return response()->json([
            'message' => 'Categories updated successfully.',
            'data' => ['updated_count' => (int) $updated],
        ]);
    }

    public function categoriesBulkDestroy(Request $request): JsonResponse
    {
        if ($denied = $this->guardCategories()) {
            return $denied;
        }
        $ids = collect($request->input('ids')  ?? [])
            ->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        if ($ids === []) {
            return response()->json(['message' => 'At least one category id is required.', 'error' => 'validation'], 422);
        }
        $deleted = 0;
        $service = app(CategoryService::class);
        foreach (Category::query()->whereIn('id', $ids)->get() as $row) {
            try {
                $service->delete($row);
                $deleted++;
            } catch (\Throwable) {
            }
        }

        return response()->json([
            'message' => 'Categories deleted successfully.',
            'data' => ['deleted_count' => $deleted],
        ]);
    }

    public function attributesIndex(Request $request): JsonResponse
    {
        if ($denied = $this->guardFeatures()) {
            return $denied;
        }
        $limit = min(100, max(1, (int) $request->query('limit', $request->query('per_page', 50))));
        $rows = Feature::query()->with('translations')->orderBy('position')->orderBy('id')->limit($limit)->get()
            ->map(fn (Feature $f) => $this->attributeRow($f))
            ->values()
            ->all();

        return response()->json(['data' => $rows]);
    }

    public function attributesShow(int $id): JsonResponse
    {
        if ($denied = $this->guardFeatures()) {
            return $denied;
        }
        $feature = Feature::query()->with(['translations', 'values.translations'])->find($id);
        if (! $feature) {
            return response()->json(['message' => 'Attribute not found.', 'error' => 'not_found'], 404);
        }

        return response()->json(['data' => $this->attributeRow($feature, true)]);
    }

    public function attributesStore(Request $request): JsonResponse
    {
        if ($denied = $this->guardFeatures()) {
            return $denied;
        }
        $code = Str::slug(trim((string) ($request->input('code') ?? '')), '_');
        $name = trim((string) ($request->input('name') ?? $request->input('admin_name') ?? ''));
        if ($code === '' || $name === '') {
            return response()->json(['message' => 'code and name are required.', 'error' => 'validation'], 422);
        }
        try {
            $row = app(FeatureService::class)->saveFeature([
                'code' => $code,
                'type' => (string) ($request->input('type') ?? 'select'),
                'is_filterable' => $request->boolean('is_filterable', $request->boolean('isFilterable')),
                'used_for_combinations' => $request->boolean('used_for_combinations', $request->boolean('isConfigurable')),
                'is_enabled' => $request->boolean('status', $request->boolean('enabled', true)),
                'translations' => [
                    Language::defaultCode() => ['name' => $name],
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => 'create_error'], 422);
        }

        return response()->json(['data' => $this->attributeRow($row->fresh('translations') ?? $row)], 201);
    }

    public function attributesUpdate(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guardFeatures()) {
            return $denied;
        }
        $feature = Feature::query()->find($id);
        if (! $feature) {
            return response()->json(['message' => 'Attribute not found.', 'error' => 'not_found'], 404);
        }
        $payload = [];
        if ($request->has('type')) {
            $payload['type'] = (string) $request->input('type');
        }
        if ($request->has('is_filterable') || $request->has('isFilterable')) {
            $payload['is_filterable'] = $request->boolean('is_filterable', $request->boolean('isFilterable'));
        }
        if ($request->has('status') || $request->has('enabled')) {
            $payload['is_enabled'] = $request->boolean('status', $request->boolean('enabled'));
        }
        $name = trim((string) ($request->input('name') ?? $request->input('admin_name') ?? ''));
        if ($name !== '') {
            $payload['translations'] = [Language::defaultCode() => ['name' => $name]];
        }
        try {
            $row = app(FeatureService::class)->saveFeature($payload, $feature);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => 'update_error'], 422);
        }

        return response()->json(['data' => $this->attributeRow($row->fresh('translations') ?? $row)]);
    }

    public function attributesDestroy(int $id): JsonResponse
    {
        if ($denied = $this->guardFeatures()) {
            return $denied;
        }
        $feature = Feature::query()->find($id);
        if (! $feature) {
            return response()->json(['message' => 'Attribute not found.', 'error' => 'not_found'], 404);
        }
        $assigned = (int) DB::table('product_feature_values')->where('feature_id', $feature->id)->distinct()->count('product_id');
        if ($assigned > 0) {
            return response()->json(['message' => 'Attributes assigned to products cannot be deleted.', 'error' => 'delete_error'], 422);
        }
        try {
            app(FeatureService::class)->deleteFeature($feature);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => 'delete_error'], 422);
        }

        return response()->json(['message' => 'Attribute deleted successfully.', 'data' => ['id' => $id]]);
    }

    public function attributesBulkDestroy(Request $request): JsonResponse
    {
        if ($denied = $this->guardFeatures()) {
            return $denied;
        }
        $ids = collect($request->input('ids')  ?? [])
            ->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        if ($ids === []) {
            return response()->json(['message' => 'At least one attribute id is required.', 'error' => 'validation'], 422);
        }
        $deleted = 0;
        $service = app(FeatureService::class);
        foreach (Feature::query()->whereIn('id', $ids)->get() as $row) {
            $assigned = (int) DB::table('product_feature_values')->where('feature_id', $row->id)->distinct()->count('product_id');
            if ($assigned > 0) {
                continue;
            }
            try {
                $service->deleteFeature($row);
                $deleted++;
            } catch (\Throwable) {
                // skip
            }
        }

        return response()->json([
            'message' => 'Attributes deleted successfully.',
            'data' => ['deleted_count' => $deleted],
        ]);
    }

    public function attributeOptionsStore(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guardFeatures()) {
            return $denied;
        }
        $feature = Feature::query()->find($id);
        if (! $feature) {
            return response()->json(['message' => 'Attribute not found.', 'error' => 'not_found'], 404);
        }
        $label = trim((string) ($request->input('label') ?? $request->input('admin_name') ?? $request->input('name') ?? ''));
        $code = Str::slug(trim((string) ($request->input('code') ?? $label)), '_');
        if ($code === '' || $label === '') {
            return response()->json(['message' => 'code and label are required.', 'error' => 'validation'], 422);
        }
        try {
            $value = app(FeatureService::class)->saveValue([
                'feature_id' => $feature->id,
                'code' => $code,
                'is_enabled' => $request->boolean('status', $request->boolean('enabled', true)),
                'translations' => [
                    Language::defaultCode() => ['value' => $label],
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => 'create_error'], 422);
        }

        return response()->json(['data' => $this->optionRow($value, (int) $feature->id)], 201);
    }

    public function attributeOptionsUpdate(Request $request, int $id, int $optionId): JsonResponse
    {
        if ($denied = $this->guardFeatures()) {
            return $denied;
        }
        $value = FeatureValue::query()->where('feature_id', $id)->whereKey($optionId)->first();
        if (! $value) {
            return response()->json(['message' => 'Attribute option not found.', 'error' => 'not_found'], 404);
        }
        $payload = ['feature_id' => $id];
        if ($request->has('status') || $request->has('enabled')) {
            $payload['is_enabled'] = $request->boolean('status', $request->boolean('enabled'));
        }
        $label = trim((string) ($request->input('label') ?? $request->input('admin_name') ?? $request->input('name') ?? ''));
        if ($label !== '') {
            $payload['translations'] = [Language::defaultCode() => ['value' => $label]];
        }
        try {
            $value = app(FeatureService::class)->saveValue($payload, $value);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => 'update_error'], 422);
        }

        return response()->json(['data' => $this->optionRow($value, $id)]);
    }

    public function attributeOptionsDestroy(int $id, int $optionId): JsonResponse
    {
        if ($denied = $this->guardFeatures()) {
            return $denied;
        }
        $value = FeatureValue::query()->where('feature_id', $id)->whereKey($optionId)->first();
        if (! $value) {
            return response()->json(['message' => 'Attribute option not found.', 'error' => 'not_found'], 404);
        }
        try {
            app(FeatureService::class)->deleteValue($value);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => 'delete_error'], 422);
        }

        return response()->json(['message' => 'Attribute option deleted successfully.', 'data' => ['id' => $optionId]]);
    }

    public function familiesIndex(Request $request): JsonResponse
    {
        if ($denied = $this->guardFeatures()) {
            return $denied;
        }
        $limit = min(100, max(1, (int) $request->query('limit', $request->query('per_page', 50))));
        $rows = ProductTemplate::query()->orderByDesc('id')->limit($limit)->get()
            ->map(fn (ProductTemplate $f) => $this->familyRow($f))
            ->values()
            ->all();

        return response()->json(['data' => $rows]);
    }

    public function familiesShow(int $id): JsonResponse
    {
        if ($denied = $this->guardFeatures()) {
            return $denied;
        }
        $family = ProductTemplate::query()->find($id);
        if (! $family) {
            return response()->json(['message' => 'Product template not found.', 'error' => 'not_found'], 404);
        }

        return response()->json(['data' => $this->familyRow($family, true)]);
    }

    public function familiesStore(Request $request): JsonResponse
    {
        if ($denied = $this->guardFeatures()) {
            return $denied;
        }
        $code = Str::slug(trim((string) ($request->input('code') ?? '')), '_');
        $name = trim((string) ($request->input('name') ?? ''));
        if ($code === '' || $name === '') {
            return response()->json(['message' => 'code and name are required.', 'error' => 'validation'], 422);
        }
        if (ProductTemplate::query()->where('code', $code)->exists()) {
            return response()->json(['message' => 'Product template code already exists.', 'error' => 'validation'], 422);
        }
        $isDefault = $request->boolean('is_default', $request->boolean('isDefault'));
        if ($request->has('template_groups') || $request->has('templateGroups')) {
            $groups = $request->input('template_groups') ?? $request->input('templateGroups');
            if (($err = $this->invalidTemplateGroups($groups)) !== null) {
                return $err;
            }
        } else {
            $groups = [];
        }
        if ($isDefault) {
            ProductTemplate::query()->where('is_default', true)->update(['is_default' => false]);
        } elseif (! ProductTemplate::query()->where('is_default', true)->exists()) {
            // First family becomes default when none exists yet.
            $isDefault = true;
        }
        $row = ProductTemplate::query()->create([
            'code' => $code,
            'name' => $name,
            'is_default' => $isDefault,
            'template_groups' => is_array($groups) ? $groups : [],
        ]);

        return response()->json(['data' => $this->familyRow($row, true)], 201);
    }

    public function familiesUpdate(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guardFeatures()) {
            return $denied;
        }
        $family = ProductTemplate::query()->find($id);
        if (! $family) {
            return response()->json(['message' => 'Product template not found.', 'error' => 'not_found'], 404);
        }
        if ($request->filled('name')) {
            $family->name = trim((string) $request->input('name'));
        }
        if ($request->has('is_default') || $request->has('isDefault')) {
            $isDefault = $request->boolean('is_default', $request->boolean('isDefault'));
            if (! $isDefault && $family->is_default) {
                $otherDefaults = ProductTemplate::query()
                    ->where('id', '!=', $family->id)
                    ->where('is_default', true)
                    ->exists();
                if (! $otherDefaults) {
                    return response()->json([
                        'message' => 'Cannot clear the last default product template.',
                        'error' => 'validation',
                    ], 422);
                }
            }
            if ($isDefault) {
                ProductTemplate::query()->where('id', '!=', $family->id)->where('is_default', true)->update(['is_default' => false]);
            }
            $family->is_default = $isDefault;
        }
        if ($request->has('template_groups') || $request->has('templateGroups')) {
            $groups = $request->input('template_groups') ?? $request->input('templateGroups');
            if (($err = $this->invalidTemplateGroups($groups)) !== null) {
                return $err;
            }
            $family->template_groups = $groups;
        }
        $family->save();

        return response()->json(['data' => $this->familyRow($family, true)]);
    }

    public function familiesDestroy(int $id): JsonResponse
    {
        if ($denied = $this->guardFeatures()) {
            return $denied;
        }
        $family = ProductTemplate::query()->find($id);
        if (! $family) {
            return response()->json(['message' => 'Product template not found.', 'error' => 'not_found'], 404);
        }
        if ($family->is_default) {
            return response()->json(['message' => 'Default product template cannot be deleted.', 'error' => 'delete_error'], 422);
        }
        $family->delete();

        return response()->json(['message' => 'Product template deleted successfully.', 'data' => ['id' => $id]]);
    }

    /**
     * @return JsonResponse|null
     */
    private function invalidTemplateGroups(mixed $groups): ?JsonResponse
    {
        if (! is_array($groups)) {
            return response()->json([
                'message' => 'template_groups must be an array of group objects.',
                'error' => 'validation',
            ], 422);
        }
        foreach ($groups as $index => $group) {
            if (! is_array($group)) {
                return response()->json([
                    'message' => 'template_groups['.$index.'] must be an object.',
                    'error' => 'validation',
                ], 422);
            }
            $name = trim((string) ($group['name'] ?? $group['code'] ?? ''));
            if ($name === '') {
                return response()->json([
                    'message' => 'template_groups['.$index.'] requires a name or code.',
                    'error' => 'validation',
                ], 422);
            }
            if (array_key_exists('attributes', $group) && ! is_array($group['attributes'])) {
                return response()->json([
                    'message' => 'template_groups['.$index.'].attributes must be an array.',
                    'error' => 'validation',
                ], 422);
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeProductType(string $type): string
    {
        return match (strtolower(trim($type))) {
            'configurable', 'config' => 'combination',
            'grouped' => 'group',
            'booking' => 'service',
            default => $type,
        };
    }

    private function productTypeKey(string $type): string
    {
        return match ($type) {
            'combination' => 'configurable',
            'group' => 'grouped',
            'service' => 'booking',
            default => $type,
        };
    }

    /**
     * @return list<int>
     */
    private function normalizeCategoryIds(Request $request): array
    {
        $raw = $request->input('categories')
            ?? $request->input('category_ids')
            ?? $request->input('categoryIds')
            ?? $request->input('ids')
            ?? $request->input('ids')
            ?? [];
        if (! is_array($raw)) {
            $raw = [(int) $raw];
        }

        return collect($raw)
            ->map(fn ($v) => (int) (is_array($v) ? ($v['id'] ?? 0) : $v))
            ->filter(fn ($v) => $v > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     *
     * @param  array<string|int, mixed>  $super
     * @return list<array{attribute_id: int, value_ids: list<int>}>
     */
    private function variantAxesToAxes(array $super): array
    {
        $axes = [];
        $position = 0;
        foreach ($super as $key => $optionIds) {
            if (! is_array($optionIds)) {
                continue;
            }
            $ids = collect($optionIds)->map(fn ($v) => (int) $v)->filter(fn ($v) => $v > 0)->unique()->values()->all();
            if ($ids === []) {
                continue;
            }
            $attribute = null;
            if (is_numeric($key)) {
                $attribute = CatalogAttribute::query()->find((int) $key);
            }
            if (! $attribute && is_string($key) && $key !== '') {
                $attribute = CatalogAttribute::query()->where('code', $key)->first();
            }
            if (! $attribute) {
                $fromValue = AttributeValue::query()->whereIn('id', $ids)->first();
                if ($fromValue) {
                    $attribute = CatalogAttribute::query()->find($fromValue->attribute_id);
                }
            }
            if (! $attribute) {
                continue;
            }
            $axes[] = [
                'attribute_id' => (int) $attribute->id,
                'value_ids' => $ids,
                'position' => $position++,
            ];
        }

        return $axes;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeCompositionRows(Request $request): array
    {
        $raw = $request->input('products')
            ?? $request->input('links')
            ?? $request->input('components')
            ?? $request->input('bundle_options')
            ?? $request->input('bundleOptions')
            ?? $request->input('data')
            ?? [];
        if (! is_array($raw)) {
            return [];
        }
        // Flatten style option groups → component rows
        $flat = [];
        foreach (array_values($raw) as $item) {
            if (! is_array($item)) {
                continue;
            }
            if (isset($item['products']) && is_array($item['products'])) {
                $group = (string) ($item['label'] ?? $item['option_group'] ?? 'default');
                foreach (array_values($item['products']) as $child) {
                    if (! is_array($child)) {
                        continue;
                    }
                    $child['option_group'] = $group;
                    $flat[] = $child;
                }
                continue;
            }
            $flat[] = $item;
        }
        $rows = [];
        foreach ($flat as $row) {
            $childId = (int) ($row['child_product_id']
                ?? $row['associated_product_id']
                ?? $row['associatedProductId']
                ?? $row['product_id']
                ?? $row['productId']
                ?? $row['id']
                ?? 0);
            if ($childId < 1) {
                continue;
            }
            $rows[] = [
                'child_product_id' => $childId,
                'quantity' => (int) ($row['quantity'] ?? $row['qty'] ?? 1),
                'qty_editable' => (bool) ($row['qty_editable'] ?? $row['isUserDefined'] ?? true),
                'is_required' => (bool) ($row['is_required'] ?? $row['isRequired'] ?? true),
                'default_selected' => (bool) ($row['default_selected'] ?? $row['isDefault'] ?? true),
                'option_group' => $row['option_group'] ?? $row['optionGroup'] ?? null,
                'min_quantity' => $row['min_quantity'] ?? null,
                'max_quantity' => $row['max_quantity'] ?? null,
            ];
        }

        return $rows;
    }

    private function relationsIndex(int $id, string $type): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        $product = Product::query()->find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'error' => 'not_found'], 404);
        }
        $rows = $product->relationsOfType($type)->with('translations')->orderBy('products.id')->get()
            ->map(fn (Product $p) => $this->productRow($p))
            ->values()
            ->all();

        return response()->json(['data' => $rows]);
    }

    private function relationsReplace(Request $request, int $id, string $type): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        $product = Product::query()->find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'error' => 'not_found'], 404);
        }
        $ids = collect($request->input('ids')
            ?? $request->input('ids')
            ?? $request->input('product_ids')
            ?? $request->input('products')
            ?? [])
            ->map(fn ($v) => (int) (is_array($v) ? ($v['id'] ?? 0) : $v))
            ->filter(fn ($v) => $v > 0 && $v !== (int) $product->id)
            ->unique()
            ->values()
            ->all();
        DB::table('product_relations')->where('product_id', $product->id)->where('type', $type)->delete();
        foreach ($ids as $relatedId) {
            if (! Product::query()->whereKey($relatedId)->exists()) {
                continue;
            }
            DB::table('product_relations')->insert([
                'product_id' => $product->id,
                'related_product_id' => $relatedId,
                'type' => $type,
            ]);
        }

        return $this->relationsIndex($id, $type);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function customizableOptionRow(array $row, int $productId): array
    {
        $compatType = match ((string) ($row['type'] ?? 'text')) {
            'text' => 'field',
            'textarea' => 'area',
            'select' => 'drop_down',
            default => (string) ($row['type'] ?? 'text'),
        };

        return [
            'id' => (int) ($row['id'] ?? 0),
            'type' => $compatType,
            'label' => (string) ($row['label'] ?? ''),
            'isRequired' => ! empty($row['is_required']) ? 1 : 0,
            'sortOrder' => (int) ($row['position'] ?? 0),
            'maxCharacters' => isset($row['max_length']) ? (int) $row['max_length'] : null,
            'price' => (float) ($row['price_amount'] ?? 0),
            'priceType' => (string) ($row['price_type'] ?? 'none'),
            'options' => array_values($row['options'] ?? []),
            'productId' => $productId,
            'product' => '/api/v1/console/products/'.$productId,
        ];
    }

    private function storeDownloadable(Request $request, int $id, string $role, string $kind): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        $product = Product::query()->find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'error' => 'not_found'], 404);
        }
        if ($role === AttachmentService::ROLE_PAID && ! AttachmentService::productAllowsPaidRole($product)) {
            return response()->json([
                'message' => 'Paid downloadable links require a virtual, downloadable, or service product.',
                'error' => 'validation',
            ], 422);
        }
        $file = $request->file('file') ?? $request->file('attachment') ?? $request->file('link');
        if (! $file) {
            return response()->json(['message' => 'file is required.', 'error' => 'validation'], 422);
        }
        $title = trim((string) ($request->input('title') ?? $request->input('name') ?? $request->input('label') ?? '')) ?: null;
        try {
            $attachment = app(AttachmentService::class)->createAndAttach($product, $file, $title, null, $role);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => 'upload_error'], 422);
        }

        return response()->json(['data' => $this->downloadableRow($attachment, (int) $product->id, $kind)], 201);
    }

    private function updateDownloadable(Request $request, int $id, int $attachmentId, string $role, string $kind): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        $product = Product::query()->find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'error' => 'not_found'], 404);
        }
        $attachment = $product->attachments()
            ->wherePivot('role', $role)
            ->where('attachments.id', $attachmentId)
            ->first();
        if (! $attachment) {
            return response()->json(['message' => 'Downloadable '.$kind.' not found.', 'error' => 'not_found'], 404);
        }
        $data = [];
        if ($request->filled('title') || $request->filled('name') || $request->filled('label')) {
            $data['name'] = trim((string) ($request->input('title') ?? $request->input('name') ?? $request->input('label') ?? ''));
        }
        if ($request->filled('description')) {
            $data['description'] = trim((string) $request->input('description'));
        }
        $file = $request->file('file') ?? $request->file('attachment') ?? $request->file('link');
        try {
            $updated = app(AttachmentService::class)->update($attachment, $data, $file);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => 'update_error'], 422);
        }

        return response()->json(['data' => $this->downloadableRow($updated, (int) $product->id, $kind)]);
    }

    private function destroyDownloadable(int $id, int $attachmentId, string $role): JsonResponse
    {
        if ($denied = $this->guardProducts()) {
            return $denied;
        }
        $product = Product::query()->find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'error' => 'not_found'], 404);
        }
        $attachment = $product->attachments()
            ->wherePivot('role', $role)
            ->where('attachments.id', $attachmentId)
            ->first();
        if (! $attachment) {
            return response()->json(['message' => 'Downloadable resource not found.', 'error' => 'not_found'], 404);
        }
        try {
            app(AttachmentService::class)->delete($attachment);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => 'delete_error'], 422);
        }

        return response()->json([
            'message' => 'Downloadable resource deleted successfully.',
            'data' => ['id' => $attachmentId],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function downloadableRow(Attachment $a, int $productId, string $kind): array
    {
        $url = null;
        if (is_string($a->storage_path ?? null) && $a->storage_path !== '') {
            $url = '/storage/'.ltrim((string) $a->storage_path, '/');
        }
        $title = (string) ($a->name ?: $a->original_filename ?: 'Download');

        return [
            'id' => (int) $a->id,
            'type' => $kind === 'sample' ? 'sample' : 'file',
            'title' => $title,
            'file' => (string) ($a->original_filename ?? $a->stored_filename ?? ''),
            'fileName' => (string) ($a->original_filename ?? $a->stored_filename ?? ''),
            'fileUrl' => $url,
            'url' => $url,
            'sortOrder' => 0,
            'productId' => $productId,
            'product' => '/api/v1/console/products/'.$productId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function videoRow(Product $product): array
    {
        $video = trim((string) ($product->video_url ?? ''));

        return [
            'id' => (int) $product->id,
            'type' => 'videos',
            'path' => $video,
            'video_url' => $video,
            'productId' => (int) $product->id,
            'position' => 0,
            'publicPath' => $video !== '' && str_starts_with($video, 'http') ? $video : ($video !== '' ? url($video) : null),
            'fileName' => $video !== '' ? basename(parse_url($video, PHP_URL_PATH) ?: $video) : null,
        ];
    }

    private function optionRow(FeatureValue $value, int $attributeId): array
    {
        $label = $value->translation(Language::defaultCode())?->value
            ?? $value->translations->first()?->value
            ?? $value->code;

        return [
            'id' => (int) $value->id,
            'attributeId' => $attributeId,
            'code' => (string) $value->code,
            'label' => (string) $label,
            'status' => $value->is_enabled ? 1 : 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attributeRow(Feature $f, bool $withDetail = false): array
    {
        $name = $f->translation(Language::defaultCode())?->name
            ?? $f->translations->first()?->name
            ?? $f->code;
        $row = [
            'id' => (int) $f->id,
            'code' => (string) $f->code,
            'type' => (string) ($f->type ?? 'select'),
            'name' => (string) $name,
            'adminName' => (string) $name,
            'status' => $f->is_enabled ? 1 : 0,
            'isFilterable' => (bool) $f->is_filterable,
            'isConfigurable' => (bool) $f->used_for_combinations,
        ];
        if ($withDetail) {
            $f->loadMissing('values.translations');
            $row['options'] = $f->values->map(function ($value) use ($f) {
                $label = $value->translation(Language::defaultCode())?->value
                    ?? $value->translations->first()?->value
                    ?? $value->code;

                return [
                    'id' => (int) $value->id,
                    'attributeId' => (int) $f->id,
                    'code' => (string) $value->code,
                    'label' => (string) $label,
                    'status' => $value->is_enabled ? 1 : 0,
                ];
            })->values()->all();
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function familyRow(ProductTemplate $f, bool $withGroups = false): array
    {
        return [
            'id' => (int) $f->id,
            'code' => (string) $f->code,
            'name' => (string) $f->name,
            'isDefault' => (bool) $f->is_default,
            'templateGroups' => $withGroups ? (is_array($f->template_groups) ? $f->template_groups : []) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function qtyPriceRow(ProductQtyPrice $row, string $valueType = 'fixed', ?float $value = null): array
    {
        $price = (float) $row->price;
        $qty = (float) $row->min_qty;

        return [
            'id' => (int) $row->id,
            'productId' => (int) $row->product_id,
            'qty' => $qty,
            'minQty' => $qty,
            'maxQty' => $row->max_qty !== null ? (float) $row->max_qty : null,
            'price' => $price,
            'value' => $value ?? $price,
            'valueType' => $valueType,
            'position' => (int) $row->position,
            'customerGroupId' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function imageRow(ProductImage $img, int $productId): array
    {
        return [
            'id' => (int) $img->id,
            'productId' => $productId,
            'path' => (string) ($img->path ?? ''),
            'url' => (string) ($img->url ?? ''),
            'position' => (int) ($img->position ?? 0),
            'altText' => $img->alt !== null ? (string) $img->alt : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function productRow(Product $p): array
    {
        $locale = app()->getLocale();
        $name = $p->translation($locale)?->name;
        $price = $p->price !== null ? (float) $p->price : null;
        $qty = $p->stock_qty !== null ? (float) $p->stock_qty : null;

        $type = (string) ($p->type ?? 'simple');

        return [
            'id' => (int) $p->id,
            'sku' => (string) $p->sku,
            'name' => (string) ($name ?? ''),
            'type' => $this->productTypeKey($type),
            'typeNative' => $type,
            'price' => $price,
            'formattedPrice' => $price !== null ? number_format($price, 2, '.', '') : null,
            'status' => $p->is_enabled ? 1 : 0,
            'quantity' => $qty,
            'stock_qty' => $qty,
            'defaultCategoryId' => $p->default_category_id ? (int) $p->default_category_id : null,
            'createdAt' => optional($p->created_at)?->toIso8601String(),
            'updatedAt' => optional($p->updated_at)?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function categoryRow(Category $c): array
    {
        $locale = app()->getLocale();

        return [
            'id' => (int) $c->id,
            'name' => (string) ($c->translation($locale)?->name ?? ''),
            'status' => $c->is_enabled ? 1 : 0,
            'position' => (int) ($c->position ?? 0),
            'parentId' => $c->parent_id ? (int) $c->parent_id : null,
        ];
    }

    private function guardProducts(): ?JsonResponse
    {
        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin) {
            return response()->json(['message' => 'Unauthenticated.', 'error' => 'unauthenticated'], 401);
        }
        if (! $admin->hasPermission('catalog.products') && ! $admin->hasPermission('catalog.manage')) {
            return response()->json(['message' => 'Forbidden.', 'error' => 'forbidden'], 403);
        }

        return null;
    }

    private function guardCategories(): ?JsonResponse
    {
        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin) {
            return response()->json(['message' => 'Unauthenticated.', 'error' => 'unauthenticated'], 401);
        }
        if (! $admin->hasPermission('catalog.categories') && ! $admin->hasPermission('catalog.manage')) {
            return response()->json(['message' => 'Forbidden.', 'error' => 'forbidden'], 403);
        }

        return null;
    }

    private function guardFeatures(): ?JsonResponse
    {
        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin) {
            return response()->json(['message' => 'Unauthenticated.', 'error' => 'unauthenticated'], 401);
        }
        if (! $admin->hasPermission('catalog.features') && ! $admin->hasPermission('catalog.manage')) {
            return response()->json(['message' => 'Forbidden.', 'error' => 'forbidden'], 403);
        }

        return null;
    }
}
