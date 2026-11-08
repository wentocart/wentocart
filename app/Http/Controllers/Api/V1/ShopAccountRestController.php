<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Models\Product;
use App\Domain\Checkout\Services\CartService;
use App\Domain\Customer\GdprSchema;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\GdprRequest;
use App\Domain\Customer\Services\GdprService;
use App\Domain\Settings\GdprSettings;
use App\Domain\Settings\ProductSettings;
use App\Domain\Settings\SearchCatalogSettings;
use App\Domain\Store\Models\Store;
use App\Domain\Store\StoreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Shop REST account REST: wishlists, compare-items, gdpr-requests.
 */
class ShopAccountRestController extends Controller
{
    public function wishlists(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        if (! SearchCatalogSettings::wishlistEnabled()) {
            return $this->paginated([]);
        }

        [$sortCol, $dir] = $this->sortOrder($request, 'id', 'asc');
        $perPage = min(50, max(1, (int) $request->query('per_page', 30)));
        $page = max(1, (int) $request->query('page', 1));

        $q = DB::table('wishlists')->where('customer_id', $customer->id);
        $total = (clone $q)->count();
        $rows = $q->orderBy($sortCol, $dir)->orderBy('id', $dir)
            ->forPage($page, $perPage)->get();

        $storeId = (int) (app(StoreContext::class)->id() ?: Store::query()->where('is_default', true)->value('id') ?: 0);
        $data = $rows->map(fn ($row) => $this->wishlistRow($row, $customer, $storeId))->values()->all();

        return $this->paginated($data, $total, $page, $perPage);
    }

