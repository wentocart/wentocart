<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Catalog\Models\Product;
use App\Domain\Checkout\Services\CartService;
use App\Domain\Customer\Models\Customer;
use App\Domain\Settings\SearchCatalogSettings;
use GraphQL\Error\UserError;
use Illuminate\Support\Facades\DB;

/**
 * Shop REST wishlist queries/mutations (item IRI + cursor connection).
 */
final class GraphqlWishlist
{
    /**
     * @param  array<string, mixed>  $args
     * @return array{edges: list<array{cursor: string, node: array<string, mixed>}>, pageInfo: array<string, mixed>, totalCount: int}
     */
    public static function connection(array $args): array
    {
        $customer = self::requireCustomer();
        $first = max(1, min(100, (int) ($args['first'] ?? $args['limit'] ?? 30)));
        $afterId = 0;
        $after = trim((string) ($args['after'] ?? ''));
        if ($after !== '') {
            $decoded = base64_decode($after, true);
            if (is_string($decoded) && preg_match('/^wl:(\d+)$/', $decoded, $m)) {
                $afterId = (int) $m[1];
            }
        }

        $sort = strtolower(trim((string) ($args['sort'] ?? 'id')));
        $order = strtolower(trim((string) ($args['order'] ?? 'asc')));
        if (str_contains($sort, '-')) {
            [$sort, $maybeOrder] = array_pad(explode('-', $sort, 2), 2, $order);
            $order = in_array(strtolower($maybeOrder), ['asc', 'desc'], true) ? strtolower($maybeOrder) : $order;
        }
        $sortCol = $sort === 'created_at' ? 'created_at' : 'id';
        $dir = $order === 'desc' ? 'desc' : 'asc';

        $q = DB::table('wishlists')->where('customer_id', $customer->id);
        $total = (clone $q)->count();
        if ($afterId > 0) {
            $q->where('id', $dir === 'asc' ? '>' : '<', $afterId);
        }
        $rows = $q->orderBy($sortCol, $dir)->orderBy('id', $dir)->limit($first + 1)->get();
        $hasNext = $rows->count() > $first;
        $slice = $rows->take($first);
        $locale = app()->getLocale();
        $productIds = $slice->pluck('product_id')->map(fn ($id) => (int) $id)->all();
        $products = Product::query()->whereIn('id', $productIds)->with(['translations', 'images'])->get()->keyBy('id');

        $edges = $slice->map(function ($row) use ($locale, $products, $customer) {
            $product = $products->get((int) $row->product_id);

            return [
                'cursor' => base64_encode('wl:'.$row->id),
                'node' => self::mapRow($row, $product, $customer, $locale),
            ];
        })->values()->all();
        $end = $slice->last();

        return [
            'edges' => $edges,
            'pageInfo' => [
                'hasNextPage' => $hasNext,
                'hasPreviousPage' => $afterId > 0,
                'startCursor' => $edges[0]['cursor'] ?? null,
                'endCursor' => $end ? base64_encode('wl:'.$end->id) : null,
            ],
            'totalCount' => $total,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findOne(mixed $id): ?array
    {
        $customer = self::requireCustomer();
        $rowId = self::parseWishlistId($id);
        if ($rowId < 1) {
            return null;
        }
        $row = DB::table('wishlists')->where('customer_id', $customer->id)->where('id', $rowId)->first();
        if (! $row) {
            return null;
        }
        $product = Product::query()->with(['translations', 'images'])->find((int) $row->product_id);

        return self::mapRow($row, $product, $customer, app()->getLocale());
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{wishlist: array<string, mixed>, clientMutationId: ?string}
     */
    public static function createMutation(array $args): array
    {
        $input = is_array($args['input'] ?? null) ? $args['input'] : $args;
        $customer = self::requireCustomer();
        $productId = (int) ($input['productId'] ?? $input['product_id'] ?? 0);
        if ($productId < 1 || ! Product::query()->where('id', $productId)->exists()) {
            throw new UserError('Product not found.');
        }

        $existing = DB::table('wishlists')
            ->where('customer_id', $customer->id)
            ->where('product_id', $productId)
            ->first();
        if (! $existing) {
            $now = now();
            $id = DB::table('wishlists')->insertGetId([
                'customer_id' => $customer->id,
                'product_id' => $productId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $row = DB::table('wishlists')->where('id', $id)->first();
        } else {
            $row = $existing;
        }

        $product = Product::query()->with(['translations', 'images'])->find($productId);

        return [
            'wishlist' => self::mapRow($row, $product, $customer, app()->getLocale()),
            'clientMutationId' => isset($input['clientMutationId']) ? (string) $input['clientMutationId'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{wishlist: array{id: string}, clientMutationId: ?string, wishlisted: bool, product_id: ?string}
     */
    public static function deleteMutation(array $args): array
    {
        $input = is_array($args['input'] ?? null) ? $args['input'] : $args;
        $customer = self::requireCustomer();

        $row = null;
        $wishlistId = self::parseWishlistId($input['id'] ?? $input['wishlistItemId'] ?? null);
        if ($wishlistId > 0) {
            $row = DB::table('wishlists')->where('customer_id', $customer->id)->where('id', $wishlistId)->first();
        }
        $productId = (int) ($input['productId'] ?? $input['product_id'] ?? 0);
        if (! $row && $productId > 0) {
            $row = DB::table('wishlists')
                ->where('customer_id', $customer->id)
                ->where('product_id', $productId)
                ->first();
        }
        if (! $row) {
            throw new UserError('Wishlist item not found.');
        }

        $iri = '/api/v1/store/wishlists/'.$row->id;
        $pid = (string) $row->product_id;
        DB::table('wishlists')->where('id', $row->id)->delete();

        return [
            'wishlist' => ['id' => $iri],
            'clientMutationId' => isset($input['clientMutationId']) ? (string) $input['clientMutationId'] : null,
            'wishlisted' => false,
            'product_id' => $pid,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{wishlistToCart: array{message: string}, cart: array<string, mixed>, clientMutationId: ?string}
     */
    public static function moveToCartMutation(array $args): array
    {
        $input = is_array($args['input'] ?? null) ? $args['input'] : $args;
        $customer = self::requireCustomer();

        $row = null;
        $wishlistId = self::parseWishlistId($input['wishlistItemId'] ?? $input['id'] ?? null);
        if ($wishlistId > 0) {
            $row = DB::table('wishlists')->where('customer_id', $customer->id)->where('id', $wishlistId)->first();
        }
        $productId = (int) ($input['productId'] ?? $input['product_id'] ?? 0);
        if (! $row && $productId > 0) {
            $row = DB::table('wishlists')
                ->where('customer_id', $customer->id)
                ->where('product_id', $productId)
                ->first();
        }
        if (! $row) {
            throw new UserError('Wishlist item not found.');
        }

        $product = Product::query()->enabled()->find((int) $row->product_id);
        if (! $product) {
            throw new UserError('Product not found.');
        }

        $qty = (float) ($input['quantity'] ?? $input['qty'] ?? 1);
        if ($qty <= 0) {
            $qty = 1;
        }

        $carts = app(CartService::class);
        $cart = $carts->current($customer);
        try {
            $carts->add($cart, $product, $qty);
        } catch (\Throwable $e) {
            throw new UserError($e->getMessage());
        }
        DB::table('wishlists')->where('id', $row->id)->delete();

        return [
            'wishlistToCart' => ['message' => 'Item moved to cart.'],
            'cart' => ShopSchema::cartPayloadPublic($carts),
            'clientMutationId' => isset($input['clientMutationId']) ? (string) $input['clientMutationId'] : null,
        ];
    }

    public static function isInWishlist(int $productId): string
    {
        $customer = auth('customer')->user();
        if (! $customer instanceof Customer || $productId < 1) {
            return '0';
        }
        if (! SearchCatalogSettings::wishlistEnabled()) {
            return '0';
        }

        return DB::table('wishlists')
            ->where('customer_id', $customer->id)
            ->where('product_id', $productId)
            ->exists() ? '1' : '0';
    }

    protected static function requireCustomer(): Customer
    {
        $customer = auth('customer')->user();
        if (! $customer instanceof Customer) {
            throw new UserError('Authentication required.');
        }
        if (! SearchCatalogSettings::wishlistEnabled()) {
            throw new UserError('Wishlist is disabled.');
        }

        return $customer;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function mapRow(object $row, ?Product $product, Customer $customer, string $locale): array
    {
        return [
            'id' => '/api/v1/store/wishlists/'.$row->id,

            'product' => $product ? ShopSchema::mapProduct($product, $locale) : null,
            'customer' => [
                'id' => (string) $customer->id,
                'email' => $customer->email,
                'name' => $customer->displayName(),
                'firstName' => $customer->first_name ?? null,
                'lastName' => $customer->last_name ?? null,
                'phone' => $customer->phone ?? null,
            ],
            'createdAt' => isset($row->created_at) ? (string) $row->created_at : null,
            'updatedAt' => isset($row->updated_at) ? (string) $row->updated_at : null,
        ];
    }

    protected static function parseWishlistId(mixed $raw): int
    {
        if ($raw === null || $raw === '') {
            return 0;
        }
        if (is_int($raw) || (is_string($raw) && ctype_digit($raw))) {
            return (int) $raw;
        }
        $s = (string) $raw;
        if (preg_match('/(\d+)\s*$/', $s, $m)) {
            return (int) $m[1];
        }

        return 0;
    }
}
