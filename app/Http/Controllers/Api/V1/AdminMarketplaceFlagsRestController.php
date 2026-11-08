<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Marketplace\Models\SellerFlag;
use App\Domain\Marketplace\SellerFlagSchema;
use App\Domain\Marketplace\Services\SellerAccountHealthService;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 */
class AdminMarketplaceFlagsRestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        SellerFlagSchema::ensure();
        $perPage = min(100, max(1, (int) $request->query('per_page', $request->query('limit', 20))));
        $page = max(1, (int) $request->query('page', 1));
        $q = SellerFlag::query()
            ->with(['seller:id,shop_name,shop_slug', 'customer:id,name,email'])
            ->orderByDesc('id');
        $sellerId = (int) ($request->query('seller_id') ?? $request->query('sellerId') ?? 0);
        if ($sellerId > 0) {
            $q->where('seller_id', $sellerId);
        }
        $status = trim((string) ($request->query('status') ?? ''));
        if (in_array($status, SellerFlag::STATUSES, true)) {
            $q->where('status', $status);
        }
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $perPage)->get()
            ->map(fn (SellerFlag $row) => $this->row($row))
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
        SellerFlagSchema::ensure();
        $row = SellerFlag::query()
            ->with(['seller:id,shop_name,shop_slug', 'customer:id,name,email'])
            ->find($id);
        if (! $row) {
            return response()->json(['message' => 'Seller flag not found.', 'error' => 'not_found'], 404);
        }

        return response()->json(['data' => $this->row($row, true)]);
    }

    public function moderate(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        SellerFlagSchema::ensure();
        $row = SellerFlag::query()->find($id);
        if (! $row) {
            return response()->json(['message' => 'Seller flag not found.', 'error' => 'not_found'], 404);
        }
        $action = trim((string) ($request->input('action') ?? $request->input('status') ?? ''));
        $note = trim((string) ($request->input('note') ?? $request->input('notes') ?? ''));
        $status = match ($action) {
            'accept', 'accepted' => SellerFlag::STATUS_ACCEPTED,
            'dismiss', 'dismissed', 'reject', 'rejected' => SellerFlag::STATUS_DISMISSED,
            'pending' => SellerFlag::STATUS_PENDING,
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
        if ($row->seller_id) {
            app(SellerAccountHealthService::class)->refresh($row->seller, true);
        }

        return response()->json([
            'message' => 'Seller flag updated.',
            'data' => $this->row($row->fresh(['seller:id,shop_name', 'customer:id,name,email']) ?? $row, true),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        SellerFlagSchema::ensure();
        $row = SellerFlag::query()->find($id);
        if (! $row) {
            return response()->json(['message' => 'Seller flag not found.', 'error' => 'not_found'], 404);
        }
        $sellerId = (int) $row->seller_id;
        $row->delete();
        if ($sellerId > 0 && ($seller = \App\Domain\Marketplace\Models\Seller::query()->find($sellerId))) {
            app(SellerAccountHealthService::class)->refresh($seller, true);
        }

        return response()->json(['message' => 'Seller flag deleted.', 'data' => ['id' => $id]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(SellerFlag $row, bool $detail = false): array
    {
        $data = [
            'id' => (int) $row->id,
            'sellerId' => (int) $row->seller_id,
            'shopName' => (string) ($row->seller?->shop_name ?? ''),
            'customerId' => $row->customer_id ? (int) $row->customer_id : null,
            'customerEmail' => (string) ($row->customer?->email ?? ''),
            'reason' => (string) ($row->reason ?? ''),
            'status' => (string) ($row->status ?? ''),
            'createdAt' => optional($row->created_at)?->toIso8601String(),
        ];
        if ($detail) {
            $data['body'] = (string) ($row->body ?? '');
            $data['moderationNote'] = (string) ($row->moderation_note ?? '');
            $data['moderatedAt'] = optional($row->moderated_at)?->toIso8601String();
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
