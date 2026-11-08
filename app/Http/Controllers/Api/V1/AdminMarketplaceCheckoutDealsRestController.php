<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Marketplace\Models\SellerDiscount;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 */
class AdminMarketplaceCheckoutDealsRestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));
        $page = max(1, (int) $request->query('page', 1));
        $q = SellerDiscount::query()
            ->with('seller:id,shop_name,shop_slug')
            ->whereIn('apply_as', ['cart', 'coupon'])
            ->orderByDesc('id');
        $sellerId = (int) ($request->query('seller_id') ?? 0);
        if ($sellerId > 0) {
            $q->where('seller_id', $sellerId);
        }
        $status = trim((string) ($request->query('status') ?? ''));
        if ($status === 'active') {
            $q->where('is_enabled', true);
        } elseif ($status === 'inactive') {
            $q->where('is_enabled', false);
        }
        $term = trim((string) ($request->query('q') ?? $request->query('search') ?? ''));
        if ($term !== '') {
            $q->where('name', 'like', '%'.$term.'%');
        }
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $perPage)->get()->map(fn (SellerDiscount $d) => [
            'id' => (int) $d->id,
            'sellerId' => (int) $d->seller_id,
            'shopName' => (string) ($d->seller?->shop_name ?? ''),
            'name' => (string) $d->name,
            'applyAs' => (string) $d->apply_as,
            'isEnabled' => (bool) $d->is_enabled,
            'priority' => 80,
            'startsAt' => optional($d->starts_at)?->toIso8601String(),
            'expiresAt' => optional($d->expires_at)?->toIso8601String(),
        ])->values()->all();

        return response()->json([
            'data' => $rows,
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage],
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $row = SellerDiscount::query()->whereIn('apply_as', ['cart', 'coupon'])->find($id);
        if (! $row) {
            return response()->json(['message' => 'Checkout deal not found.', 'error' => 'not_found'], 404);
        }
        if ($row->discount_rule_id) {
            \App\Domain\Promo\Models\DiscountRule::query()
                ->whereKey($row->discount_rule_id)
                ->where('seller_id', $row->seller_id)
                ->delete();
        }
        $row->delete();

        return response()->json(['message' => 'Checkout deal deleted.', 'data' => ['id' => $id]]);
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
