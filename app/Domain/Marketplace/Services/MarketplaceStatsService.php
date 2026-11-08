<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerEarning;
use App\Domain\Marketplace\Models\SellerOrder;
use App\Domain\Marketplace\Models\SellerProduct;
use App\Domain\Marketplace\Models\SellerReturn;
use App\Domain\Marketplace\Models\SellerReview;
use App\Domain\Marketplace\Models\SellerOrderItem;
use App\Domain\Marketplace\Models\SellerWithdrawal;
use Illuminate\Support\Facades\DB;

/**
 * Phase 21 — consolidated marketplace aggregate queries for dashboards and tab counts.
 */
class MarketplaceStatsService
{
    /** SQL expression matching SellerEarning::remainingNet(). */
    protected function remainingNetSumSql(): string
    {
        return 'SUM(CASE WHEN (net_amount - refunded_net - paid_net) > 0 THEN (net_amount - refunded_net - paid_net) ELSE 0 END)';
    }

    /**
     * @return array{
     *   pending_applications: int,
     *   active_sellers: int,
     *   pending_products: int,
     *   open_orders: int,
     *   open_returns: int,
     *   pending_payouts: int,
     *   pending_reviews: int,
     *   pending_earnings: int
     * }
     */
    public function adminDashboardCounts(): array
    {
        $sellers = Seller::query()->selectRaw(
            'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pending_applications, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as active_sellers',
            [Seller::STATUS_PENDING, Seller::STATUS_APPROVED]
        )->first();

        $products = (int) SellerProduct::query()
            ->where('approval_status', SellerProduct::STATUS_PENDING)
            ->count();

        $orders = (int) SellerOrder::query()
            ->whereIn('status', ['pending', 'processing', 'shipped'])
            ->count();

        $returns = (int) SellerReturn::query()
            ->whereIn('status', [
                SellerReturn::STATUS_REQUESTED,
                SellerReturn::STATUS_APPROVED,
                SellerReturn::STATUS_RECEIVED,
            ])
            ->count();

        $payouts = (int) SellerWithdrawal::query()
            ->whereIn('status', SellerWithdrawal::HOLDING_STATUSES)
            ->count();

        $reviews = (int) SellerReview::query()
            ->where('status', SellerReview::STATUS_PENDING)
            ->count();

        $earnings = (int) SellerEarning::query()
            ->where('status', SellerEarning::STATUS_PENDING)
            ->count();

        return [
            'pending_applications' => (int) ($sellers->pending_applications ?? 0),
            'active_sellers' => (int) ($sellers->active_sellers ?? 0),
            'pending_products' => $products,
            'open_orders' => $orders,
            'open_returns' => $returns,
            'pending_payouts' => $payouts,
            'pending_reviews' => $reviews,
            'pending_earnings' => $earnings,
        ];
    }

