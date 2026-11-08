<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\GraphqlProductComposition;
use App\Domain\Api\Graphql\GraphqlProductConfigurable;
use App\Domain\Catalog\Models\Attachment;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductComponent;
use App\Domain\Catalog\Models\ProductCustomizationField;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\ProductQtyPrice;
use App\Domain\Catalog\Services\AttachmentService;
use App\Domain\Catalog\Services\CustomizationService;
use App\Domain\Catalog\Support\CatalogMediaUrl;
use App\Domain\Localization\Money;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 */
class ShopProductSubresourcesController extends Controller
{
    public function images(int $productId): JsonResponse
    {
        $product = Product::query()->enabled()->find($productId);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'success' => false], 404);
        }
        $rows = $product->images()->orderBy('position')->orderBy('id')->get()
            ->map(fn (ProductImage $img) => $this->imageRow($img, 'images'))
            ->values()
            ->all();

        return response()->json($rows);
    }

    public function videos(int $productId): JsonResponse
    {
        $product = Product::query()->enabled()->find($productId);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'success' => false], 404);
        }

        // Wento stores product video URL on the product row when present.
        $rows = [];
        $video = trim((string) ($product->video_url ?? ''));
        if ($video !== '') {
            $rows[] = [
                'id' => (int) $product->id,
                'type' => 'videos',
                'path' => $video,
                'productId' => (int) $product->id,
                'position' => 0,
                'publicPath' => str_starts_with($video, 'http') ? $video : url($video),
                'fileName' => basename(parse_url($video, PHP_URL_PATH) ?: $video),
                'altText' => null,
            ];
        }

        return response()->json($rows);
    }

    public function customerGroupPrices(int $productId): JsonResponse
    {
        $product = Product::query()->enabled()->find($productId);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'success' => false], 404);
        }

        $rows = $product->qtyPrices()->orderBy('min_qty')->orderBy('position')->get()
            ->map(function (ProductQtyPrice $tier) use ($product) {
                $qty = (float) ($tier->min_qty ?? 0);
                $price = (float) ($tier->price ?? 0);
                $base = (float) ($product->price ?? 0);
                $valueType = 'fixed';
                $value = $price;
                if ($base > 0 && $price < $base) {
                    // Present discount % when tier is below base (valueType=discount)
                    $pct = round((1 - ($price / $base)) * 100, 2);
                    if ($pct > 0 && $pct < 100) {
                        $valueType = 'discount';
                        $value = $pct;
                    }
                }

                return [
                    'id' => (int) $tier->id,
                    'qty' => fmod($qty, 1.0) === 0.0 ? (int) $qty : $qty,
                    'valueType' => $valueType,
                    'value' => $value,
                    'customerGroupId' => null,
                    'uniqueId' => (string) $product->id.'|0|'.(string) $qty,
                    'productId' => (int) $product->id,
                    'price' => $price,
                ];
            })
            ->values()
            ->all();

        return response()->json($rows);
    }

    public function customizableOptions(int $productId, CustomizationService $customizations): JsonResponse
    {
        $product = Product::query()->enabled()->with('customizationFields')->find($productId);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'success' => false], 404);
        }
        $rows = $product->customizationFields
            ->sortBy('position')
            ->values()
            ->map(fn (ProductCustomizationField $field) => $this->optionRow($field, $customizations))
            ->all();

        return response()->json($rows);
    }

    public function showCustomizableOption(int $id, CustomizationService $customizations): JsonResponse
    {
        $field = ProductCustomizationField::query()->find($id);
        if (! $field) {
            return response()->json(['message' => 'Customizable option not found.', 'success' => false], 404);
        }

        return response()->json($this->optionRow($field, $customizations));
    }

    public function attributeValues(int $productId): JsonResponse
    {
        $product = Product::query()->enabled()->with(['featureValues.feature', 'featureValues.translations'])->find($productId);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'success' => false], 404);
        }
        $locale = app()->getLocale();
        $rows = [];
        foreach ($product->featureValues ?? [] as $value) {
            $feature = $value->feature;
            $label = $value->translation($locale)?->value ?? $value->code;
            $rows[] = [
                'id' => (int) $value->id,
                'locale' => $locale,
                'channel' => null,
                'textValue' => (string) $label,
                'integerValue' => null,
                'decimalValue' => null,
                'booleanValue' => null,
                'datetimeValue' => null,
                'dateValue' => null,
                'jsonValue' => null,
                'value' => (string) $label,
                'uniqueId' => (string) $product->id.'|'.(string) ($feature?->id ?? 0).'|'.$locale,
                'attribute' => $feature ? '/api/v1/store/attributes/'.$feature->id : null,
                'product' => '/api/v1/store/products/'.$product->id,
            ];
        }

        return response()->json($rows);
    }

    public function productImages(Request $request): JsonResponse
    {
        $perPage = min(50, max(1, (int) $request->query('per_page', 10)));
        $page = max(1, (int) $request->query('page', 1));
        $q = ProductImage::query()->orderBy('id');
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $perPage)->get()
            ->map(fn (ProductImage $img) => $this->imageRow($img, 'images'))
            ->values()
            ->all();

        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function showProductImage(int $id): JsonResponse
    {
        $img = ProductImage::query()->find($id);
        if (! $img) {
            return response()->json(['message' => 'Product image not found.', 'success' => false], 404);
        }

        return response()->json($this->imageRow($img, 'images'));
    }

    /**
     */
    public function productVideos(Request $request): JsonResponse
    {
        $perPage = min(50, max(1, (int) $request->query('per_page', 10)));
        $page = max(1, (int) $request->query('page', 1));
        $q = Product::query()->enabled()->whereNotNull('video_url')->where('video_url', '!=', '')->orderBy('id');
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $perPage)->get()
            ->map(function (Product $product) {
                $video = trim((string) $product->video_url);

                return [
                    'id' => (int) $product->id,
                    'type' => 'videos',
                    'path' => $video,
                    'productId' => (int) $product->id,
                    'position' => 0,
                    'publicPath' => str_starts_with($video, 'http') ? $video : url($video),
                    'fileName' => basename(parse_url($video, PHP_URL_PATH) ?: $video),
                    'altText' => null,
                ];
            })
            ->values()
            ->all();

        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function showProductVideo(int $id): JsonResponse
    {
        $product = Product::query()->enabled()->find($id);
        $video = trim((string) ($product?->video_url ?? ''));
        if (! $product || $video === '') {
            return response()->json(['message' => 'Product video not found.', 'success' => false], 404);
        }

        return response()->json([
            'id' => (int) $product->id,
            'type' => 'videos',
            'path' => $video,
            'productId' => (int) $product->id,
            'position' => 0,
            'publicPath' => str_starts_with($video, 'http') ? $video : url($video),
            'fileName' => basename(parse_url($video, PHP_URL_PATH) ?: $video),
            'altText' => null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function imageRow(ProductImage $img, string $type): array
    {
        $path = (string) $img->path;
        $url = CatalogMediaUrl::url($path) ?: (string) ($img->url ?? '');

        return [
            'id' => (int) $img->id,
            'type' => $type,
            'path' => $path,
            'productId' => (int) $img->product_id,
            'position' => (int) ($img->position ?? 0),
            'publicPath' => $url,
            'fileName' => pathinfo($path, PATHINFO_FILENAME) ?: basename($path),
            'altText' => $img->altText(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function optionRow(ProductCustomizationField $field, CustomizationService $customizations): array
    {
        $p = $customizations->presentField($field);
        $compatType = match ((string) ($p['type'] ?? $field->type)) {
            'text' => 'field',
            'textarea' => 'area',
            'select' => 'drop_down',
            'radio' => 'radio',
            'checkbox' => 'checkbox',
            'file', 'image' => 'file',
            default => (string) ($p['type'] ?? $field->type),
        };

        return [
            'id' => (int) $field->id,
            'type' => $compatType,
            'isRequired' => ($p['is_required'] ?? $field->is_required) ? 1 : 0,
            'sortOrder' => (int) ($p['position'] ?? $field->position ?? 0),
            'product' => '/api/v1/store/products/'.$field->product_id,
            'prices' => [
                '/api/v1/store/product_customizable_option_prices/'.$field->id,
            ],
            'translation' => '/api/v1/store/product_customizable_option_translations/'.$field->id,
            'translations' => [
                '/api/v1/store/product_customizable_option_translations/'.$field->id,
            ],
            'label' => (string) ($p['label'] ?? $field->label),
            'maxCharacters' => isset($p['max_length']) ? (int) $p['max_length'] : null,
            'price' => (float) ($p['price_amount'] ?? 0),
            'priceType' => (string) ($p['price_type'] ?? 'none'),
        ];
    }

    public function optionPrices(Request $request): JsonResponse
    {
        $perPage = min(50, max(1, (int) $request->query('per_page', 10)));
        $page = max(1, (int) $request->query('page', 1));
        $customizations = app(CustomizationService::class);
        $q = ProductCustomizationField::query()->orderBy('id');
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $perPage)->get()
            ->map(function (ProductCustomizationField $field) use ($customizations) {
                $p = $customizations->presentField($field);

                return [
                    'id' => (int) $field->id,
                    'price' => (float) ($p['price_amount'] ?? 0),
                    'priceType' => (string) (($p['price_type'] ?? 'none') === 'percent' ? 'percent' : 'fixed'),
                    'customizableOption' => '/api/v1/store/product_customizable_options/'.$field->id,
                ];
            })
            ->values()
            ->all();

        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function showOptionPrice(int $id): JsonResponse
    {
        $field = ProductCustomizationField::query()->find($id);
        if (! $field) {
            return response()->json(['message' => 'Customizable option price not found.', 'success' => false], 404);
        }
        $p = app(CustomizationService::class)->presentField($field);

        return response()->json([
            'id' => (int) $field->id,
            'price' => (float) ($p['price_amount'] ?? 0),
            'priceType' => (string) (($p['price_type'] ?? 'none') === 'percent' ? 'percent' : 'fixed'),
            'customizableOption' => '/api/v1/store/product_customizable_options/'.$field->id,
        ]);
    }

    public function optionTranslations(Request $request): JsonResponse
    {
        $perPage = min(50, max(1, (int) $request->query('per_page', 10)));
        $page = max(1, (int) $request->query('page', 1));
        $locale = app()->getLocale();
        $q = ProductCustomizationField::query()->orderBy('id');
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $perPage)->get()
            ->map(fn (ProductCustomizationField $field) => [
                'id' => (int) $field->id,
                'locale' => $locale,
                'label' => (string) $field->label,
                'customizableOption' => '/api/v1/store/product_customizable_options/'.$field->id,
            ])
            ->values()
            ->all();

        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function showOptionTranslation(int $id): JsonResponse
    {
        $field = ProductCustomizationField::query()->find($id);
        if (! $field) {
            return response()->json(['message' => 'Customizable option translation not found.', 'success' => false], 404);
        }

        return response()->json([
            'id' => (int) $field->id,
            'locale' => app()->getLocale(),
            'label' => (string) $field->label,
            'customizableOption' => '/api/v1/store/product_customizable_options/'.$field->id,
        ]);
    }

    /**
     */
    public function variants(int $productId): JsonResponse
    {
        $product = Product::query()->enabled()->find($productId);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'success' => false], 404);
        }
        $relay = GraphqlProductConfigurable::variants($productId, ['first' => 100]);

        return response()->json(array_values($relay['items'] ?? $relay['nodes'] ?? []));
    }

    /**
     */
    public function bookingProducts(int $productId): JsonResponse
    {
        $product = Product::query()->enabled()->find($productId);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'success' => false], 404);
        }
        $relay = GraphqlProductComposition::bookingProducts($productId, ['first' => 20]);
        $rows = collect($relay['edges'] ?? [])->map(function (array $edge) {
            $n = $edge['node'] ?? [];
            $id = (int) ($n['_id'] ?? $n['id'] ?? 0);
            $type = (string) ($n['type'] ?? 'default');

            return [
                'id' => $id,
                'type' => $type,
                'qty' => (int) ($n['qty'] ?? 0),
                'location' => $n['location'] ?? null,
                'showLocation' => ! empty($n['location']) ? 1 : 0,
                'availableFrom' => $n['availableFrom'] ?? null,
                'availableTo' => $n['availableTo'] ?? null,
                'slots' => $n['slots'] ?? $n['defaultSlot'] ?? $n['appointmentSlot'] ?? $n['rentalSlot'] ?? null,
                'defaultSlot' => $type === 'default' ? '/api/v1/store/booking_product_default_slots/'.$id : null,
                'appointmentSlot' => $type === 'appointment' ? '/api/v1/store/booking_product_appointment_slots/'.$id : null,
                'rentalSlot' => $type === 'rental' ? '/api/v1/store/booking_product_rental_slots/'.$id : null,
                'tableSlot' => null,
                'eventTickets' => [],
            ];
        })->values()->all();

        return response()->json($rows);
    }

    public function showBookingProduct(int $id): JsonResponse
    {
        // Booking config id maps to the product id in Wento.
        $res = $this->bookingProducts($id);
        $data = $res->getData(true);
        if (! is_array($data) || $data === [] || isset($data['message'])) {
            return response()->json(['message' => 'Booking product not found.', 'success' => false], 404);
        }

        return response()->json($data[0] ?? $data);
    }

    /**
     */
    public function bundleOptions(int $productId): JsonResponse
    {
        $product = Product::query()->enabled()->find($productId);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'success' => false], 404);
        }
        $components = ProductComponent::query()
            ->where('parent_product_id', $productId)
            ->with('child')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        // Group by option_group → bundle option decision groups
        $groups = [];
        foreach ($components as $c) {
            $key = (string) ($c->option_group ?: 'default');
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'id' => (int) $c->id,
                    'type' => $c->is_required ? 'checkbox' : 'select',
                    'isRequired' => $c->is_required ? 1 : 0,
                    'sortOrder' => (int) ($c->position ?? 0),
                    'product' => '/api/v1/store/products/'.$productId,
                    'bundleOptionProducts' => [],
                    'translation' => '/api/v1/store/product_bundle_option_translations/'.$c->id,
                    'translations' => ['/api/v1/store/product_bundle_option_translations/'.$c->id],
                    'label' => $key === 'default' ? 'Options' : $key,
                ];
            }
            $groups[$key]['bundleOptionProducts'][] = '/api/v1/store/product-bundle-option-products/'.$c->id;
        }

        return response()->json(array_values($groups));
    }

    public function showBundleOption(int $id): JsonResponse
    {
        $c = ProductComponent::query()->find($id);
        if (! $c) {
            return response()->json(['message' => 'Bundle option not found.', 'success' => false], 404);
        }
        $key = (string) ($c->option_group ?: 'default');

        return response()->json([
            'id' => (int) $c->id,
            'type' => $c->is_required ? 'checkbox' : 'select',
            'isRequired' => $c->is_required ? 1 : 0,
            'sortOrder' => (int) ($c->position ?? 0),
            'product' => '/api/v1/store/products/'.$c->parent_product_id,
            'bundleOptionProducts' => ['/api/v1/store/product-bundle-option-products/'.$c->id],
            'translation' => '/api/v1/store/product_bundle_option_translations/'.$c->id,
            'translations' => ['/api/v1/store/product_bundle_option_translations/'.$c->id],
            'label' => $key === 'default' ? 'Options' : $key,
        ]);
    }

    public function bundleOptionProducts(Request $request): JsonResponse
    {
        $perPage = min(50, max(1, (int) $request->query('per_page', 10)));
        $page = max(1, (int) $request->query('page', 1));
        $q = ProductComponent::query()->orderBy('id');
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $perPage)->get()
            ->map(fn (ProductComponent $c) => $this->bundleMemberRow($c))
            ->values()
            ->all();

        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function showBundleOptionProduct(int $id): JsonResponse
    {
        $c = ProductComponent::query()->find($id);
        if (! $c) {
            return response()->json(['message' => 'Bundle option product not found.', 'success' => false], 404);
        }

        return response()->json($this->bundleMemberRow($c));
    }

    public function showBundleOptionTranslation(int $id): JsonResponse
    {
        $c = ProductComponent::query()->find($id);
        if (! $c) {
            return response()->json(['message' => 'Bundle option translation not found.', 'success' => false], 404);
        }
        $key = (string) ($c->option_group ?: 'default');

        return response()->json([
            'id' => (int) $c->id,
            'locale' => app()->getLocale(),
            'label' => $key === 'default' ? 'Options' : $key,
            'bundleOption' => '/api/v1/store/product_bundle_options/'.$c->id,
        ]);
    }

    public function bundleOptionTranslations(Request $request): JsonResponse
    {
        $perPage = min(50, max(1, (int) $request->query('per_page', 10)));
        $page = max(1, (int) $request->query('page', 1));
        $locale = app()->getLocale();
        $q = ProductComponent::query()->orderBy('id');
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $perPage)->get()
            ->map(function (ProductComponent $c) use ($locale) {
                $key = (string) ($c->option_group ?: 'default');

                return [
                    'id' => (int) $c->id,
                    'locale' => $locale,
                    'label' => $key === 'default' ? 'Options' : $key,
                    'bundleOption' => '/api/v1/store/product_bundle_options/'.$c->id,
                ];
            })
            ->values()
            ->all();

        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function productDownloadableSamples(Request $request, AttachmentService $attachments): JsonResponse
    {
        $perPage = min(50, max(1, (int) $request->query('per_page', 10)));
        $page = max(1, (int) $request->query('page', 1));
        $q = Attachment::query()->orderBy('id');
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $perPage)->get()
            ->map(fn (Attachment $a) => [
                'id' => (int) $a->id,
                'file' => (string) ($a->storage_path ?? ''),
                'fileName' => (string) ($a->original_filename ?? $a->name ?? ''),
                'type' => 'file',
                'sortOrder' => 0,
                'fileUrl' => (string) ($attachments->present($a)['download_url'] ?? ''),
                'product' => null,
                'translation' => '/api/v1/store/product_downloadable_sample_translations/'.$a->id,
                'translations' => ['/api/v1/store/product_downloadable_sample_translations/'.$a->id],
            ])
            ->values()
            ->all();

        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function showDownloadableSample(int $id, AttachmentService $attachments): JsonResponse
    {
        $a = Attachment::query()->find($id);
        if (! $a) {
            return response()->json(['message' => 'Downloadable sample not found.', 'success' => false], 404);
        }

        return response()->json([
            'id' => (int) $a->id,
            'file' => (string) ($a->storage_path ?? ''),
            'fileName' => (string) ($a->original_filename ?? $a->name ?? ''),
            'type' => 'file',
            'sortOrder' => 0,
            'fileUrl' => (string) ($attachments->present($a)['download_url'] ?? ''),
            'product' => null,
            'translation' => '/api/v1/store/product_downloadable_sample_translations/'.$a->id,
            'translations' => ['/api/v1/store/product_downloadable_sample_translations/'.$a->id],
        ]);
    }

    /**
     */
    public function groupedProducts(int $productId): JsonResponse
    {
        $product = Product::query()->enabled()->find($productId);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'success' => false], 404);
        }
        $rows = ProductComponent::query()
            ->where('parent_product_id', $productId)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (ProductComponent $c) => [
                'id' => (int) $c->id,
                'qty' => (int) ($c->quantity ?? 1),
                'sortOrder' => (int) ($c->position ?? 0),
                'product' => '/api/v1/store/products/'.$productId,
                'associatedProduct' => $c->child_product_id
                    ? '/api/v1/store/products/'.$c->child_product_id
                    : null,
            ])
            ->values()
            ->all();

        return response()->json($rows);
    }

    public function showGroupedProduct(int $id): JsonResponse
    {
        $c = ProductComponent::query()->find($id);
        if (! $c) {
            return response()->json(['message' => 'Grouped product not found.', 'success' => false], 404);
        }

        return response()->json([
            'id' => (int) $c->id,
            'qty' => (int) ($c->quantity ?? 1),
            'sortOrder' => (int) ($c->position ?? 0),
            'product' => '/api/v1/store/products/'.$c->parent_product_id,
            'associatedProduct' => $c->child_product_id
                ? '/api/v1/store/products/'.$c->child_product_id
                : null,
        ]);
    }

    /**
     */
    public function downloadableLinks(int $productId, AttachmentService $attachments): JsonResponse
    {
        $product = Product::query()->enabled()->with('attachments.translations')->find($productId);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'success' => false], 404);
        }
        $rows = $product->attachments
            ->map(fn (Attachment $a) => $this->downloadableLinkRow($a, $product, $attachments))
            ->values()
            ->all();

        return response()->json($rows);
    }

    public function downloadableSamples(int $productId, AttachmentService $attachments): JsonResponse
    {
        $product = Product::query()->enabled()->with('attachments.translations')->find($productId);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'success' => false], 404);
        }
        // Samples = document-role public previews
        $rows = collect($attachments->presentDocumentsOnly($product))
            ->map(fn (array $row) => [
                'id' => (int) ($row['id'] ?? 0),
                'file' => (string) ($row['storage_path'] ?? $row['path'] ?? ''),
                'fileName' => (string) ($row['original_filename'] ?? $row['name'] ?? ''),
                'type' => 'file',
                'sortOrder' => 0,
                'fileUrl' => (string) ($row['url'] ?? $row['download_url'] ?? ''),
                'product' => '/api/v1/store/products/'.$productId,
                'translation' => '/api/v1/store/product_downloadable_sample_translations/'.(int) ($row['id'] ?? 0),
                'translations' => [
                    '/api/v1/store/product_downloadable_sample_translations/'.(int) ($row['id'] ?? 0),
                ],
            ])
            ->values()
            ->all();

        return response()->json($rows);
    }

    public function productDownloadableLinks(Request $request, AttachmentService $attachments): JsonResponse
    {
        $perPage = min(50, max(1, (int) $request->query('per_page', 10)));
        $page = max(1, (int) $request->query('page', 1));
        $q = Attachment::query()->orderBy('id');
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $perPage)->get()
            ->map(fn (Attachment $a) => $this->downloadableLinkRow($a, null, $attachments))
            ->values()
            ->all();

        return $this->paginated($rows, $total, $page, $perPage);
    }

    public function showDownloadableLink(int $id, AttachmentService $attachments): JsonResponse
    {
        $a = Attachment::query()->find($id);
        if (! $a) {
            return response()->json(['message' => 'Downloadable link not found.', 'success' => false], 404);
        }

        return response()->json($this->downloadableLinkRow($a, null, $attachments));
    }

    public function showDownloadableLinkTranslation(int $id): JsonResponse
    {
        $a = Attachment::query()->with('translations')->find($id);
        if (! $a) {
            return response()->json(['message' => 'Downloadable link translation not found.', 'success' => false], 404);
        }
        $locale = app()->getLocale();

        return response()->json([
            'id' => (int) $a->id,
            'locale' => $locale,
            'title' => $a->displayName($locale),
            'description' => (string) ($a->translation($locale)?->description ?? $a->description ?? ''),
            'downloadableLink' => '/api/v1/store/product-downloadable-links/'.$a->id,
        ]);
    }

    public function showDownloadableSampleTranslation(int $id): JsonResponse
    {
        return $this->showDownloadableLinkTranslation($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function bundleMemberRow(ProductComponent $c): array
    {
        return [
            'id' => (int) $c->id,
            'qty' => (int) ($c->quantity ?? 1),
            'isUserDefined' => $c->qty_editable ? 1 : 0,
            'isDefault' => $c->default_selected ? 1 : 0,
            'sortOrder' => (int) ($c->position ?? 0),
            'bundleOption' => '/api/v1/store/product_bundle_options/'.$c->id,
            'product' => $c->child_product_id
                ? '/api/v1/store/products/'.$c->child_product_id
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function downloadableLinkRow(Attachment $a, ?Product $product, AttachmentService $attachments): array
    {
        $presented = $product
            ? $attachments->presentForProduct($a, $product)
            : $attachments->present($a);
        $price = 0.0;
        $url = (string) ($presented['url'] ?? $presented['download_url'] ?? '');

        return [
            'id' => (int) $a->id,
            'url' => null,
            'type' => 'file',
            'price' => $price,
            'formattedPrice' => Money::format($price),
            'sampleFile' => null,
            'sampleFileName' => null,
            'sampleType' => null,
            'downloads' => 1,
            'sortOrder' => 0,
            'fileUrl' => $url,
            'sampleFileUrl' => null,
            'product' => $product
                ? '/api/v1/store/products/'.$product->id
                : null,
            'translation' => '/api/v1/store/product_downloadable_link_translations/'.$a->id,
            'translations' => ['/api/v1/store/product_downloadable_link_translations/'.$a->id],
            'fileName' => (string) ($a->original_filename ?? $a->name ?? ''),
        ];
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
