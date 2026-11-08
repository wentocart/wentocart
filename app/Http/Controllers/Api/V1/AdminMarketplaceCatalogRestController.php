<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Marketplace\Models\SellerOrder;
use App\Domain\Marketplace\Models\SellerProduct;
use App\Domain\Marketplace\Services\SellerService;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 */
class AdminMarketplaceCatalogRestController extends Controller
{
    public function productsIndex(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $perPage = min(100, max(1, (int) $request->query('per_page', $request->query('limit', 20))));
        $page = max(1, (int) $request->query('page', 1));
        $q = SellerProduct::query()->with('seller:id,shop_name,shop_slug')->orderByDesc('id');
        $sellerId = (int) ($request->query('seller_id') ?? $request->query('sellerId') ?? 0);
        if ($sellerId > 0) {
            $q->where('seller_id', $sellerId);
        }
        $status = trim((string) ($request->query('status') ?? $request->query('approval_status') ?? ''));
        if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $q->where('approval_status', $status);
        }
        $term = trim((string) ($request->query('q') ?? $request->query('search') ?? ''));
        if ($term !== '') {
            $like = '%'.$term.'%';
            $q->where(function ($inner) use ($like) {
                $inner->where('name', 'like', $like)->orWhere('sku', 'like', $like);
            });
        }
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $perPage)->get()
            ->map(fn (SellerProduct $row) => $this->productRow($row))
            ->values()
            ->all();

        return response()->json([
            'data' => $rows,
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage],
        ]);
    }

    public function productsShow(int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $row = SellerProduct::query()->with('seller:id,shop_name,shop_slug')->find($id);
        if (! $row) {
            return response()->json(['message' => 'Seller product not found.', 'error' => 'not_found'], 404);
        }

        return response()->json(['data' => $this->productRow($row, true)]);
    }

    public function productsReview(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $row = SellerProduct::query()->find($id);
        if (! $row) {
            return response()->json(['message' => 'Seller product not found.', 'error' => 'not_found'], 404);
        }
        $action = trim((string) ($request->input('action') ?? ''));
        if (! in_array($action, ['approve', 'reject'], true)) {
            return response()->json(['message' => 'action must be approve or reject.', 'error' => 'validation'], 422);
        }
        $note = trim((string) ($request->input('note') ?? $request->input('notes') ?? ''));
        if ($action === 'reject' && $note === '') {
            return response()->json(['message' => 'A rejection reason is required.', 'error' => 'validation'], 422);
        }
        try {
            app(SellerService::class)->reviewProduct(
                $row,
                $action,
                $note !== '' ? $note : null,
                Auth::guard('admin')->id() ? (int) Auth::guard('admin')->id() : null,
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => 'review_error'], 422);
        }

        return response()->json([
            'message' => $action === 'approve' ? 'Seller product approved.' : 'Seller product rejected.',
            'data' => $this->productRow($row->fresh(['seller']) ?? $row, true),
        ]);
    }

    public function ordersIndex(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $perPage = min(100, max(1, (int) $request->query('per_page', $request->query('limit', 20))));
        $page = max(1, (int) $request->query('page', 1));
        $q = SellerOrder::query()->with('seller:id,shop_name,shop_slug')->orderByDesc('id');
        $sellerId = (int) ($request->query('seller_id') ?? $request->query('sellerId') ?? 0);
        if ($sellerId > 0) {
            $q->where('seller_id', $sellerId);
        }
        $status = trim((string) ($request->query('status') ?? ''));
        if (in_array($status, ['pending', 'processing', 'shipped', 'completed', 'cancelled'], true)) {
            $q->where('status', $status);
        }
        $term = trim((string) ($request->query('q') ?? $request->query('search') ?? ''));
        if ($term !== '') {
            $like = '%'.$term.'%';
            $q->where(function ($inner) use ($like) {
                $inner->where('number', 'like', $like)->orWhere('customer_email', 'like', $like);
            });
        }
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $perPage)->get()
            ->map(fn (SellerOrder $row) => $this->orderRow($row))
            ->values()
            ->all();

        return response()->json([
            'data' => $rows,
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage],
        ]);
    }

    public function ordersShow(int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $row = SellerOrder::query()->with(['seller:id,shop_name,shop_slug', 'items'])->find($id);
        if (! $row) {
            return response()->json(['message' => 'Seller order not found.', 'error' => 'not_found'], 404);
        }

        return response()->json(['data' => $this->orderRow($row, true)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function productRow(SellerProduct $row, bool $detail = false): array
    {
        $data = [
            'id' => (int) $row->id,
            'sellerId' => (int) $row->seller_id,
            'shopName' => (string) ($row->seller?->shop_name ?? ''),
            'productId' => $row->product_id ? (int) $row->product_id : null,
            'name' => (string) ($row->name ?? ''),
            'sku' => (string) ($row->sku ?? ''),
            'price' => (float) ($row->price ?? 0),
            'stockQty' => (float) ($row->stock_qty ?? 0),
            'approvalStatus' => (string) ($row->approval_status ?? ''),
            'isEnabled' => $row->is_enabled ? 1 : 0,
            'createdAt' => optional($row->created_at)?->toIso8601String(),
        ];
        if ($detail) {
            $data['reviewNote'] = (string) ($row->approval_note ?? '');
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function orderRow(SellerOrder $row, bool $detail = false): array
    {
        $data = [
            'id' => (int) $row->id,
            'sellerId' => (int) $row->seller_id,
            'shopName' => (string) ($row->seller?->shop_name ?? ''),
            'number' => (string) ($row->number ?? ''),
            'status' => (string) ($row->status ?? ''),
            'paymentStatus' => (string) ($row->payment_status ?? ''),
            'customerEmail' => (string) ($row->customer_email ?? ''),
            'subtotal' => (float) ($row->subtotal ?? 0),
            'grandTotal' => (float) ($row->grand_total ?? $row->subtotal ?? 0),
            'commissionTotal' => (float) ($row->commission_total ?? 0),
            'currencyCode' => (string) ($row->currency_code ?? ''),
            'createdAt' => optional($row->created_at)?->toIso8601String(),
        ];
        if ($detail) {
            $data['items'] = $row->relationLoaded('items')
                ? $row->items->map(fn ($item) => [
                    'id' => (int) $item->id,
                    'sku' => (string) ($item->sku ?? ''),
                    'name' => (string) ($item->name ?? ''),
                    'qty' => (float) ($item->qty ?? 0),
                    'unitPrice' => (float) ($item->unit_price ?? 0),
                    'total' => (float) ($item->total ?? 0),
                ])->values()->all()
                : [];
        }

        return $data;
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
