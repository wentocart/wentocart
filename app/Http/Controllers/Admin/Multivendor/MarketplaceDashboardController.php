<?php

namespace App\Http\Controllers\Admin\Multivendor;

use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerOrder;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 18 — admin marketplace dashboard (Inertia, auth:admin + ACL).
 */
class MarketplaceDashboardController extends Controller
{
    public function __invoke(): Response
    {
        $counts = app(\App\Domain\Marketplace\Services\MarketplaceStatsService::class)->adminDashboardCounts();

        $stats = [
            [
                'key' => 'pending_applications',
                'label' => 'Pending applications',
                'value' => $counts['pending_applications'],
                'href' => '/admin/multivendor/seller-requests?status=pending',
            ],
            [
                'key' => 'active_sellers',
                'label' => 'Active sellers',
                'value' => $counts['active_sellers'],
                'href' => '/admin/multivendor/sellers?status=approved',
            ],
            [
                'key' => 'pending_products',
                'label' => 'Products awaiting review',
                'value' => $counts['pending_products'],
                'href' => '/admin/multivendor/products?status=pending',
            ],
            [
                'key' => 'open_orders',
                'label' => 'Open seller orders',
                'value' => $counts['open_orders'],
                'href' => '/admin/multivendor/orders',
            ],
            [
                'key' => 'open_returns',
                'label' => 'Open returns',
                'value' => $counts['open_returns'],
                'href' => '/admin/multivendor/returns',
            ],
            [
                'key' => 'pending_payouts',
                'label' => 'Payouts in progress',
                'value' => $counts['pending_payouts'],
                'href' => '/admin/multivendor/payouts',
            ],
            [
                'key' => 'pending_reviews',
                'label' => 'Reviews to moderate',
                'value' => $counts['pending_reviews'],
                'href' => '/admin/multivendor/reviews?status=pending',
            ],
            [
                'key' => 'pending_earnings',
                'label' => 'Pending earnings rows',
                'value' => $counts['pending_earnings'],
                'href' => '/admin/multivendor/earnings?status=pending',
            ],
        ];

        $recentSellers = Seller::query()
            ->with('customer:id,name,email,first_name,last_name')
            ->latest('id')
            ->limit(8)
            ->get()
            ->map(fn (Seller $seller) => [
                'id' => $seller->id,
                'shop_name' => $seller->shop_name,
                'status' => $seller->status,
                'email' => $seller->email,
                'href' => '/admin/multivendor/sellers/'.$seller->id,
                'created_at' => $seller->created_at?->toDateString(),
            ]);

        $recentOrders = SellerOrder::query()
            ->with('seller:id,shop_name')
            ->latest('id')
            ->limit(8)
            ->get()
            ->map(fn (SellerOrder $order) => [
                'id' => $order->id,
                'number' => $order->number,
                'status' => $order->status,
                'shop_name' => $order->seller?->shop_name,
                'grand_total' => (float) $order->grand_total,
                'href' => '/admin/multivendor/orders/'.$order->id,
            ]);

        return Inertia::render('Multivendor/Dashboard', [
            'stats' => $stats,
            'recent_sellers' => $recentSellers,
            'recent_orders' => $recentOrders,
            'links' => [
                ['href' => '/admin/multivendor/seller-requests', 'label' => 'Applications'],
                ['href' => '/admin/multivendor/sellers', 'label' => 'Sellers'],
                ['href' => '/admin/multivendor/products', 'label' => 'Catalog moderation'],
                ['href' => '/admin/multivendor/orders', 'label' => 'Seller orders'],
                ['href' => '/admin/multivendor/commissions', 'label' => 'Commissions'],
                ['href' => '/admin/multivendor/earnings', 'label' => 'Earnings'],
                ['href' => '/admin/multivendor/payouts', 'label' => 'Payouts'],
                ['href' => '/admin/multivendor/returns', 'label' => 'Returns'],
                ['href' => '/admin/multivendor/settings', 'label' => 'Marketplace settings'],
            ],
        ]);
    }
}