    /**
     * @return array<string, int>
     */
    public function sellerListTabCounts(): array
    {
        $row = Seller::query()->selectRaw(
            'COUNT(*) as all_count,
            SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as rejected,
            SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as blocked',
            [
                Seller::STATUS_PENDING,
                Seller::STATUS_APPROVED,
                Seller::STATUS_REJECTED,
                Seller::STATUS_BLOCKED,
            ]
        )->first();

        return [
            'all' => (int) ($row->all_count ?? 0),
            Seller::STATUS_PENDING => (int) ($row->pending ?? 0),
            Seller::STATUS_APPROVED => (int) ($row->approved ?? 0),
            Seller::STATUS_REJECTED => (int) ($row->rejected ?? 0),
            Seller::STATUS_BLOCKED => (int) ($row->blocked ?? 0),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function sellerProductTabCounts(?int $sellerId = null): array
    {
        $query = SellerProduct::query();
        if ($sellerId) {
            $query->where('seller_id', $sellerId);
        }

        $row = (clone $query)->selectRaw(
            'COUNT(*) as all_count,
            SUM(CASE WHEN approval_status = ? THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN approval_status = ? THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN approval_status = ? THEN 1 ELSE 0 END) as rejected,
            SUM(CASE WHEN is_enabled = 1 THEN 1 ELSE 0 END) as enabled,
            SUM(CASE WHEN is_enabled = 0 THEN 1 ELSE 0 END) as disabled',
            [
                SellerProduct::STATUS_PENDING,
                SellerProduct::STATUS_APPROVED,
                SellerProduct::STATUS_REJECTED,
            ]
        )->first();

        return [
            'all' => (int) ($row->all_count ?? 0),
            'pending' => (int) ($row->pending ?? 0),
            'approved' => (int) ($row->approved ?? 0),
            'rejected' => (int) ($row->rejected ?? 0),
            'enabled' => (int) ($row->enabled ?? 0),
            'disabled' => (int) ($row->disabled ?? 0),
        ];
    }

    /**
     * @return array{
     *   products: int,
     *   orders: int,
     *   pending_orders: int,
     *   open_returns: int,
     *   reviews: int,
     *   earnings: float
     * }
     */
    public function sellerPortalStats(Seller $seller): array
    {
        $sellerId = (int) $seller->id;
        $pendingStatuses = ['pending', 'processing'];
        $openReturnStatuses = ['requested', 'approved', 'received'];
        $pendingPlaceholders = implode(',', array_fill(0, count($pendingStatuses), '?'));
        $returnPlaceholders = implode(',', array_fill(0, count($openReturnStatuses), '?'));
        $prefix = DB::connection()->getTablePrefix();
        $productsTable = $prefix.(new SellerProduct)->getTable();
        $ordersTable = $prefix.(new SellerOrder)->getTable();
        $returnsTable = $prefix.(new SellerReturn)->getTable();
        $reviewsTable = $prefix.(new SellerReview)->getTable();
        $earningsTable = $prefix.(new SellerEarning)->getTable();

        $row = DB::selectOne(
            'SELECT
                (SELECT COUNT(*) FROM '.$productsTable.' WHERE seller_id = ?) AS products,
                (SELECT COUNT(*) FROM '.$ordersTable.' WHERE seller_id = ?) AS orders,
                (SELECT COUNT(*) FROM '.$ordersTable.' WHERE seller_id = ? AND status IN ('.$pendingPlaceholders.')) AS pending_orders,
                (SELECT COALESCE(SUM(CASE WHEN status IN ('.$returnPlaceholders.') THEN 1 ELSE 0 END), 0) FROM '.$returnsTable.' WHERE seller_id = ?) AS open_returns,
                (SELECT COUNT(*) FROM '.$reviewsTable.' WHERE seller_id = ?) AS reviews,
                (SELECT COALESCE(SUM(net_amount), 0) FROM '.$earningsTable.' WHERE seller_id = ?) AS earnings',
            array_merge(
                [$sellerId, $sellerId, $sellerId],
                $pendingStatuses,
                $openReturnStatuses,
                [$sellerId, $sellerId, $sellerId]
            )
        );

        return [
            'products' => (int) ($row->products ?? 0),
            'orders' => (int) ($row->orders ?? 0),
            'pending_orders' => (int) ($row->pending_orders ?? 0),
            'open_returns' => (int) ($row->open_returns ?? 0),
            'reviews' => (int) ($row->reviews ?? 0),
            'earnings' => round((float) ($row->earnings ?? 0), 2),
        ];
    }

    /**
     * Public storefront stats for a seller shop page.
     *
     * @return array{products_count: int, sold_count: int, member_since: string|null}
     */
    public function publicShopStats(Seller $seller, int $productsCount): array
    {
        $eligible = SellerReviewService::ELIGIBLE_SELLER_ORDER_STATUSES;

        $soldCount = (int) SellerOrderItem::query()
            ->whereIn('seller_order_id', SellerOrder::query()
                ->where('seller_id', $seller->id)
                ->whereIn('status', $eligible)
                ->select('id'))
            ->selectRaw('COALESCE(SUM(CASE WHEN (qty - returned_qty - cancelled_qty) > 0 THEN (qty - returned_qty - cancelled_qty) ELSE 0 END), 0) as sold')
            ->value('sold');

        return [
            'products_count' => $productsCount,
            'sold_count' => max(0, $soldCount),
            'member_since' => $seller->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{
     *   product_count: int,
     *   order_count: int,
     *   return_count: int,
     *   review_count: int,
     *   pending_product_count: int
     * }
     */
    public function sellerDetailStats(Seller $seller): array
    {
        $productRow = SellerProduct::query()
            ->where('seller_id', $seller->id)
            ->selectRaw(
                'COUNT(*) as product_count, SUM(CASE WHEN approval_status = ? THEN 1 ELSE 0 END) as pending_product_count',
                [SellerProduct::STATUS_PENDING]
            )
            ->first();

        return [
            'product_count' => (int) ($productRow->product_count ?? 0),
            'order_count' => $seller->orders()->count(),
            'return_count' => $seller->returns()->count(),
            'review_count' => $seller->sellerReviews()->count(),
            'pending_product_count' => (int) ($productRow->pending_product_count ?? 0),
        ];
    }

    /**
     * @return array{gross: float, commission: float, net: float}
     */
    public function earningsTotals(Seller $seller): array
    {
        $row = SellerEarning::query()
            ->where('seller_id', $seller->id)
            ->selectRaw('COALESCE(SUM(gross_amount), 0) as gross, COALESCE(SUM(commission_amount), 0) as commission, COALESCE(SUM(net_amount), 0) as net')
            ->first();

        return [
            'gross' => (float) ($row->gross ?? 0),
            'commission' => (float) ($row->commission ?? 0),
            'net' => (float) ($row->net ?? 0),
        ];
    }

    /**
     * Remaining net totals grouped by earning status (wallet buckets).
     *
     * @return array{pending: float, available: float, paid: float}
     */
    public function earningRemainingByStatus(Seller $seller): array
    {
        $expr = $this->remainingNetSumSql();
        $rows = SellerEarning::query()
            ->where('seller_id', $seller->id)
            ->whereIn('status', [
                SellerEarning::STATUS_PENDING,
                SellerEarning::STATUS_AVAILABLE,
                SellerEarning::STATUS_PAID,
            ])
            ->groupBy('status')
            ->selectRaw("status, {$expr} as total")
            ->pluck('total', 'status');

        return [
            'pending' => round((float) ($rows[SellerEarning::STATUS_PENDING] ?? 0), 2),
            'available' => round((float) ($rows[SellerEarning::STATUS_AVAILABLE] ?? 0), 2),
            'paid' => round((float) ($rows[SellerEarning::STATUS_PAID] ?? 0), 2),
        ];
    }
}
