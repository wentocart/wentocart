<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Marketplace\Models\MarketplaceDiscountJoin;
use App\Domain\Marketplace\SellerDiscountJoinSchema;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 */
class AdminMarketplaceDiscountJoinsRestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        SellerDiscountJoinSchema::ensure();
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));
        $page = max(1, (int) $request->query('page', 1));
        $q = MarketplaceDiscountJoin::query()
            ->with(['seller:id,shop_name,shop_slug', 'discountRule:id,name,is_enabled,priority,starts_at,expires_at'])
            ->orderByDesc('id');
        $status = trim((string) ($request->query('status') ?? ''));
        if (in_array($status, MarketplaceDiscountJoin::STATUSES, true)) {
            $q->where('status', $status);
        }
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $perPage)->get()->map(fn (MarketplaceDiscountJoin $j) => [
            'id' => (int) $j->id,
            'sellerId' => (int) $j->seller_id,
            'shopName' => (string) ($j->seller?->shop_name ?? ''),
            'discountRuleId' => (int) $j->discount_rule_id,
            'discountName' => (string) ($j->discountRule?->name ?? ''),
            'status' => (string) $j->status,
            'discountEnabled' => (bool) ($j->discountRule?->is_enabled ?? false),
            'priority' => (int) ($j->discountRule?->priority ?? 0),
            'startsAt' => optional($j->discountRule?->starts_at)?->toIso8601String(),
            'expiresAt' => optional($j->discountRule?->expires_at)?->toIso8601String(),
            'createdAt' => optional($j->created_at)?->toIso8601String(),
        ])->values()->all();

        return response()->json([
            'data' => $rows,
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage],
        ]);
    }

    public function approve(int $id): JsonResponse
    {
        return $this->setStatus($id, MarketplaceDiscountJoin::STATUS_APPROVED, 'Join request approved.');
    }

    public function disapprove(int $id): JsonResponse
    {
        return $this->setStatus($id, MarketplaceDiscountJoin::STATUS_DISAPPROVED, 'Join request disapproved.');
    }

    private function setStatus(int $id, string $status, string $message): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        SellerDiscountJoinSchema::ensure();
        $join = MarketplaceDiscountJoin::query()->find($id);
        if (! $join) {
            return response()->json(['message' => 'Join request not found.', 'error' => 'not_found'], 404);
        }
        $join->update(['status' => $status]);

        return response()->json([
            'message' => $message,
            'data' => ['id' => (int) $join->id, 'status' => $status],
        ]);
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
