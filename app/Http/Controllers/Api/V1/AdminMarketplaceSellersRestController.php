<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\GraphqlAdminImpersonate;
use App\Domain\Catalog\Models\Product;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerProduct;
use App\Domain\Marketplace\Services\SellerService;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use GraphQL\Error\UserError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 */
class AdminMarketplaceSellersRestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $perPage = min(100, max(1, (int) $request->query('per_page', $request->query('limit', 20))));
        $page = max(1, (int) $request->query('page', 1));
        $q = Seller::query()->with('customer:id,name,email,first_name,last_name')->orderByDesc('id');
        $status = trim((string) ($request->query('status') ?? ''));
        if ($status !== '' && in_array($status, Seller::STATUSES, true)) {
            $q->where('status', $status);
        }
        $term = trim((string) ($request->query('q') ?? $request->query('search') ?? ''));
        if ($term !== '') {
            $like = '%'.$term.'%';
            $q->where(function ($inner) use ($like) {
                $inner->where('shop_name', 'like', $like)
                    ->orWhere('shop_slug', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like);
            });
        }
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $perPage)->get()
            ->map(fn (Seller $s) => $this->row($s))
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

    public function show(int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $seller = Seller::query()->with(['customer:id,name,email,first_name,last_name', 'commissions'])->find($id);
        if (! $seller) {
            return response()->json(['message' => 'Seller not found.', 'error' => 'not_found'], 404);
        }

        return response()->json(['data' => $this->row($seller, true)]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $seller = Seller::query()->find($id);
        if (! $seller) {
            return response()->json(['message' => 'Seller not found.', 'error' => 'not_found'], 404);
        }
        $fields = [
            'shop_name', 'profile_name', 'phone', 'contact_name', 'contact_phone', 'contact_email',
            'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country_code',
            'description', 'tax_id', 'vacation_message', 'store_policy', 'shipping_policy', 'return_policy',
        ];
        foreach ($fields as $field) {
            if ($request->has($field)) {
                $seller->{$field} = $request->input($field);
            }
        }
        if ($request->has('is_active')) {
            $seller->is_active = $request->boolean('is_active');
        }
        if ($request->has('vacation_enabled')) {
            $seller->vacation_enabled = $request->boolean('vacation_enabled');
        }
        $seller->save();

        if ($request->filled('commission_rate') || $request->filled('commissionPercentage')) {
            $rate = (float) ($request->input('commission_rate') ?? $request->input('commissionPercentage'));
            app(SellerService::class)->setCommission(
                $seller,
                $rate,
                $request->input('notes') ?? $request->input('note'),
                null,
                null,
                Auth::guard('admin')->id() ? (int) Auth::guard('admin')->id() : null,
            );
            $seller->refresh();
        }

        return response()->json(['data' => $this->row($seller->fresh(['customer', 'commissions']) ?? $seller, true)]);
    }

    public function review(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $seller = Seller::query()->find($id);
        if (! $seller) {
            return response()->json(['message' => 'Seller not found.', 'error' => 'not_found'], 404);
        }
        $action = trim((string) ($request->input('action') ?? ''));
        if (! in_array($action, ['approve', 'reject', 'block', 'suspend', 'reactivate'], true)) {
            return response()->json(['message' => 'action must be approve|reject|block|suspend|reactivate.', 'error' => 'validation'], 422);
        }
        $rate = $request->filled('commission_rate') || $request->filled('commissionPercentage')
            ? (float) ($request->input('commission_rate') ?? $request->input('commissionPercentage'))
            : null;
        try {
            app(SellerService::class)->review(
                $seller,
                $action,
                $request->input('note') ?? $request->input('notes'),
                Auth::guard('admin')->id() ? (int) Auth::guard('admin')->id() : null,
                $rate,
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => 'review_error'], 422);
        }

        return response()->json([
            'message' => 'Seller reviewed successfully.',
            'data' => $this->row($seller->fresh(['customer']) ?? $seller, true),
        ]);
    }

    /**
     * for the seller owner so admin can open the seller portal as that account.
     */
    public function loginAs(int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $seller = Seller::query()->find($id);
        if (! $seller) {
            return response()->json(['message' => 'Seller not found.', 'error' => 'not_found'], 404);
        }
        if (! $seller->customer_id) {
            return response()->json(['message' => 'Seller has no linked customer account.', 'error' => 'validation'], 422);
        }

        try {
            $issued = GraphqlAdminImpersonate::issue((int) $seller->customer_id);
        } catch (UserError $e) {
            $message = $e->getMessage();
            $status = str_contains(strtolower($message), 'not found') ? 404 : 403;

            return response()->json(['message' => $message, 'error' => 'impersonate_error'], $status);
        }

        return response()->json([
            'message' => 'Seller login token issued.',
            'data' => [
                'sellerId' => (int) $seller->id,
                'shopSlug' => (string) $seller->shop_slug,
                'token' => $issued['token'],
                'customerId' => (int) $issued['customerId'],
                'customerEmail' => $issued['customerEmail'],
                'customerName' => $issued['customerName'],
                'impersonatedByAdminId' => (int) $issued['impersonatedByAdminId'],
                'expiresAt' => $issued['expiresAt'],
            ],
        ]);
    }

    /**
     */
    public function assignProduct(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $seller = Seller::query()->find($id);
        if (! $seller || $seller->status !== Seller::STATUS_APPROVED) {
            return response()->json(['message' => 'Approved seller required.', 'error' => 'not_found'], 404);
        }
        $productId = (int) ($request->input('product_id') ?? $request->input('productId') ?? 0);
        $product = Product::query()->find($productId);
        if (! $product) {
            return response()->json(['message' => 'Product not found.', 'error' => 'validation'], 422);
        }
        if ($product->seller_id && (int) $product->seller_id !== (int) $seller->id) {
            return response()->json([
                'message' => 'Product is already assigned to another seller.',
                'error' => 'validation',
            ], 422);
        }
        $approve = filter_var($request->input('approve', true), FILTER_VALIDATE_BOOLEAN);
        $locale = app()->getLocale();
        $name = (string) ($product->translation($locale)?->name ?? $product->sku);

        $product->forceFill(['seller_id' => $seller->id])->save();
        $listing = SellerProduct::query()->updateOrCreate(
            ['seller_id' => $seller->id, 'product_id' => $product->id],
            [
                'sku' => $product->sku,
                'name' => $name,
                'slug' => $product->translation($locale)?->slug,
                'description' => $product->translation($locale)?->description,
                'short_description' => $product->translation($locale)?->short_description,
                'price' => $product->price,
                'compare_price' => $product->compare_price,
                'stock_qty' => $product->stock_qty,
                'category_id' => $product->default_category_id,
                'is_enabled' => (bool) $product->is_enabled,
                'approval_status' => $approve ? SellerProduct::STATUS_APPROVED : SellerProduct::STATUS_PENDING,
                'reviewed_by' => $approve && Auth::guard('admin')->id() ? (int) Auth::guard('admin')->id() : null,
                'reviewed_at' => $approve ? now() : null,
            ]
        );

        return response()->json([
            'message' => 'Product assigned to seller.',
            'data' => [
                'sellerId' => (int) $seller->id,
                'productId' => (int) $product->id,
                'sellerProductId' => (int) $listing->id,
                'approvalStatus' => (string) $listing->approval_status,
                'sku' => (string) $listing->sku,
                'name' => (string) $listing->name,
            ],
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Seller $seller, bool $detail = false): array
    {
        $row = [
            'id' => (int) $seller->id,
            'shopName' => (string) ($seller->shop_name ?? ''),
            'shopSlug' => (string) ($seller->shop_slug ?? ''),
            'status' => (string) ($seller->status ?? ''),
            'isActive' => $seller->is_active ? 1 : 0,
            'commissionEnabled' => $seller->commission_rate !== null ? 1 : 0,
            'commissionPercentage' => $seller->commission_rate !== null ? (float) $seller->commission_rate : null,
            'email' => (string) ($seller->email ?? $seller->customer?->email ?? ''),
            'firstName' => (string) ($seller->first_name ?? ''),
            'lastName' => (string) ($seller->last_name ?? ''),
            'customerId' => $seller->customer_id ? (int) $seller->customer_id : null,
            'healthScore' => $seller->health_score !== null ? (int) $seller->health_score : null,
            'healthBand' => $seller->health_band ? (string) $seller->health_band : null,
            'createdAt' => optional($seller->created_at)?->toIso8601String(),
            'updatedAt' => optional($seller->updated_at)?->toIso8601String(),
        ];
        if ($detail) {
            $row['phone'] = (string) ($seller->phone ?? '');
            $row['description'] = (string) ($seller->description ?? '');
            $row['city'] = (string) ($seller->city ?? '');
            $row['state'] = (string) ($seller->state ?? '');
            $row['countryCode'] = (string) ($seller->country_code ?? '');
            $row['reviewNote'] = (string) ($seller->review_note ?? '');
            $row['vacationEnabled'] = $seller->vacation_enabled ? 1 : 0;
            $row['commissions'] = $seller->relationLoaded('commissions')
                ? $seller->commissions->values()->all()
                : [];
        }

        return $row;
    }

    private function guard(): ?JsonResponse
    {
        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin) {
            return response()->json(['message' => 'Unauthenticated.', 'error' => 'unauthenticated'], 401);
        }

        return null;
    }
}
