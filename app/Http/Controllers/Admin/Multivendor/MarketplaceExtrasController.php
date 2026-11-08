<?php

namespace App\Http\Controllers\Admin\Multivendor;

use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerEarning;
use App\Domain\Marketplace\Models\SellerMessage;
use App\Domain\Marketplace\Models\SellerOrder;
use App\Domain\Marketplace\Models\SellerReview;
use App\Http\Controllers\Admin\Multivendor\Concerns\PresentsMultivendor;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Domain\Security\CsvSafe;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MarketplaceExtrasController extends Controller
{
    use PresentsMultivendor;

    public function settings(): Response
    {
        return Inertia::render('Multivendor/Form', [
            'title' => 'Marketplace settings',
            'path' => '/admin/multivendor/settings',
            'method' => 'put',
            'action' => '/admin/multivendor/settings',
            'values' => MarketplaceSettings::all(),
            'fields' => [
                ['name' => 'default_commission', 'label' => 'Default commission (%)', 'type' => 'number', 'required' => true, 'hint' => 'Used when a new seller request is accepted.'],
                ['name' => 'auto_approve_products', 'label' => 'Approve seller products automatically', 'type' => 'checkbox'],
                [
                    'name' => 'earning_release_mode',
                    'label' => 'Earning release',
                    'type' => 'select',
                    'required' => true,
                    'hint' => 'When seller net earnings become available for payout.',
                    'options' => [
                        ['value' => 'on_payment', 'label' => 'When parent order is paid'],
                        ['value' => 'on_delivery', 'label' => 'When seller order is delivered'],
                        ['value' => 'after_return_window', 'label' => 'After return window from delivery'],
                    ],
                ],
                ['name' => 'return_window_days', 'label' => 'Return window (days)', 'type' => 'number', 'required' => true, 'hint' => 'Used when release mode is “After return window from delivery”.'],
            ],
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'default_commission' => ['required', 'numeric', 'min:0', 'max:100'],
            'auto_approve_products' => ['sometimes', 'boolean'],
            'earning_release_mode' => ['required', 'in:on_payment,on_delivery,after_return_window'],
            'return_window_days' => ['required', 'integer', 'min:0', 'max:365'],
        ]);
        MarketplaceSettings::save([
            'default_commission' => $data['default_commission'],
            'auto_approve_products' => (bool) ($data['auto_approve_products'] ?? false),
            'earning_release_mode' => $data['earning_release_mode'],
            'return_window_days' => (int) $data['return_window_days'],
        ]);

        return back()->with('success', 'Marketplace settings saved.');
    }

    public function reviews(Request $request): Response
    {
        $filters = $this->listFilters($request);
        $sellerId = $this->sellerId($request);
        $query = SellerReview::query()->with(['seller:id,shop_name', 'customer:id,name,email']);
        if ($sellerId) {
            $query->where('seller_id', $sellerId);
        }
        if (in_array($filters['status'], ['pending', 'approved', 'rejected'], true)) {
            $query->where('status', $filters['status']);
        }
        $this->applyMappedFilters($query, $filters, [
            'title' => ['title', 'body'],
            'rating_range' => 'rating',
        ]);
        if ($filters['customer_name'] !== '') {
            $needle = $filters['customer_name'];
            $query->whereHas('customer', fn ($customer) => $customer
                ->where('email', 'like', '%'.$needle.'%')
                ->orWhere('name', 'like', '%'.$needle.'%'));
        }

        $this->applyListIdRange($query, $filters);
        $this->applyListOrdering($query, $filters, ['id', 'rating', 'status', 'created_at']);

        return Inertia::render('Multivendor/List', [
            'screen' => 'reviews',
            'title' => 'Seller Reviews',
            'path' => '/admin/multivendor/reviews',
            'filters' => $filters,
            'sellers' => $this->sellerOptions(),
            'empty' => 'No seller reviews yet.',
            'tabs' => [
                ['key' => 'all', 'label' => 'All'],
                ['key' => 'pending', 'label' => 'Pending'],
                ['key' => 'approved', 'label' => 'Approved'],
                ['key' => 'rejected', 'label' => 'Rejected'],
            ],
            'columns' => [
                ['key' => 'shop_name', 'label' => 'Seller'],
                ['key' => 'customer_name', 'label' => 'Customer'],
                ['key' => 'rating', 'label' => 'Rating'],
                ['key' => 'title', 'label' => 'Review'],
                ['key' => 'status', 'label' => 'Status'],
            ],
            'rows' => $query->adminPaginate()->through(fn (SellerReview $row) => [
                'id' => $row->id,
                'shop_name' => $row->seller?->shop_name,
                'customer_name' => $row->customer?->displayName() ?: $row->customer?->email,
                'rating' => $row->rating,
                'title' => $row->title ?: mb_substr((string) $row->body, 0, 80),
                'status' => $row->status,
                'can_moderate' => $row->status === 'pending',
                'approveHref' => $row->status === 'pending' ? '/admin/multivendor/reviews/'.$row->id.'/moderate' : null,
                'rejectHref' => $row->status === 'pending' ? '/admin/multivendor/reviews/'.$row->id.'/moderate' : null,
                'deleteHref' => '/admin/multivendor/reviews/'.$row->id,
            ]),
        ]);
    }

    public function moderateReview(Request $request, SellerReview $sellerReview): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:approve,reject'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        app(\App\Domain\Marketplace\Services\SellerReviewService::class)->moderate(
            $sellerReview,
            $data['action'],
            $request->user('admin')?->id,
            $data['note'] ?? null,
        );

        return back()->with('success', 'Seller review updated.');
    }

    public function destroyReview(SellerReview $sellerReview): RedirectResponse
    {
        $sellerReview->delete();

        return back()->with('success', 'Seller review deleted.');
    }

    public function messages(Request $request): Response
    {
        $filters = $this->listFilters($request);
        $sellerId = $this->sellerId($request);
        $query = SellerMessage::query()->with('seller:id,shop_name');
        if ($sellerId) {
            $query->where('seller_id', $sellerId);
        }
        if ($filters['search'] !== '') {
            $query->where(function ($inner) use ($filters) {
                $inner->where('name', 'like', '%'.$filters['search'].'%')
                    ->orWhere('email', 'like', '%'.$filters['search'].'%')
                    ->orWhere('body', 'like', '%'.$filters['search'].'%');
            });
        }
        $this->applyMappedFilters($query, $filters, [
            'name' => 'name',
            'email' => 'email',
            'preview' => 'body',
        ]);

        $this->applyListIdRange($query, $filters);
        $this->applyListOrdering($query, $filters, ['id', 'name', 'email', 'created_at']);

        return Inertia::render('Multivendor/List', [
            'screen' => 'messages',
            'title' => 'Seller Messages',
            'path' => '/admin/multivendor/messages',
            'filters' => $filters,
            'sellers' => $this->sellerOptions(),
            'empty' => 'No seller messages yet.',
            'columns' => [
                ['key' => 'shop_name', 'label' => 'Seller'],
                ['key' => 'name', 'label' => 'From'],
                ['key' => 'email', 'label' => 'Email'],
                ['key' => 'preview', 'label' => 'Message'],
                ['key' => 'status', 'label' => 'Status'],
            ],
            'rows' => $query->adminPaginate()->through(fn (SellerMessage $row) => [
                'id' => $row->id,
                'shop_name' => $row->seller?->shop_name,
                'name' => $row->name,
                'email' => $row->email,
                'preview' => mb_substr((string) $row->body, 0, 80),
                'status' => $row->reply_body ? 'replied' : 'open',
                'href' => '/admin/multivendor/messages/'.$row->id,
                'action_label' => 'View',
            ]),
        ]);
    }

    public function showMessage(SellerMessage $sellerMessage): Response
    {
        $sellerMessage->update(['is_read' => true]);
        $sellerMessage->load('seller');

        return Inertia::render('Multivendor/MessageShow', [
            'message' => [
                'id' => $sellerMessage->id,
                'shop_name' => $sellerMessage->seller?->shop_name,
                'name' => $sellerMessage->name,
                'email' => $sellerMessage->email,
                'body' => $sellerMessage->body,
                'reply_body' => $sellerMessage->reply_body,
                'created_at' => $sellerMessage->created_at?->toDateTimeString(),
            ],
        ]);
    }

    public function replyMessage(Request $request, SellerMessage $sellerMessage): RedirectResponse
    {
        $data = $request->validate(['reply_body' => ['required', 'string', 'max:4000']]);
        $sellerMessage->update([
            'reply_body' => $data['reply_body'],
            'replied_at' => now(),
            'is_read' => true,
        ]);

        return back()->with('success', 'Reply saved.');
    }

    public function exportSellers(): StreamedResponse
    {
        $rows = Seller::query()->orderBy('shop_name')->get();

        return $this->csv('seller-profiles.csv', ['id', 'shop', 'slug', 'email', 'status', 'commission', 'city'], $rows->map(fn (Seller $seller) => [
            $seller->id, $seller->shop_name, $seller->shop_slug, $seller->email, $seller->status, $seller->commission_rate, $seller->city,
        ]));
    }

    public function exportOrders(Request $request): StreamedResponse
    {
        $sellerId = $this->sellerId($request);
        $rows = SellerOrder::query()->with('seller:id,shop_name')->when($sellerId, fn ($q) => $q->where('seller_id', $sellerId))->orderByDesc('id')->get();

        return $this->csv('seller-orders.csv', ['id', 'number', 'shop', 'email', 'status', 'total', 'earning'], $rows->map(fn (SellerOrder $row) => [
            $row->id, $row->number, $row->seller?->shop_name, $row->customer_email, $row->status, $row->grand_total, $row->earning_total,
        ]));
    }

    public function exportTransactions(Request $request): StreamedResponse
    {
        $sellerId = $this->sellerId($request);
        $rows = SellerEarning::query()->with('seller:id,shop_name')->when($sellerId, fn ($q) => $q->where('seller_id', $sellerId))->orderByDesc('id')->get();

        return $this->csv('seller-transactions.csv', ['id', 'shop', 'gross', 'commission', 'net', 'status'], $rows->map(fn (SellerEarning $row) => [
            $row->id, $row->seller?->shop_name, $row->gross_amount, $row->commission_amount, $row->net_amount, $row->status,
        ]));
    }

    /**
     * @param  list<string>  $headers
     * @param  iterable<int, array<int, mixed>>  $rows
     */
    protected function csv(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            CsvSafe::put($out, $headers);
            foreach ($rows as $row) {
                CsvSafe::put($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
