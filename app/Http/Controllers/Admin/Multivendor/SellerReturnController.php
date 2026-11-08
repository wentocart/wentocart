<?php

namespace App\Http\Controllers\Admin\Multivendor;

use App\Domain\Marketplace\Models\SellerReturn;
use App\Domain\Marketplace\Services\SellerReturnService;
use App\Http\Controllers\Admin\Multivendor\Concerns\PresentsMultivendor;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SellerReturnController extends Controller
{
    use PresentsMultivendor;

    public function __construct(protected SellerReturnService $returns) {}

    public function index(Request $request): Response
    {
        $filters = $this->listFilters($request);
        $sellerId = $this->sellerId($request);
        $query = SellerReturn::query()->with(['seller:id,shop_name', 'order:id,number']);

        if ($sellerId) {
            $query->where('seller_id', $sellerId);
        }
        if (in_array($filters['status'], SellerReturn::STATUSES, true)) {
            $query->where('status', $filters['status']);
        }
        if ($filters['search'] !== '') {
            $needle = $filters['search'];
            $query->where(function ($inner) use ($needle) {
                $inner->where('number', 'like', '%'.$needle.'%')
                    ->orWhereHas('order', fn ($q) => $q->where('number', 'like', '%'.$needle.'%'));
            });
        }
        $this->applyMappedFilters($query, $filters, [
            'number' => 'number',
            'refund_total_range' => 'refund_total',
        ]);
        if ($filters['order_number'] !== '') {
            $needle = $filters['order_number'];
            $query->whereHas('order', fn ($inner) => $inner->where('number', 'like', '%'.$needle.'%'));
        }

        $this->applyListIdRange($query, $filters);
        $this->applyListOrdering($query, $filters, ['id', 'number', 'status', 'created_at', 'refund_total']);

        return Inertia::render('Multivendor/List', [
            'screen' => 'returns',
            'title' => 'Seller Returns',
            'path' => '/admin/multivendor/returns',
            'filters' => $filters,
            'sellers' => $this->sellerOptions(),
            'empty' => 'No seller returns yet.',
            'tabs' => [
                ['key' => 'all', 'label' => 'All'],
                ['key' => 'requested', 'label' => 'Requested'],
                ['key' => 'approved', 'label' => 'Approved'],
                ['key' => 'received', 'label' => 'Received'],
                ['key' => 'refunded', 'label' => 'Refunded'],
                ['key' => 'rejected', 'label' => 'Rejected'],
            ],
            'columns' => [
                ['key' => 'number', 'label' => 'Return'],
                ['key' => 'order_number', 'label' => 'Order'],
                ['key' => 'shop_name', 'label' => 'Seller'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'refund_total', 'label' => 'Refund'],
            ],
            'rows' => $query
                ->adminPaginate()
                ->through(fn (SellerReturn $row) => [
                    'id' => $row->id,
                    'number' => $row->number,
                    'order_number' => $row->order?->number ?: '—',
                    'shop_name' => $row->seller?->shop_name,
                    'status' => $row->status,
                    'refund_total' => (float) $row->refund_total,
                    'href' => '/admin/multivendor/returns/'.$row->id,
                    'action_label' => 'View',
                ]),
        ]);
    }

    public function show(SellerReturn $sellerReturn): Response
    {
        return Inertia::render('Multivendor/ReturnShow', [
            'returnRow' => $this->returns->present($sellerReturn->load(['items', 'seller', 'order', 'sellerOrder'])),
            'order_number' => $sellerReturn->order?->number,
            'seller_order_id' => $sellerReturn->seller_order_id,
        ]);
    }

    public function approve(Request $request, SellerReturn $sellerReturn): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
        $this->returns->approve($sellerReturn, null, $request->user('admin')?->id, $data['note'] ?? null);

        return back()->with('success', 'Return approved.');
    }

    public function reject(Request $request, SellerReturn $sellerReturn): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
        $this->returns->reject($sellerReturn, null, $request->user('admin')?->id, $data['note'] ?? null);

        return back()->with('success', 'Return rejected.');
    }

    public function receive(SellerReturn $sellerReturn): RedirectResponse
    {
        $this->returns->markReceived($sellerReturn, null, request()->user('admin')?->id);

        return back()->with('success', 'Return marked received.');
    }

    public function refund(Request $request, SellerReturn $sellerReturn): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
        $this->returns->refund($sellerReturn, null, $request->user('admin')?->id, $data['note'] ?? null);

        return back()->with('success', 'Return refunded.');
    }
}
