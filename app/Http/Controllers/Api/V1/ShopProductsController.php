<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\GraphqlProductComposition;
use App\Domain\Api\Graphql\GraphqlProductConfigurable;
use App\Domain\Api\Graphql\GraphqlShopAttributes;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductComponent;
use App\Domain\Catalog\Models\ProductCustomizationField;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Services\AttachmentService;
use App\Domain\Catalog\Services\CatalogPresenter;
use App\Domain\Catalog\Services\CombinationService;
use App\Domain\Catalog\Services\CustomizationService;
use App\Domain\Catalog\Support\CatalogMediaUrl;
use App\Domain\Customer\Models\Customer;
use App\Domain\Localization\Money;
use App\Domain\Settings\ProductSettings;
use App\Domain\Store\Models\Store;
use App\Domain\Store\StoreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Shop REST GET /api/v1/store/products[+ /{id}].
 */
class ShopProductsController extends Controller
{
    public function __construct(private readonly CatalogPresenter $present) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) ($request->query('itemsPerPage')
            ?? $request->query('items_per_page')
            ?? $request->query('per_page', 30))));
        $page = max(1, (int) $request->query('page', 1));
        $locale = app()->getLocale();

        $q = Product::query()->enabled()->with(['translations', 'images']);
        $query = trim((string) $request->query('query', $request->query('q', '')));
        $name = trim((string) $request->query('name', ''));
        if ($query !== '' || $name !== '') {
            $like = '%'.($query !== '' ? $query : $name).'%';
            $q->where(function ($inner) use ($like) {
                $inner->where('sku', 'like', $like)
                    ->orWhereHas('translations', fn ($t) => $t->where('name', 'like', $like));
            });
        }
        $type = trim((string) $request->query('type', ''));
        if ($type !== '') {
            $q->where('type', $type);
        }
        $categoryId = (int) $request->query('category_id', $request->query('categoryId', 0));
        if ($categoryId > 0) {
            $q->where(function ($inner) use ($categoryId) {
                $inner->where('default_category_id', $categoryId)
                    ->orWhereHas('categories', fn ($c) => $c->where('categories.id', $categoryId));
            });
        }

        try {
            GraphqlShopAttributes::applyProductFilter($q, [
                'filter' => $request->query('filter'),
            ]);
        } catch (\Throwable) {
            // ignore bad filter JSON for REST list
        }

        // Attribute filters: ?color=1,2
        foreach ($request->query() as $key => $value) {
            if (! is_string($key) || in_array($key, ['page', 'per_page', 'itemsPerPage', 'items_per_page', 'query', 'q', 'name', 'type', 'category_id', 'categoryId', 'filter', 'sort', 'order', 'price', 'status'], true)) {
                continue;
            }
            if (! is_string($value) && ! is_numeric($value)) {
                continue;
            }
            $ids = array_values(array_filter(array_map('intval', explode(',', (string) $value))));
            if ($ids === []) {
                continue;
            }
            $q->whereHas('featureValues', function ($fv) use ($key, $ids) {
                $fv->whereIn('feature_values.id', $ids)
                    ->whereHas('feature', fn ($f) => $f->where('code', $key));
            });
        }

        $price = trim((string) $request->query('price', ''));
        if ($price !== '' && str_contains($price, ',')) {
            [$from, $to] = array_pad(explode(',', $price, 2), 2, null);
            if ($from !== null && $from !== '') {
                $q->where('price', '>=', (float) $from);
            }
            if ($to !== null && $to !== '') {
                $q->where('price', '<=', (float) $to);
            }
        }

        $sort = strtolower(trim((string) $request->query('sort', 'id')));
        $order = strtolower(trim((string) $request->query('order', 'desc')));
        $dir = $order === 'asc' ? 'asc' : 'desc';
        match ($sort) {
            'name' => $q->orderBy(
                DB::raw('(select name from product_translations where product_translations.product_id = products.id and product_translations.language_code = '.DB::getPdo()->quote($locale).' limit 1)'),
                $dir
            ),
            'price' => $q->orderBy('price', $dir),
            'created_at' => $q->orderBy('created_at', $dir),
            default => $q->orderBy('id', $dir),
        };

        $total = (clone $q)->count();
        $products = $q->forPage($page, $perPage)->get();
        $wishlist = $this->wishlistIds($request);
        $compare = $this->compareIds($request);

        $data = $products->map(fn (Product $p) => $this->card($p, $locale, $wishlist, $compare))->values()->all();

        return $this->paginated($data, $total, $page, $perPage);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $locale = app()->getLocale();
        $product = Product::query()->enabled()->with([
            'translations', 'images', 'brand', 'customizationFields', 'components.child.translations',
            'attachments.translations', 'serviceSetting', 'rentalSetting',
        ])->find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'success' => false], 404);
        }
        $wishlist = $this->wishlistIds($request);
        $compare = $this->compareIds($request);
        $card = $this->card($product, $locale, $wishlist, $compare);
        $full = $this->present->product($product, $locale, true);
        $card['description'] = (string) ($full['description'] ?? '');
        $card['createdAt'] = $product->created_at?->toIso8601String();
        $card['updatedAt'] = $product->updated_at?->toIso8601String();
        $card['isSaleable'] = $product->is_enabled && $product->inStockFor(1.0);
        $card['productTemplate'] = [
            'id' => (int) ($product->product_template_id ?? 0),
            'code' => 'default',
            'name' => 'Default',
        ];
        $card['color'] = null;
        $card['size'] = null;
        $card['brand'] = $product->brand ? [
            'id' => (int) $product->brand->id,
            'code' => (string) ($product->brand->code ?? $product->brand->id),
            'label' => (string) ($product->brand->translation($locale)?->name ?? $product->brand->code ?? ''),
        ] : null;
        $card['categories'] = $full['categories'] ?? [];
        $card['stores'] = $this->storeRows();
        $card['images'] = $product->images->map(fn (ProductImage $img) => [
            'id' => (int) $img->id,
            'type' => 'images',
            'path' => (string) $img->path,
            'productId' => (int) $product->id,
            'position' => (int) ($img->position ?? 0),
            'publicPath' => CatalogMediaUrl::url($img->path),
        ])->values()->all();
        $video = trim((string) ($product->video_url ?? ''));
        $card['videos'] = $video !== '' ? [[
            'id' => (int) $product->id,
            'type' => 'videos',
            'path' => $video,
            'productId' => (int) $product->id,
            'position' => 0,
            'publicPath' => str_starts_with($video, 'http') ? $video : url($video),
        ]] : [];

        $variantsRelay = GraphqlProductConfigurable::variants((int) $product->id, ['first' => 100]);
        $card['variantAxes'] = $product->isCombination()
            ? array_values((GraphqlProductConfigurable::variantAxes((int) $product->id, ['first' => 50])['edges'] ?? []))
            : [];
        if ($card['variantAxes'] !== []) {
            $card['variantAxes'] = collect($card['variantAxes'])->map(fn ($e) => $e['node'] ?? $e)->values()->all();
        }
        $card['variants'] = array_values($variantsRelay['items'] ?? $variantsRelay['nodes'] ?? []);

        $bookingRelay = GraphqlProductComposition::bookingProducts((int) $product->id, ['first' => 10]);
        $card['bookingProducts'] = collect($bookingRelay['edges'] ?? [])->map(fn ($e) => $e['node'] ?? [])->values()->all();

        $card['bundleOptions'] = $product->type === 'bundle'
            ? $this->inlineBundleOptions($product)
            : [];
        $card['groupedProducts'] = in_array($product->type, ['group', 'grouped'], true)
            ? $product->components->map(fn (ProductComponent $c) => [
                'id' => (int) $c->id,
                'qty' => (int) ($c->quantity ?? 1),
                'sortOrder' => (int) ($c->position ?? 0),
                'associatedProduct' => $c->child ? $this->card($c->child, $locale, $wishlist, $compare) : null,
            ])->values()->all()
            : [];

        $attachments = app(AttachmentService::class);
        $card['downloadableLinks'] = $product->attachments->map(function ($a) use ($product, $attachments) {
            $p = $attachments->presentForProduct($a, $product);

            return [
                'id' => (int) $a->id,
                'type' => 'file',
                'price' => 0,
                'fileUrl' => (string) ($p['download_url'] ?? ''),
                'fileName' => (string) ($a->original_filename ?? $a->name ?? ''),
            ];
        })->values()->all();
        $card['downloadableSamples'] = collect($attachments->presentDocumentsOnly($product))
            ->map(fn (array $row) => [
                'id' => (int) ($row['id'] ?? 0),
                'type' => 'file',
                'fileUrl' => (string) ($row['download_url'] ?? $row['url'] ?? ''),
                'fileName' => (string) ($row['original_filename'] ?? $row['name'] ?? ''),
            ])->values()->all();

        $customizations = app(CustomizationService::class);
        $card['customizableOptions'] = $product->customizationFields
            ->sortBy('position')
            ->values()
            ->map(function (ProductCustomizationField $field) use ($customizations, $product) {
                $p = $customizations->presentField($field);

                return [
                    'id' => (int) $field->id,
                    'type' => (string) ($p['type'] ?? $field->type),
                    'label' => (string) ($p['label'] ?? $field->label),
                    'isRequired' => ($p['is_required'] ?? $field->is_required) ? 1 : 0,
                    'sortOrder' => (int) ($p['position'] ?? $field->position ?? 0),
                    'product' => '/api/v1/store/products/'.$product->id,
                ];
            })->all();

        $card['relatedProducts'] = $this->relationCards($product, 'related', $locale, $wishlist, $compare);
        $card['upSells'] = $this->relationCards($product, 'upsell', $locale, $wishlist, $compare);
        $card['crossSells'] = $this->relationCards($product, 'crosssell', $locale, $wishlist, $compare);

        return response()->json($card);
    }

    /**
     */
    public function related(Request $request, int $id): JsonResponse
    {
        return $this->relationCollection($request, $id, 'related');
    }

    /**
     */
    public function upSell(Request $request, int $id): JsonResponse
    {
        return $this->relationCollection($request, $id, 'upsell');
    }

    /**
     */
    public function additionalInformation(int $id): JsonResponse
    {
        $product = Product::query()->enabled()->with(['featureValues.feature.translations', 'featureValues.translations'])->find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'success' => false], 404);
        }
        $locale = app()->getLocale();
        $rows = [];
        foreach ($product->featureValues ?? [] as $value) {
            $feature = $value->feature;
            $label = (string) ($feature?->translation($locale)?->name ?? $feature?->code ?? '');
            $val = (string) ($value->translation($locale)?->value ?? $value->code ?? '');
            if ($label === '' && $val === '') {
                continue;
            }
            $rows[] = [
                'id' => (int) ($feature?->id ?? $value->id),
                'code' => (string) ($feature?->code ?? ''),
                'label' => $label !== '' ? $label : (string) ($feature?->code ?? 'attribute'),
                'value' => $val,
                'admin_name' => $label !== '' ? $label : (string) ($feature?->code ?? ''),
                'type' => (string) ($feature?->type ?? 'text'),
            ];
        }

        return response()->json(['data' => $rows]);
    }

    /**
     */
    public function configurableConfig(int $id): JsonResponse
    {
        $product = Product::query()->enabled()->find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'success' => false], 404);
        }
        $attributes = [];
        $index = [];
        $variantPrices = [];
        if ($product->isCombination()) {
            try {
                $axes = app(CombinationService::class)->presentConfig($product);
            } catch (\Throwable) {
                $axes = [];
            }
            foreach ($axes as $axis) {
                $attributes[] = [
                    'id' => (int) ($axis['attribute_id'] ?? 0),
                    'code' => (string) ($axis['code'] ?? ''),
                    'label' => (string) ($axis['name'] ?? $axis['code'] ?? ''),
                    'swatch_type' => (($axis['type'] ?? '') === 'color') ? 'color' : null,
                    'options' => collect($axis['values'] ?? [])->map(fn (array $v) => [
                        'id' => (int) ($v['id'] ?? 0),
                        'label' => (string) ($v['value'] ?? $v['code'] ?? ''),
                        'swatch_value' => $v['color_code'] ?? null,
                    ])->values()->all(),
                ];
            }
            $relay = GraphqlProductConfigurable::variants($id, ['first' => 100]);
            foreach ($relay['items'] ?? $relay['nodes'] ?? [] as $variant) {
                if (! is_array($variant)) {
                    continue;
                }
                $vid = (int) ($variant['_id'] ?? $variant['id'] ?? 0);
                if ($vid < 1) {
                    continue;
                }
                $price = (float) ($variant['price'] ?? $product->price ?? 0);
                $variantPrices[(string) $vid] = [
                    'regular_price' => ['price' => $price, 'formatted_price' => Money::format($price)],
                    'final_price' => ['price' => $price, 'formatted_price' => Money::format($price)],
                ];
                $index[] = [
                    'id' => $vid,
                    'sku' => (string) ($variant['sku'] ?? ''),
                    'is_saleable' => ! empty($variant['isSaleable'] ?? $variant['status'] ?? true),
                ];
            }
        }

        return response()->json([
            'data' => [
                'attributes' => $attributes,
                'index' => $index,
                'variant_prices' => $variantPrices,
                'variant_images' => [],
                'choose_text' => 'Choose an option',
            ],
        ]);
    }

    private function relationCollection(Request $request, int $id, string $type): JsonResponse
    {
        $product = Product::query()->enabled()->find($id);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'success' => false], 404);
        }
        $locale = app()->getLocale();
        $wishlist = $this->wishlistIds($request);
        $compare = $this->compareIds($request);

        return response()->json($this->relationCards($product, $type, $locale, $wishlist, $compare));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function storeRows(): array
    {
        $storeId = (int) (app(StoreContext::class)->id() ?: 0);
        $stores = Store::query()->where('is_enabled', true)->orderByDesc('is_default')->orderBy('id')->get();

        return $stores->map(fn (Store $s) => [
            'id' => (int) $s->id,
            'code' => (string) ($s->code ?: 'default'),
            'hostname' => request()->getHost(),
            'currencyCode' => Money::currentCode(),
            'localeCode' => app()->getLocale(),
            'isCurrent' => $storeId > 0 ? (int) $s->id === $storeId : (bool) $s->is_default,
        ])->values()->all();
    }

    /**
     * @param  list<int>  $wishlist
     * @param  list<int>  $compare
     * @return list<array<string, mixed>>
     */
    private function relationCards(Product $product, string $type, string $locale, array $wishlist, array $compare): array
    {
        return $product->relationsOfType($type)
            ->where('is_enabled', true)
            ->with(['translations', 'images'])
            ->limit(20)
            ->get()
            ->map(fn (Product $p) => $this->card($p, $locale, $wishlist, $compare))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function inlineBundleOptions(Product $product): array
    {
        $groups = [];
        foreach ($product->components as $c) {
            $key = (string) ($c->option_group ?: 'default');
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'id' => (int) $c->id,
                    'type' => $c->is_required ? 'checkbox' : 'select',
                    'isRequired' => $c->is_required ? 1 : 0,
                    'sortOrder' => (int) ($c->position ?? 0),
                    'label' => $key === 'default' ? 'Options' : $key,
                    'bundleOptionProducts' => [],
                ];
            }
            $groups[$key]['bundleOptionProducts'][] = [
                'id' => (int) $c->id,
                'qty' => (int) ($c->quantity ?? 1),
                'isDefault' => $c->default_selected ? 1 : 0,
                'productId' => (int) ($c->child_product_id ?? 0),
            ];
        }

        return array_values($groups);
    }

    /**
     * @param  list<int>  $wishlist
     * @param  list<int>  $compare
     * @return array<string, mixed>
     */
    private function card(Product $product, string $locale, array $wishlist, array $compare): array
    {
        $presented = $this->present->product($product, $locale, false);
        $regular = (float) ($presented['price'] ?? $product->price ?? 0);
        $sale = null;
        if (! empty($presented['compare_price']) && (float) $presented['compare_price'] > $regular) {
            $sale = $regular;
            $regular = (float) $presented['compare_price'];
        }
        $image = is_string($presented['image'] ?? null)
            ? $presented['image']
            : (is_string($presented['thumbnail'] ?? null) ? $presented['thumbnail'] : null);

        return [
            'id' => (int) $product->id,
            'sku' => (string) $product->sku,
            'type' => (string) ($product->type ?? 'simple'),
            'bookingType' => $product->type === 'booking' ? 'default' : null,
            'name' => (string) ($presented['name'] ?? ''),
            'slug' => (string) ($presented['slug'] ?? ''),
            'status' => (bool) $product->is_enabled,
            'shortDescription' => (string) ($presented['short_description'] ?? ''),
            'price' => $regular,
            'specialPrice' => $sale,
            'new' => (bool) ($product->is_new ?? false),
            'featured' => (bool) ($product->is_best_seller ?? false),
            'minimumPrice' => $sale ?? $regular,
            'maximumPrice' => $regular,
            'formattedPrice' => Money::format($regular),
            'formattedSpecialPrice' => $sale !== null ? Money::format($sale) : null,
            'formattedMinimumPrice' => Money::format($sale ?? $regular),
            'formattedMaximumPrice' => Money::format($regular),
            'baseImageUrl' => $image,
            'isInWishlist' => in_array((int) $product->id, $wishlist, true) ? 1 : 0,
            'isInCompare' => in_array((int) $product->id, $compare, true) ? 1 : 0,
        ];
    }

    /** @return list<int> */
    private function wishlistIds(Request $request): array
    {
        $customer = $request->user('customer');
        if (! $customer instanceof Customer) {
            return [];
        }

        return DB::table('wishlists')->where('customer_id', $customer->id)->pluck('product_id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return list<int> */
    private function compareIds(Request $request): array
    {
        if (! ProductSettings::compareEnabled()) {
            return [];
        }
        $raw = session()->get('wento_compare_ids', []);
        $customer = $request->user('customer');
        if ($customer instanceof Customer) {
            $profile = is_array($customer->profile) ? $customer->profile : [];
            if (is_array($profile['compare_ids'] ?? null) && $profile['compare_ids'] !== []) {
                $raw = $profile['compare_ids'];
            }
        }
        if (! is_array($raw)) {
            return [];
        }

        return collect($raw)->map(fn ($id) => (int) $id)->filter(fn (int $id) => $id > 0)->values()->all();
    }

    /**
     * @param  list<array<string, mixed>>  $data
     */
    private function paginated(array $data, int $total, int $page, int $perPage): JsonResponse
    {
        $pages = max(1, (int) ceil($total / max(1, $perPage)));

        return response()->json($data)->withHeaders([
            'X-Total-Count' => (string) $total,
            'X-Page' => (string) $page,
            'X-Per-Page' => (string) $perPage,
            'X-Total-Pages' => (string) $pages,
        ]);
    }
}
