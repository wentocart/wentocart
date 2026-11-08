<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\ProductService;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Services\CustomerService;
use App\Domain\Localization\Models\Country;
use App\Domain\Localization\Models\Currency;
use App\Domain\Localization\Models\Language;
use App\Domain\Ops\Models\WebhookEndpoint;
use App\Domain\Ops\WebhookCatalog;
use App\Domain\Ops\WebserviceCatalog;
use App\Domain\Ops\WebservicePayload;
use App\Domain\Ops\WebserviceQuery;
use App\Domain\Order\Models\Order;
use App\Domain\Order\OrderStageService;
use App\Domain\Security\SafeUrl;
use App\Domain\Store\StoreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

class WebserviceController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'auth' => [
                'header' => 'Authorization: Bearer wntk_…',
                'query_string' => false,
            ],
            'include' => 'Pass ?include=images,categories (comma-separated) for extra relations listed on each endpoint.',
            'sync' => 'Pass ?updated_since=ISO-8601 with ?page= and ?per_page= (max 100).',
            'endpoints' => WebserviceCatalog::endpoints(),
            'scopes' => WebhookCatalog::scopes(),
        ]);
    }

    public function shop(Request $request): JsonResponse
    {
        $shop = app(StoreContext::class)->store();
        $includes = WebserviceQuery::includes($request, ['countries']);
        $payload = [
            'name' => $shop?->name ?: (string) config('wento.name', config('app.name')),
            'url' => rtrim((string) config('app.url'), '/').'/',
            'currency' => (string) config('wento.default_currency', 'USD'),
            'language' => Language::defaultCode(),
            'languages' => Language::query()->orderBy('position')->orderBy('id')->get()
                ->map(fn (Language $row) => WebservicePayload::language($row))->all(),
            'currencies' => Currency::query()->orderBy('position')->orderBy('id')->get()
                ->map(fn (Currency $row) => WebservicePayload::currency($row))->all(),
        ];
        if (in_array('countries', $includes, true)) {
            $payload['countries'] = Country::query()->orderBy('position')->orderBy('id')->get()
                ->map(fn ($row) => WebservicePayload::country($row))->all();
        }

        return response()->json($payload);
    }

    public function products(Request $request): JsonResponse
    {
        $includes = WebserviceQuery::includes($request, WebserviceCatalog::productIncludes());
        $q = Product::query()->with(WebservicePayload::productEager($includes))->orderBy('id');
        if ($sku = trim((string) $request->query('sku', ''))) {
            $q->where('sku', $sku);
        }

        return WebserviceQuery::paginate($request, $q, fn (Product $row) => WebservicePayload::product($row, $includes));
    }

    public function showProduct(Request $request, Product $product): JsonResponse
    {
        $includes = WebserviceQuery::includes($request, WebserviceCatalog::productIncludes());

        return response()->json(['data' => WebservicePayload::product($product, $includes)]);
    }

    public function storeProduct(Request $request, ProductService $products): JsonResponse
    {
        $data = $this->validateProduct($request, true);
        $saved = $products->save($this->productSavePayload($data));

        return response()->json(['data' => WebservicePayload::product($saved)], 201);
    }

    public function updateProduct(Request $request, Product $product, ProductService $products): JsonResponse
    {
        $data = $this->validateProduct($request, false);
        $payload = $this->productSavePayload($data, $product);
        $saved = $products->save($payload, $product);

        return response()->json(['data' => WebservicePayload::product($saved)]);
    }

    public function destroyProduct(Product $product, ProductService $products): JsonResponse
    {
        $products->delete($product);

        return response()->json(['ok' => true]);
    }

    public function orders(Request $request): JsonResponse
    {
        $includes = WebserviceQuery::includes($request, ['customer']);
        $with = ['items'];
        if (in_array('customer', $includes, true)) {
            $with[] = 'customer';
        }
        $q = Order::query()->with($with)->orderByDesc('id');
        if ($status = trim((string) $request->query('status', ''))) {
            $q->where('status', $status);
        }
        if ($number = trim((string) $request->query('number', ''))) {
            $q->where('number', $number);
        }

        return WebserviceQuery::paginate($request, $q, fn (Order $row) => WebservicePayload::order($row, $includes));
    }

    public function showOrder(Request $request, Order $order): JsonResponse
    {
        $includes = WebserviceQuery::includes($request, ['customer']);

        return response()->json(['data' => WebservicePayload::order($order, $includes)]);
    }

    public function updateOrderStatus(Request $request, Order $order, OrderStageService $stages): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'max:80'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);
        $updated = $stages->apply($order, $data['status'], $data['comment'] ?? 'Updated via web service');

        return response()->json(['data' => WebservicePayload::order($updated)]);
    }

    public function customers(Request $request): JsonResponse
    {
        $includes = WebserviceQuery::includes($request, ['addresses']);
        $q = Customer::query()->orderBy('id');
        if (in_array('addresses', $includes, true)) {
            $q->with('addresses');
        }
        if ($email = trim((string) $request->query('email', ''))) {
            $q->where('email', $email);
        }

        return WebserviceQuery::paginate($request, $q, fn (Customer $row) => WebservicePayload::customer($row, $includes));
    }

    public function showCustomer(Request $request, Customer $customer): JsonResponse
    {
        $includes = WebserviceQuery::includes($request, ['addresses']);

        return response()->json(['data' => WebservicePayload::customer($customer, $includes)]);
    }

    public function storeCustomer(Request $request, CustomerService $customers): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:180'],
            'name' => ['nullable', 'string', 'max:180'],
            'first_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:40'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);
        $customer = $customers->save($data);

        return response()->json(['data' => WebservicePayload::customer($customer)], 201);
    }

    public function updateCustomer(Request $request, Customer $customer, CustomerService $customers): JsonResponse
    {
        $data = $request->validate([
            'email' => ['sometimes', 'email', 'max:180'],
            'name' => ['nullable', 'string', 'max:180'],
            'first_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:40'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);
        $payload = array_merge([
            'email' => $customer->email,
            'name' => $customer->name,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'phone' => $customer->phone,
            'is_enabled' => $customer->is_enabled,
        ], $data);
        $saved = $customers->save($payload, $customer);

        return response()->json(['data' => WebservicePayload::customer($saved)]);
    }

    public function webhooks(): JsonResponse
    {
        $rows = WebhookEndpoint::query()->latest('id')->get()->map(fn (WebhookEndpoint $row) => [
            'id' => $row->id,
            'name' => $row->name,
            'url' => $row->url,
            'events' => $row->subscribedEvents(),
            'is_enabled' => $row->is_enabled,
            'secret_prefix' => $row->secret_prefix,
        ]);

        return response()->json(['data' => $rows]);
    }

    public function storeWebhook(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'url' => ['required', 'url', 'max:500'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', 'max:80'],
        ]);
        try {
            SafeUrl::assertPublicHttp((string) $data['url']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        $events = WebhookEndpoint::normalizeEvents($data['events']);
        if ($events === []) {
            return response()->json(['error' => 'Choose at least one known event.'], 422);
        }
        $plain = WebhookEndpoint::generateSecret();
        $endpoint = new WebhookEndpoint;
        $endpoint->name = $data['name'];
        $endpoint->url = $data['url'];
        $endpoint->events = $events;
        $endpoint->event_pattern = in_array('*', $events, true) ? '*' : ($events[0] ?? '*');
        $endpoint->is_enabled = true;
        $endpoint->setPlainSecret($plain);
        $endpoint->save();

        return response()->json([
            'data' => [
                'id' => $endpoint->id,
                'name' => $endpoint->name,
                'url' => $endpoint->url,
                'events' => $endpoint->subscribedEvents(),
                'secret' => $plain,
            ],
            'warning' => 'The signing secret is shown once. Store it now.',
        ], 201);
    }

    public function destroyWebhook(WebhookEndpoint $webhook): JsonResponse
    {
        $webhook->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validateProduct(Request $request, bool $creating): array
    {
        return $request->validate([
            'sku' => [$creating ? 'required' : 'sometimes', 'string', 'max:80'],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:180'],
            'price' => [$creating ? 'required' : 'sometimes', 'numeric', 'min:0'],
            'stock_qty' => ['sometimes', 'numeric', 'min:0'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function productSavePayload(array $data, ?Product $existing = null): array
    {
        $lang = Language::defaultCode();
        $name = (string) ($data['name'] ?? $existing?->translation()?->name ?? 'Product');
        $qty = (float) ($data['stock_qty'] ?? $existing?->stock_qty ?? 0);
        $slug = (string) ($existing?->translation()?->slug ?: '');
        if (isset($data['name']) || $slug === '') {
            $slug = Str::slug($name) ?: ($slug !== '' ? $slug : 'product');
        }

        return [
            'product' => [
                'type' => $existing?->type ?: 'simple',
                'sku' => (string) ($data['sku'] ?? $existing?->sku),
                'price' => (float) ($data['price'] ?? $existing?->price ?? 0),
                'stock_qty' => $qty,
                'stock_status' => $qty > 0 ? 'in_stock' : 'out_of_stock',
                'is_enabled' => array_key_exists('is_enabled', $data)
                    ? (bool) $data['is_enabled']
                    : (bool) ($existing?->is_enabled ?? true),
                'visibility' => $existing?->visibility ?: 'catalog_search',
                'available_for_order' => $existing?->available_for_order ?? true,
                'show_price' => $existing?->show_price ?? true,
                'manage_stock' => $existing?->manage_stock ?? true,
            ],
            'translations' => [
                $lang => [
                    'language_code' => $lang,
                    'name' => $name,
                    'slug' => $slug ?: 'product',
                ],
            ],
        ];
    }
}