    public function createWishlist(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        if (! SearchCatalogSettings::wishlistEnabled()) {
            return response()->json(['message' => 'Wishlist is disabled.', 'success' => false], 400);
        }

        $productId = (int) ($request->input('productId') ?? $request->input('product_id') ?? 0);
        if ($productId < 1 || ! Product::query()->whereKey($productId)->exists()) {
            return response()->json(['message' => 'Product not found.', 'success' => false], 400);
        }

        $existing = DB::table('wishlists')
            ->where('customer_id', $customer->id)
            ->where('product_id', $productId)
            ->first();
        if ($existing) {
            return response()->json(['message' => 'Product already in wishlist.', 'success' => false], 400);
        }

        $now = now();
        $id = DB::table('wishlists')->insertGetId([
            'customer_id' => $customer->id,
            'product_id' => $productId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $row = DB::table('wishlists')->where('id', $id)->first();
        $storeId = (int) (app(StoreContext::class)->id() ?: 0);

        return response()->json($this->wishlistRow($row, $customer, $storeId), 201);
    }

    /**
     */
    public function showWishlist(Request $request, int $id): JsonResponse
    {
        $customer = $this->customer($request);
        if (! SearchCatalogSettings::wishlistEnabled()) {
            return response()->json(['message' => 'Wishlist item not found.', 'success' => false], 404);
        }
        $row = DB::table('wishlists')->where('customer_id', $customer->id)->where('id', $id)->first();
        if (! $row) {
            return response()->json(['message' => 'Wishlist item not found.', 'success' => false], 404);
        }
        $storeId = (int) (app(StoreContext::class)->id() ?: Store::query()->where('is_default', true)->value('id') ?: 0);

        return response()->json($this->wishlistRow($row, $customer, $storeId));
    }

    public function deleteWishlist(Request $request, int $id): JsonResponse
    {
        $customer = $this->customer($request);
        $deleted = DB::table('wishlists')->where('customer_id', $customer->id)->where('id', $id)->delete();
        if (! $deleted) {
            return response()->json(['message' => 'Wishlist item not found.', 'success' => false], 404);
        }

        return response()->json(['success' => true, 'message' => 'Wishlist item removed.']);
    }

    public function deleteAllWishlists(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        DB::table('wishlists')->where('customer_id', $customer->id)->delete();

        return response()->json(['success' => true, 'message' => 'Wishlist cleared.']);
    }

    public function moveWishlistToCart(Request $request, int $id, CartService $carts): JsonResponse
    {
        $customer = $this->customer($request);
        $row = DB::table('wishlists')->where('customer_id', $customer->id)->where('id', $id)->first();
        if (! $row) {
            return response()->json(['message' => 'Wishlist item not found.', 'success' => false], 404);
        }
        $product = Product::query()->enabled()->find((int) $row->product_id);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'success' => false], 400);
        }
        $qty = max(1, (float) ($request->input('quantity') ?? $request->input('qty') ?? 1));
        $cart = $carts->current($customer);
        try {
            $carts->add($cart, $product, $qty);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage(), 'success' => false], 400);
        }
        DB::table('wishlists')->where('id', $row->id)->delete();

        return response()->json(['success' => true, 'message' => 'Item moved to cart.']);
    }

    /**
     */
    public function toggleWishlistByProduct(Request $request, int $id): JsonResponse
    {
        $request->merge([
            'productId' => $id,
            'product_id' => $id,
        ]);

        return $this->toggleWishlist($request);
    }

    /**
     */
    public function moveWishlistProductToCart(Request $request, int $id, CartService $carts): JsonResponse
    {
        $customer = $this->customer($request);
        $row = DB::table('wishlists')
            ->where('customer_id', $customer->id)
            ->where('product_id', $id)
            ->first();
        if (! $row) {
            return response()->json(['message' => 'Wishlist item not found.', 'success' => false], 404);
        }

        return $this->moveWishlistToCart($request, (int) $row->id, $carts);
    }

    public function toggleWishlist(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        if (! SearchCatalogSettings::wishlistEnabled()) {
            return response()->json(['message' => 'Wishlist is disabled.', 'success' => false], 400);
        }
        $productId = (int) ($request->input('productId') ?? $request->input('product_id') ?? 0);
        if ($productId < 1 || ! Product::query()->whereKey($productId)->exists()) {
            return response()->json(['message' => 'Product not found.', 'success' => false], 400);
        }
        $existing = DB::table('wishlists')
            ->where('customer_id', $customer->id)
            ->where('product_id', $productId)
            ->first();
        $storeId = (int) (app(StoreContext::class)->id() ?: 0);
        if ($existing) {
            DB::table('wishlists')->where('id', $existing->id)->delete();

            return response()->json([
                'success' => true,
                'message' => 'Removed from wishlist.',
                'wishlisted' => false,
            ]);
        }
        $now = now();
        $id = DB::table('wishlists')->insertGetId([
            'customer_id' => $customer->id,
            'product_id' => $productId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $row = DB::table('wishlists')->where('id', $id)->first();

        return response()->json(array_merge($this->wishlistRow($row, $customer, $storeId), [
            'success' => true,
            'message' => 'Added to wishlist.',
            'wishlisted' => true,
        ]), 201);
    }

    public function compareItems(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        if (! ProductSettings::compareEnabled()) {
            return $this->paginated([]);
        }
        $ids = $this->compareIds($customer);
        $perPage = min(50, max(1, (int) $request->query('per_page', 30)));
        $page = max(1, (int) $request->query('page', 1));
        $total = count($ids);
        $slice = array_slice($ids, ($page - 1) * $perPage, $perPage);
        $data = array_map(fn (int $pid) => $this->compareRow($pid, $customer), $slice);

        return $this->paginated($data, $total, $page, $perPage);
    }

    public function showCompareItem(Request $request, int $id): JsonResponse
    {
        $customer = $this->customer($request);
        if (! in_array($id, $this->compareIds($customer), true)) {
            return response()->json(['message' => 'Compare item not found.', 'success' => false], 404);
        }

        return response()->json($this->compareRow($id, $customer));
    }

    public function createCompareItem(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        if (! ProductSettings::compareEnabled()) {
            return response()->json(['message' => 'Compare is disabled.', 'success' => false], 400);
        }
        $productId = (int) ($request->input('productId') ?? $request->input('product_id') ?? 0);
        if ($productId < 1 || ! Product::query()->enabled()->whereKey($productId)->exists()) {
            return response()->json(['message' => 'Product not found.', 'success' => false], 400);
        }
        $ids = $this->compareIds($customer);
        if (in_array($productId, $ids, true)) {
            return response()->json(['message' => 'Product already in compare list.', 'success' => false], 400);
        }
        $max = ProductSettings::compareMax();
        if (count($ids) >= $max) {
            return response()->json(['message' => 'Compare list is full (max '.$max.').', 'success' => false], 400);
        }
        $ids[] = $productId;
        $this->storeCompareIds($customer, $ids);

        return response()->json($this->compareRow($productId, $customer), 201);
    }

    public function deleteCompareItem(Request $request, int $id): JsonResponse
    {
        $customer = $this->customer($request);
        $ids = array_values(array_filter($this->compareIds($customer), fn (int $pid) => $pid !== $id));
        $this->storeCompareIds($customer, $ids);

        return response()->json(['success' => true, 'message' => 'Compare item removed.']);
    }

    /**
     */
    public function deleteCompareItemByBody(Request $request): JsonResponse
    {
        $productId = (int) ($request->input('product_id') ?? $request->input('productId') ?? 0);
        if ($productId < 1) {
            return response()->json(['message' => 'The product id field is required.', 'success' => false], 422);
        }

        return $this->deleteCompareItem($request, $productId);
    }

    public function deleteAllCompareItems(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        $this->storeCompareIds($customer, []);

        return response()->json(['success' => true, 'message' => 'Compare list cleared.']);
    }

    /**
     * Shop REST gdprStatus — public (storefront key only).
     */
    public function gdprStatus(): JsonResponse
    {
        return response()->json([
            'enabled' => \App\Domain\Settings\GdprSettings::enabled(),
            'success' => true,
        ]);
    }

    public function gdprRequests(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        if (! GdprSettings::enabled()) {
            return response()->json([
                'message' => 'GDPR data requests are disabled. Please enable GDPR from the admin configuration.',
                'success' => false,
            ], 400);
        }
        GdprSchema::ensure();
        [$sortCol, $dir] = $this->sortOrder($request, 'id', 'desc');
        $rows = GdprRequest::query()
            ->where('customer_id', $customer->id)
            ->orderBy($sortCol === 'created_at' ? 'created_at' : 'id', $dir)
            ->limit(30)
            ->get();
        $data = $rows->map(fn (GdprRequest $row) => $this->gdprRow($row, $customer))->values()->all();

        return $this->paginated($data, count($data), 1, 30);
    }

    public function showGdprRequest(Request $request, int $id): JsonResponse
    {
        $customer = $this->customer($request);
        if (! GdprSettings::enabled()) {
            return response()->json([
                'message' => 'GDPR data requests are disabled. Please enable GDPR from the admin configuration.',
                'success' => false,
            ], 400);
        }
        GdprSchema::ensure();
        $row = GdprRequest::query()->where('customer_id', $customer->id)->whereKey($id)->first();
        if (! $row) {
            return response()->json(['message' => 'GDPR request not found.', 'success' => false], 404);
        }

        return response()->json($this->gdprRow($row, $customer));
    }

    public function createGdprRequest(Request $request, GdprService $gdpr): JsonResponse
    {
        $customer = $this->customer($request);
        if (! GdprSettings::enabled()) {
            return response()->json([
                'message' => 'GDPR data requests are disabled. Please enable GDPR from the admin configuration.',
                'success' => false,
            ], 400);
        }
        $data = $request->validate([
            'type' => ['required', Rule::in(GdprSettings::TYPES)],
            'message' => ['required', 'string', 'max:500'],
        ]);
        $row = $gdpr->create($customer, $data['type'], trim($data['message']));
        $payload = $this->gdprRow($row, $customer);
        $payload['successMessage'] = 'GDPR request created.';

        return response()->json($payload, 201);
    }

    public function revokeGdprRequest(Request $request, int $id, GdprService $gdpr): JsonResponse
    {
        $customer = $this->customer($request);
        GdprSchema::ensure();
        $row = GdprRequest::query()->where('customer_id', $customer->id)->whereKey($id)->first();
        if (! $row) {
            return response()->json(['message' => 'GDPR request not found.', 'success' => false], 404);
        }
        if (! in_array($row->status, ['pending', 'processing'], true)) {
            return response()->json(['message' => 'Only pending or processing requests can be revoked.', 'success' => false], 422);
        }
        $gdpr->revoke($customer, $row);

        return response()->json(['success' => true, 'message' => 'GDPR request revoked.']);
    }

    public function deleteGdprRequest(Request $request, int $id): JsonResponse
    {
        $customer = $this->customer($request);
        GdprSchema::ensure();
        $deleted = GdprRequest::query()->where('customer_id', $customer->id)->whereKey($id)->delete();
        if (! $deleted) {
            return response()->json(['message' => 'GDPR request not found.', 'success' => false], 404);
        }

        return response()->json(['success' => true, 'message' => 'GDPR request deleted.']);
    }

    private function customer(Request $request): Customer
    {
        $customer = $request->user('customer');
        if (! $customer instanceof Customer) {
            abort(401);
        }

        return $customer;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function sortOrder(Request $request, string $defaultSort, string $defaultDir): array
    {
        $sort = strtolower(trim((string) $request->query('sort', $defaultSort)));
        $order = strtolower(trim((string) $request->query('order', $defaultDir)));
        if (str_contains($sort, '-')) {
            [$sort, $maybe] = array_pad(explode('-', $sort, 2), 2, $order);
            $order = in_array(strtolower((string) $maybe), ['asc', 'desc'], true) ? strtolower((string) $maybe) : $order;
        }
        $col = $sort === 'created_at' ? 'created_at' : 'id';
        $dir = $order === 'desc' ? 'desc' : 'asc';

        return [$col, $dir];
    }

    /**
     * @return array<string, mixed>
     */
    private function wishlistRow(object $row, Customer $customer, int $storeId): array
    {
        return [
            'id' => (int) $row->id,
            'product' => '/api/v1/store/products/'.$row->product_id,
            'customer' => '/api/v1/store/customers/'.$customer->id,
            'channel' => $storeId > 0 ? '/api/v1/store/stores/'.$storeId : null,
            'createdAt' => isset($row->created_at) ? (string) $row->created_at : null,
            'updatedAt' => isset($row->updated_at) ? (string) $row->updated_at : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function compareRow(int $productId, Customer $customer): array
    {
        return [
            'id' => $productId,
            'product' => '/api/v1/store/products/'.$productId,
            'customer' => '/api/v1/store/customers/'.$customer->id,
            'createdAt' => null,
            'updatedAt' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function gdprRow(GdprRequest $row, Customer $customer): array
    {
        return [
            'id' => (int) $row->id,
            'email' => (string) $customer->email,
            'status' => (string) $row->status,
            'type' => (string) $row->type,
            'message' => (string) ($row->message ?? ''),
            'revokedAt' => $row->status === 'revoked' || $row->status === 'deleted'
                ? optional($row->updated_at)?->format('Y-m-d H:i:s')
                : null,
            'createdAt' => optional($row->created_at)?->toIso8601String(),
            'updatedAt' => optional($row->updated_at)?->toIso8601String(),
            'successMessage' => null,
            'customer' => '/api/v1/store/customers/'.$customer->id,
        ];
    }

    /** @return list<int> */
    private function compareIds(Customer $customer): array
    {
        $raw = session()->get('wento_compare_ids', []);
        $profile = is_array($customer->profile) ? $customer->profile : [];
        if (is_array($profile['compare_ids'] ?? null) && $profile['compare_ids'] !== []) {
            $raw = $profile['compare_ids'];
        }
        if (! is_array($raw)) {
            return [];
        }

        return collect($raw)->map(fn ($id) => (int) $id)->filter(fn (int $id) => $id > 0)->unique()->values()->all();
    }

    /** @param  list<int>  $ids */
    private function storeCompareIds(Customer $customer, array $ids): void
    {
        $ids = array_values($ids);
        session()->put('wento_compare_ids', $ids);
        $profile = is_array($customer->profile) ? $customer->profile : [];
        $profile['compare_ids'] = $ids;
        $customer->forceFill(['profile' => $profile])->save();
    }

    /**
     * @param  list<array<string, mixed>>  $data
     */
    private function paginated(array $data, int $total = 0, int $page = 1, int $perPage = 30): JsonResponse
    {
        $pages = max(1, (int) ceil(max(0, $total) / max(1, $perPage)));

        return response()->json($data)->withHeaders([
            'X-Total-Count' => (string) $total,
            'X-Page' => (string) $page,
            'X-Per-Page' => (string) $perPage,
            'X-Total-Pages' => (string) $pages,
        ]);
    }
}
