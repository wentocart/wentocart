<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Marketplace\Models\SellerReview;
use App\Domain\Marketplace\Services\SellerReviewService;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 */
class AdminMarketplaceReviewsRestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $perPage = min(100, max(1, (int) $request->query('per_page', $request->query('limit', 20))));
        $page = max(1, (int) $request->query('page', 1));
        $q = SellerReview::query()
            ->with(['seller:id,shop_name,shop_slug', 'customer:id,name,email'])
            ->orderByDesc('id');
        $sellerId = (int) ($request->query('seller_id') ?? $request->query('sellerId') ?? 0);
        if ($sellerId > 0) {
            $q->where('seller_id', $sellerId);
        }
        $status = trim((string) ($request->query('status') ?? ''));
        if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $q->where('status', $status);
        }
        $term = trim((string) ($request->query('q') ?? $request->query('search') ?? ''));
        if ($term !== '') {
            $like = '%'.$term.'%';
            $q->where(function ($inner) use ($like) {
                $inner->where('title', 'like', $like)
                    ->orWhere('body', 'like', $like)
                    ->orWhereHas('customer', fn ($c) => $c->where('email', 'like', $like)->orWhere('name', 'like', $like));
            });
        }
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $perPage)->get()
            ->map(fn (SellerReview $row) => $this->row($row))
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
        $row = SellerReview::query()
            ->with(['seller:id,shop_name,shop_slug', 'customer:id,name,email'])
            ->find($id);
        if (! $row) {
            return response()->json(['message' => 'Seller review not found.', 'error' => 'not_found'], 404);
        }

        return response()->json(['data' => $this->row($row, true)]);
    }

    public function moderate(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $row = SellerReview::query()->find($id);
        if (! $row) {
            return response()->json(['message' => 'Seller review not found.', 'error' => 'not_found'], 404);
        }
        $action = trim((string) ($request->input('action') ?? $request->input('status') ?? ''));
        $note = trim((string) ($request->input('note') ?? $request->input('notes') ?? ''));
        $adminId = Auth::guard('admin')->id() ? (int) Auth::guard('admin')->id() : null;

        if (in_array($action, ['approve', 'approved'], true)) {
            app(SellerReviewService::class)->moderate($row, 'approve', $adminId, $note !== '' ? $note : null);
        } elseif (in_array($action, ['reject', 'rejected', 'disapproved'], true)) {
            app(SellerReviewService::class)->moderate($row, 'reject', $adminId, $note !== '' ? $note : null);
        } elseif (in_array($action, ['pending'], true)) {
            $row->update([
                'status' => SellerReview::STATUS_PENDING,
                'moderated_by' => $adminId,
                'moderated_at' => now(),
                'moderation_note' => $note !== '' ? $note : null,
            ]);
        } else {
            return response()->json([
                'message' => 'action must be approve, reject, or pending.',
                'error' => 'validation',
            ], 422);
        }

        $fresh = $row->fresh(['seller:id,shop_name,shop_slug', 'customer:id,name,email']);

        return response()->json([
            'message' => 'Seller review updated.',
            'data' => $this->row($fresh ?? $row, true),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        return $this->moderate($request, $id);
    }

    public function destroy(int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $row = SellerReview::query()->find($id);
        if (! $row) {
            return response()->json(['message' => 'Seller review not found.', 'error' => 'not_found'], 404);
        }
        $row->delete();

        return response()->json(['message' => 'Seller review deleted.', 'data' => ['id' => $id]]);
    }

    public function bulkUpdate(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $ids = collect($request->input('ids')  ?? [])
            ->map(fn ($v) => (int) $v)->filter(fn ($v) => $v > 0)->unique()->values()->all();
        $action = trim((string) ($request->input('action') ?? $request->input('status') ?? ''));
        if ($ids === [] || $action === '') {
            return response()->json(['message' => 'ids and action are required.', 'error' => 'validation'], 422);
        }
        $updated = 0;
        foreach ($ids as $id) {
            $resp = $this->moderate(new Request(['action' => $action]), $id);
            if ($resp->getStatusCode() === 200) {
                $updated++;
            }
        }

        return response()->json(['message' => 'Bulk update complete.', 'data' => ['updated' => $updated]]);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $ids = collect($request->input('ids')  ?? [])
            ->map(fn ($v) => (int) $v)->filter(fn ($v) => $v > 0)->unique()->values()->all();
        if ($ids === []) {
            return response()->json(['message' => 'ids are required.', 'error' => 'validation'], 422);
        }
        $deleted = SellerReview::query()->whereIn('id', $ids)->delete();

        return response()->json(['message' => 'Bulk delete complete.', 'data' => ['deleted' => (int) $deleted]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(SellerReview $row, bool $detail = false): array
    {
        $data = [
            'id' => (int) $row->id,
            'sellerId' => (int) $row->seller_id,
            'shopName' => (string) ($row->seller?->shop_name ?? ''),
            'customerId' => (int) ($row->customer_id ?? 0),
            'customerName' => (string) ($row->customer?->name ?? ''),
            'customerEmail' => (string) ($row->customer?->email ?? ''),
            'rating' => (int) ($row->rating ?? 0),
            'title' => (string) ($row->title ?? ''),
            'status' => (string) ($row->status ?? ''),
            'isVerifiedPurchase' => $row->is_verified_purchase ? 1 : 0,
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
