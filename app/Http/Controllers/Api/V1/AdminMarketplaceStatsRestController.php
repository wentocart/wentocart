<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerEarning;
use App\Domain\Marketplace\Models\SellerMessage;
use App\Domain\Marketplace\Models\SellerOrder;
use App\Domain\Marketplace\Models\SellerProduct;
use App\Domain\Marketplace\Models\SellerReturn;
use App\Domain\Marketplace\Models\SellerReview;
use App\Domain\Marketplace\Models\SellerWithdrawal;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 */
class AdminMarketplaceStatsRestController extends Controller
{
    public function overview(): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        $sellersByStatus = Seller::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($v) => (int) $v)
            ->all();

        $pendingProducts = SellerProduct::query()
            ->where('approval_status', SellerProduct::STATUS_PENDING)
            ->count();
        $pendingReviews = SellerReview::query()
            ->where('status', SellerReview::STATUS_PENDING)
            ->count();
        $openReturns = SellerReturn::query()
            ->whereIn('status', [SellerReturn::STATUS_REQUESTED, SellerReturn::STATUS_APPROVED, SellerReturn::STATUS_RECEIVED])
            ->count();
        $openMessages = SellerMessage::query()->whereNull('reply_body')->count();
        $pendingPayouts = SellerWithdrawal::query()
            ->whereIn('status', SellerWithdrawal::HOLDING_STATUSES)
            ->count();
        $ordersOpen = SellerOrder::query()
            ->whereIn('status', ['pending', 'processing', 'shipped'])
            ->count();
        $earningsPending = SellerEarning::query()
            ->whereIn('status', ['pending', 'available', 'held'])
            ->count();

        $gmv30 = (float) SellerOrder::query()
            ->where('created_at', '>=', now()->subDays(30))
            ->sum('grand_total');
        $commission30 = (float) SellerOrder::query()
            ->where('created_at', '>=', now()->subDays(30))
            ->sum('commission_total');

        return response()->json([
            'data' => [
                'sellers' => [
                    'byStatus' => $sellersByStatus,
                    'total' => (int) array_sum($sellersByStatus),
                    'pendingApproval' => (int) ($sellersByStatus[Seller::STATUS_PENDING] ?? 0),
                ],
                'attention' => [
                    'pendingProducts' => (int) $pendingProducts,
                    'pendingReviews' => (int) $pendingReviews,
                    'openReturns' => (int) $openReturns,
                    'openMessages' => (int) $openMessages,
                    'pendingPayouts' => (int) $pendingPayouts,
                    'openOrders' => (int) $ordersOpen,
                    'pendingEarnings' => (int) $earningsPending,
                ],
                'performance' => [
                    'gmvLast30Days' => round($gmv30, 2),
                    'commissionLast30Days' => round($commission30, 2),
                ],
            ],
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
