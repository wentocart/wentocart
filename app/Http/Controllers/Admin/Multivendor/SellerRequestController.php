<?php

namespace App\Http\Controllers\Admin\Multivendor;

use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Services\SellerService;
use App\Http\Controllers\Admin\Multivendor\Concerns\PresentsMultivendor;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SellerRequestController extends Controller
{
    use PresentsMultivendor;

    public function __construct(protected SellerService $sellers) {}

    public function index(Request $request): Response
    {
        $filters = $this->listFilters($request);
        $query = Seller::query()->with('customer:id,name,email,first_name,last_name');
        if (in_array($filters['status'], Seller::STATUSES, true)) {
            $query->where('status', $filters['status']);
        }

        $this->applySellerColumnFilters($query, $filters);

        $this->applyListIdRange($query, $filters);
        $this->applySellerPeopleSort($query, $filters, ['id', 'shop', 'shop_name', 'created_at', 'status'], ['shop' => 'shop_name']);

        return Inertia::render('Multivendor/List', [
            'screen' => 'requests',
            'title' => 'Seller Request',
            'path' => '/admin/multivendor/seller-requests',
            'filters' => $filters,
            'columns' => [
                ['key' => 'shop', 'label' => 'Shop'],
                ['key' => 'seller', 'label' => 'Seller'],
                ['key' => 'customer', 'label' => 'Customer'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'created_at', 'label' => 'Requested'],
            ],
            'empty' => 'No seller requests yet.',
            'rows' => $query
                ->adminPaginate()
                ->through(fn (Seller $seller) => array_merge($this->sellerRow($seller), [
                    'shop' => $seller->shop_name,
                    'seller' => trim($seller->first_name.' '.$seller->last_name),
                    'customer' => $seller->customer?->displayName() ?: $seller->customer?->email ?: '—',
                    'href' => '/admin/multivendor/seller-requests/'.$seller->id,
                    'action_label' => 'Review',
                    'can_moderate' => $seller->status === Seller::STATUS_PENDING,
                ])),
        ]);
    }

    public function show(Seller $seller): Response
    {
        $seller->load(['customer:id,name,email,first_name,last_name', 'commissions']);
        $wallet = app(\App\Domain\Marketplace\Services\SellerEarningAvailabilityService::class)->walletBalances($seller);
        $detailStats = app(\App\Domain\Marketplace\Services\MarketplaceStatsService::class)->sellerDetailStats($seller);

        return Inertia::render('Multivendor/RequestShow', [
            'seller' => $this->sellerRow($seller) + [
                'profile_name' => $seller->profile_name,
                'phone' => $seller->phone,
                'contact_name' => $seller->contact_name,
                'contact_phone' => $seller->contact_phone,
                'contact_email' => $seller->contact_email,
                'address_line1' => $seller->address_line1,
                'address_line2' => $seller->address_line2,
                'city' => $seller->city,
                'state' => $seller->state,
                'postal_code' => $seller->postal_code,
                'country_code' => $seller->country_code,
                'description' => $seller->description,
                'tax_id' => $seller->tax_id,
                'review_note' => $seller->review_note,
                'commission_rate' => (float) $seller->commission_rate,
                'product_count' => $detailStats['product_count'],
                'order_count' => $detailStats['order_count'],
                'return_count' => $detailStats['return_count'],
                'review_count' => $detailStats['review_count'],
                'pending_product_count' => $detailStats['pending_product_count'],
                'wallet' => [
                    'pending' => $wallet['pending'],
                    'available' => $wallet['available_for_payout'],
                    'paid' => $wallet['paid'],
                    'ledger_balance' => $wallet['ledger_balance'],
                ],
                'links' => [
                    ['href' => '/admin/multivendor/seller-profiles/'.$seller->id.'/edit', 'label' => 'Edit profile'],
                    ['href' => '/admin/multivendor/seller-shops/'.$seller->id.'/edit', 'label' => 'Edit shop'],
                    ['href' => '/admin/multivendor/products?seller_id='.$seller->id, 'label' => 'Products'],
                    ['href' => '/admin/multivendor/orders?seller_id='.$seller->id, 'label' => 'Orders'],
                    ['href' => '/admin/multivendor/returns?seller_id='.$seller->id, 'label' => 'Returns'],
                    ['href' => '/admin/multivendor/earnings?seller_id='.$seller->id, 'label' => 'Earnings'],
                    ['href' => '/admin/multivendor/payouts?seller_id='.$seller->id, 'label' => 'Payouts'],
                    ['href' => '/admin/multivendor/reviews?seller_id='.$seller->id, 'label' => 'Reviews'],
                ],
                'commissions' => $seller->commissions,
            ],
        ]);
    }

    public function sellers(Request $request): Response
    {
        $filters = $this->listFilters($request);
        $query = Seller::query()->with('customer:id,name,email,first_name,last_name');
        if (in_array($filters['status'], Seller::STATUSES, true)) {
            $query->where('status', $filters['status']);
        } else {
            $query->whereIn('status', [Seller::STATUS_APPROVED, Seller::STATUS_BLOCKED]);
        }

        $this->applySellerColumnFilters($query, $filters);

        $this->applyListIdRange($query, $filters);
        $this->applySellerPeopleSort($query, $filters, ['id', 'shop', 'shop_name', 'created_at', 'status'], ['shop' => 'shop_name']);

        return Inertia::render('Multivendor/List', [
            'screen' => 'sellers',
            'title' => 'Sellers',
            'path' => '/admin/multivendor/sellers',
            'filters' => $filters,
            'columns' => [
                ['key' => 'shop', 'label' => 'Shop'],
                ['key' => 'seller', 'label' => 'Seller'],
                ['key' => 'customer', 'label' => 'Customer'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'created_at', 'label' => 'Created'],
            ],
            'empty' => 'No sellers yet.',
            'rows' => $query
                ->adminPaginate()
                ->through(fn (Seller $seller) => array_merge($this->sellerRow($seller), [
                    'shop' => $seller->shop_name,
                    'seller' => trim($seller->first_name.' '.$seller->last_name),
                    'customer' => $seller->customer?->displayName() ?: $seller->customer?->email ?: '—',
                    'href' => '/admin/multivendor/sellers/'.$seller->id,
                    'action_label' => 'Open',
                    'can_moderate' => $seller->status === Seller::STATUS_PENDING,
                ])),
        ]);
    }

    public function review(Request $request, Seller $seller): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:approve,reject,block,suspend,reactivate'],
            'note' => ['nullable', 'string', 'max:1000'],
            'commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $previousStatus = $seller->status;
        $action = $data['action'];

        $this->sellers->review(
            $seller,
            $action,
            $data['note'] ?? null,
            $request->user('admin')?->id,
            isset($data['commission_rate']) && $data['commission_rate'] !== ''
                ? (float) $data['commission_rate']
                : null,
        );

        $message = match (true) {
            in_array($action, ['block', 'suspend'], true) => 'Seller suspended.',
            $action === 'reject' => 'Seller request rejected.',
            $action === 'reactivate',
            $action === 'approve' && $previousStatus === Seller::STATUS_BLOCKED => 'Seller reactivated.',
            default => 'Seller request accepted.',
        };

        return back()->with('success', $message);
    }

    public function updateCommission(Request $request, Seller $seller): RedirectResponse
    {
        $data = $request->validate([
            'commission_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $this->sellers->setCommission(
            $seller,
            (float) $data['commission_rate'],
            $data['notes'] ?? null,
            null,
            null,
            auth('admin')->id() ? (int) auth('admin')->id() : null,
        );

        return back()->with('success', 'Commission rate saved.');
    }
}
