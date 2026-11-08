<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Marketplace\Models\ProductFlag;
use App\Domain\Marketplace\ProductFlagSchema;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 */
class AdminMarketplaceProductFlagsRestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        ProductFlagSchema::ensure();
        $perPage = min(100, max(1, (int) $request->query('per_page', $request->query('limit', 20))));
        $page = max(1, (int) $request->query('page', 1));
        $q = ProductFlag::query()
            ->with(['product:id,sku,seller_id', 'seller:id,shop_name', 'customer:id,email'])
            ->orderByDesc('id');
        $sellerId = (int) ($request->query('seller_id') ?? 0);
        if ($sellerId > 0) {
            $q->where('seller_id', $sellerId);
        }
        $status = trim((string) ($request->query('status') ?? ''));
        if (in_array($status, ProductFlag::STATUSES, true)) {
            $q->where('status', $status);
        }
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $perPage)->get()
            ->map(fn (ProductFlag $row) => $this->row($row))
            ->values()
            ->all();

        return response()->json([
            'data' => $rows,
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        ProductFlagSchema::ensure();
        $row = ProductFlag::query()
            ->with(['product:id,sku,seller_id', 'seller:id,shop_name', 'customer:id,email'])
            ->find($id);
        if (! $row) {
            return response()->json(['message' => 'Product flag not found.', 'error' => 'not_found'], 404);
        }

        return response()->json(['data' => $this->row($row, true)]);
    }

    public function moderate(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        ProductFlagSchema::ensure();
        $row = ProductFlag::query()->find($id);
        if (! $row) {
            return response()->json(['message' => 'Product flag not found.', 'error' => 'not_found'], 404);
        }
        $action = trim((string) ($request->input('action') ?? $request->input('status') ?? ''));
        $note = trim((string) ($request->input('note') ?? ''));
        $status = match ($action) {
            'accept', 'accepted' => ProductFlag::STATUS_ACCEPTED,
            'dismiss', 'dismissed', 'reject', 'rejected' => ProductFlag::STATUS_DISMISSED,
            'pending' => ProductFlag::STATUS_PENDING,
            default => null,
        };
        if ($status === null) {
            return response()->json(['message' => 'action must be accept, dismiss, or pending.', 'error' => 'validation'], 422);
        }
        $row->update([
            'status' => $status,
            'moderated_by' => Auth::guard('admin')->id() ? (int) Auth::guard('admin')->id() : null,
            'moderated_at' => now(),
            'moderation_note' => $note !== '' ? $note : null,
        ]);

        return response()->json([
            'message' => 'Product flag updated.',
            'data' => $this->row($row->fresh(['product:id,sku', 'seller:id,shop_name', 'customer:id,email']) ?? $row, true),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        ProductFlagSchema::ensure();
        $row = ProductFlag::query()->find($id);
        if (! $row) {
            return response()->json(['message' => 'Product flag not found.', 'error' => 'not_found'], 404);
        }
        $row->delete();

        return response()->json(['message' => 'Product flag deleted.', 'data' => ['id' => $id]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(ProductFlag $row, bool $detail = false): array
    {
        $data = [
            'id' => (int) $row->id,
            'productId' => (int) $row->product_id,
            'productSku' => (string) ($row->product?->sku ?? ''),
            'sellerId' => $row->seller_id ? (int) $row->seller_id : null,
            'shopName' => (string) ($row->seller?->shop_name ?? ''),
            'reason' => (string) ($row->reason ?? ''),
            'status' => (string) ($row->status ?? ''),
            'createdAt' => optional($row->created_at)?->toIso8601String(),
        ];
        if ($detail) {
            $data['body'] = (string) ($row->body ?? '');
            $data['customerEmail'] = (string) ($row->customer?->email ?? '');
            $data['moderationNote'] = (string) ($row->moderation_note ?? '');
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
