<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Services\SellerAccountHealthService;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 */
class AdminMarketplaceAccountHealthRestController extends Controller
{
    public function show(int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $seller = Seller::query()->find($id);
        if (! $seller) {
            return response()->json(['message' => 'Seller not found.', 'error' => 'not_found'], 404);
        }
        SellerAccountHealthService::ensureColumns();
        if ($seller->health_refreshed_at) {
            return response()->json([
                'data' => [
                    'sellerId' => (int) $seller->id,
                    'shopName' => (string) $seller->shop_name,
                    'score' => $seller->health_score !== null ? (int) $seller->health_score : null,
                    'band' => (string) ($seller->health_band ?? SellerAccountHealthService::BAND_NOT_CALCULATED),
                    'overall' => (string) ($seller->health_band ?? SellerAccountHealthService::BAND_NOT_CALCULATED),
                    'metrics' => [
                        'cancellation' => [
                            'rate' => $seller->health_cancellation_rate !== null ? (float) $seller->health_cancellation_rate : null,
                        ],
                        'feedback' => [
                            'average' => $seller->health_avg_rating !== null ? (float) $seller->health_avg_rating : null,
                        ],
                        'flags' => [
                            'count' => (int) ($seller->health_flag_count ?? 0),
                            'enabled' => true,
                        ],
                    ],
                    'refreshedAt' => optional($seller->health_refreshed_at)?->toIso8601String(),
                    'cached' => true,
                ],
            ]);
        }

        $fresh = app(SellerAccountHealthService::class)->refresh($seller, true);

        return response()->json(['data' => array_merge($fresh, ['cached' => false])]);
    }

    public function refresh(int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $seller = Seller::query()->find($id);
        if (! $seller) {
            return response()->json(['message' => 'Seller not found.', 'error' => 'not_found'], 404);
        }
        $data = app(SellerAccountHealthService::class)->refresh($seller, true);

        return response()->json([
            'message' => 'Account health refreshed.',
            'data' => array_merge($data, ['cached' => false]),
        ]);
    }

    public function bulkRefresh(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $ids = collect($request->input('ids')  ?? [])
            ->map(fn ($v) => (int) $v)->filter(fn ($v) => $v > 0)->unique()->values()->all();
        if ($ids === []) {
            return response()->json(['message' => 'ids are required.', 'error' => 'validation'], 422);
        }
        $service = app(SellerAccountHealthService::class);
        $refreshed = 0;
        foreach (Seller::query()->whereIn('id', $ids)->cursor() as $seller) {
            $service->refresh($seller, true);
            $refreshed++;
        }

        return response()->json([
            'message' => 'Account health bulk refresh complete.',
            'data' => ['refreshed' => $refreshed],
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
