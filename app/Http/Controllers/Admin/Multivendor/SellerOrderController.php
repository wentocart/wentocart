<?php

namespace App\Http\Controllers\Admin\Multivendor;

use App\Domain\Marketplace\Models\SellerOrder;
use App\Domain\Marketplace\Services\SellerService;
use App\Http\Controllers\Admin\Multivendor\Concerns\PresentsMultivendor;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SellerOrderController extends Controller
{
    use PresentsMultivendor;

    public function __construct(protected SellerService $sellers) {}

    public function index(Request $request): Response
    {
        $filters = $this->listFilters($request);
        $sellerId = $this->sellerId($request);
        $query = SellerOrder::query()->with('seller:id,shop_name,shop_slug');
        if ($sellerId) {
            $query->where('seller_id', $sellerId);
        }
        if (in_array($filters['status'], ['pending', 'processing', 'shipped', 'completed', 'cancelled'], true)) {
            $query->where('status', $filters['status']);
        }
        if ($filters['search'] !== '') {
            $needle = $filters['search'];
            $query->where(function ($inner) use ($needle) {
                $inner->where('number', 'like', '%'.$needle.'%')
                    ->orWhere('customer_email', 'like', '%'.$needle.'%');
            });
        }
        $this->applyMappedFilters($query, $filters, [
            'number' => 'number',
            'customer_email' => 'customer_email',
            'grand_total_range' => 'grand_total',
            'earning_total_range' => 'earning_total',
        ]);

        $this->applyListIdRange($query, $filters);
        $this->applyListOrdering($query, $filters, ['id', 'number', 'status', 'created_at', 'customer_email', 'grand_total', 'earning_total']);

        return Inertia::render('Multivendor/List', [
            'screen' => 'orders',
            'title' => 'Seller Orders',
            'path' => '/admin/multivendor/orders',
            'filters' => $filters,
            'sellers' => $this->sellerOptions(),
            'exportHref' => '/admin/multivendor/orders/export',
            'empty' => 'No seller orders yet.',
            'tabs' => [
                ['key' => 'all', 'label' => 'All'],
                ['key' => 'pending', 'label' => 'Pending'],
                ['key' => 'processing', 'label' => 'Processing'],
                ['key' => 'shipped', 'label' => 'Shipped'],
                ['key' => 'completed', 'label' => 'Completed'],
                ['key' => 'cancelled', 'label' => 'Cancelled'],
            ],
            'columns' => [
                ['key' => 'number', 'label' => 'Order'],
                ['key' => 'shop_name', 'label' => 'Seller'],
                ['key' => 'customer_email', 'label' => 'Customer'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'grand_total', 'label' => 'Total'],
                ['key' => 'earning_total', 'label' => 'Seller earning'],
            ],
            'rows' => $query
                ->adminPaginate()
                ->through(fn (SellerOrder $row) => [
                    'id' => $row->id,
                    'number' => $row->number,
                    'shop_name' => $row->seller?->shop_name,
                    'customer_email' => $row->customer_email ?: '—',
                    'status' => $row->status,
                    'currency_code' => $row->currency_code,
                    'grand_total' => (float) $row->grand_total,
                    'earning_total' => (float) $row->earning_total,
                    'href' => $row->order_id ? '/admin/orders/'.$row->order_id.'/edit' : '/admin/multivendor/orders/'.$row->id,
                    'action_label' => 'Edit',
                ]),
        ]);
    }

    public function show(SellerOrder $sellerOrder): Response
    {
        $sellerOrder->load(['seller', 'items', 'shipments.items', 'order:id,number,shipping_address,status']);

        return Inertia::render('Multivendor/OrderShow', [
            'order' => [
                'id' => $sellerOrder->id,
                'number' => $sellerOrder->number,
                'status' => $sellerOrder->status,
                'payment_status' => $sellerOrder->payment_status,
                'customer_email' => $sellerOrder->customer_email,
                'currency_code' => $sellerOrder->currency_code,
                'subtotal' => (float) $sellerOrder->subtotal,
                'discount_total' => (float) $sellerOrder->discount_total,
                'tax_total' => (float) $sellerOrder->tax_total,
                'shipping_total' => (float) $sellerOrder->shipping_total,
                'commission_rate' => $sellerOrder->commission_rate !== null ? (float) $sellerOrder->commission_rate : null,
                'commission_source' => $sellerOrder->commission_source,
                'commission_type' => $sellerOrder->commission_type,
                'commission_value' => $sellerOrder->commission_value !== null ? (float) $sellerOrder->commission_value : null,
                'commission_rule_id' => $sellerOrder->commission_rule_id,
                'commission_total' => (float) $sellerOrder->commission_total,
                'earning_total' => (float) $sellerOrder->earning_total,
                'grand_total' => (float) $sellerOrder->grand_total,
                'commissionable_gross' => $sellerOrder->commissionableGross(),
                'shop_name' => $sellerOrder->seller?->shop_name,
                'created_at' => $sellerOrder->created_at?->toDateTimeString(),
                'shipping_address' => $sellerOrder->order?->shipping_address,
                'parent_status' => $sellerOrder->order?->status,
                'items' => $sellerOrder->items->map(fn ($item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'sku' => $item->sku,
                    'qty' => $item->qty,
                    'unit_price' => (float) $item->unit_price,
                    'total' => (float) $item->total,
                ])->all(),
                'shipments' => $sellerOrder->shipments->map(fn ($shipment) => [
                    'id' => $shipment->id,
                    'status' => $shipment->status,
                    'shipping_cost' => (float) ($shipment->shipping_cost ?? 0),
                    'carrier' => $shipment->carrier,
                    'tracking_number' => $shipment->tracking_number,
                    'tracking_url' => $shipment->tracking_url,
                    'shipped_at' => $shipment->shipped_at?->toDateTimeString(),
                    'delivered_at' => $shipment->delivered_at?->toDateTimeString(),
                    'item_count' => $shipment->items->count(),
                ])->all(),
            ],
        ]);
    }

    public function updateStatus(Request $request, SellerOrder $sellerOrder): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:pending,processing,cancelled'],
        ]);
        $this->sellers->updateSellerOrderStatus($sellerOrder->seller, $sellerOrder, $data['status']);

        return back()->with('success', 'Seller order status saved.');
    }
}
