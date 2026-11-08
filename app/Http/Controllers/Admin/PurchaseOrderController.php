<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Models\PurchaseOrder;
use App\Domain\Catalog\Models\PurchaseOrderItem;
use App\Domain\Catalog\Models\PurchaseOrderReceipt;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Catalog\Models\Warehouse;
use App\Domain\Catalog\Services\PurchaseOrderService;
use App\Domain\Catalog\Support\PurchaseOrderCsvSchema;
use App\Domain\Catalog\Support\PurchaseOrderDocument;
use App\Domain\Settings\DocumentPdf;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseOrderController extends Controller
{
    public function __construct(protected PurchaseOrderService $orders) {}

    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString() ?: 'all';
        $expected = $request->string('expected')->toString() ?: 'all';
        $q = trim($request->string('q')->toString());
        $number = trim($request->string('number')->toString());
        $supplier = trim($request->string('supplier')->toString());
        $warehouse = trim($request->string('warehouse')->toString());
        $expectedOn = trim($request->string('expected_on')->toString());
        $linesMin = $request->input('lines_min');
        $linesMax = $request->input('lines_max');
        $sort = $request->string('sort')->toString() ?: 'id';
        $dir = $request->string('dir')->toString() === 'asc' ? 'asc' : 'desc';
        $allowedSort = ['id', 'number', 'status', 'supplier', 'warehouse', 'expected_at', 'items_count'];
        if (! in_array($sort, $allowedSort, true)) {
            $sort = 'id';
        }

        $query = PurchaseOrder::query()
            ->with(['supplier.translations', 'warehouse', 'items'])
            ->withCount('items')
            ->when($status !== 'all' && $status !== '', fn ($builder) => $builder->where('status', $status))
            ->when($expected === 'overdue', function ($builder) {
                $builder->whereDate('expected_at', '<', now()->toDateString())
                    ->whereIn('status', [PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_ORDERED]);
            })
            ->when($expected === 'upcoming', function ($builder) {
                $builder->whereNotNull('expected_at')
                    ->whereDate('expected_at', '>=', now()->toDateString())
                    ->whereIn('status', [PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_ORDERED]);
            })
            ->when($q !== '', function ($builder) use ($q) {
                $builder->where(function ($inner) use ($q) {
                    $inner->where('number', 'like', "%{$q}%")
                        ->orWhere('notes', 'like', "%{$q}%")
                        ->orWhereHas('supplier.translations', fn ($t) => $t->where('name', 'like', "%{$q}%"));
                });
            })
            ->when($number !== '', fn ($builder) => $builder->where('number', 'like', '%'.$number.'%'))
            ->when($supplier !== '', fn ($builder) => $builder->whereHas('supplier.translations', fn ($t) => $t->where('name', 'like', '%'.$supplier.'%')))
            ->when($warehouse !== '', function ($builder) use ($warehouse) {
                $builder->whereHas('warehouse', function ($w) use ($warehouse) {
                    $w->where(function ($inner) use ($warehouse) {
                        $inner->where('name', 'like', '%'.$warehouse.'%')
                            ->orWhere('code', 'like', '%'.$warehouse.'%');
                    });
                });
            })
            ->when($expectedOn !== '', fn ($builder) => $builder->whereDate('expected_at', $expectedOn))
            ->when($linesMin !== null && $linesMin !== '', fn ($builder) => $builder->has('items', '>=', (int) $linesMin))
            ->when($linesMax !== null && $linesMax !== '', fn ($builder) => $builder->has('items', '<=', (int) $linesMax));

        match ($sort) {
            'supplier' => $query->orderBy(
                \App\Domain\Catalog\Models\SupplierTranslation::query()
                    ->select('name')
                    ->whereColumn('supplier_id', 'purchase_orders.supplier_id')
                    ->limit(1),
                $dir
            )->orderBy('id'),
            'warehouse' => $query->orderBy(
                Warehouse::query()->select('name')->whereColumn('id', 'purchase_orders.warehouse_id')->limit(1),
                $dir
            )->orderBy('id'),
            'items_count' => $query->orderBy('items_count', $dir)->orderBy('id'),
            default => $query->orderBy($sort, $dir)->orderBy('id'),
        };

        $orders = $query->adminPaginate()
            ->through(fn (PurchaseOrder $row) => $this->listRow($row));

        return Inertia::render('Catalog/PurchaseOrders/Index', [
            'orders' => $orders,
            'filters' => [
                'status' => $status,
                'expected' => $expected,
                'q' => $q,
                'number' => $number,
                'supplier' => $supplier,
                'warehouse' => $warehouse,
                'expected_on' => $expectedOn,
                'lines_min' => $linesMin !== null && $linesMin !== '' ? (string) $linesMin : '',
                'lines_max' => $linesMax !== null && $linesMax !== '' ? (string) $linesMax : '',
                'sort' => $sort,
                'dir' => $dir,
            ],
            'counts' => [
                'all' => PurchaseOrder::query()->count(),
                'draft' => PurchaseOrder::query()->where('status', PurchaseOrder::STATUS_DRAFT)->count(),
                'ordered' => PurchaseOrder::query()->where('status', PurchaseOrder::STATUS_ORDERED)->count(),
                'received' => PurchaseOrder::query()->where('status', PurchaseOrder::STATUS_RECEIVED)->count(),
                'cancelled' => PurchaseOrder::query()->where('status', PurchaseOrder::STATUS_CANCELLED)->count(),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Catalog/PurchaseOrders/Form', $this->formProps(new PurchaseOrder([
            'status' => PurchaseOrder::STATUS_DRAFT,
        ])));
    }

    public function store(Request $request): RedirectResponse
    {
        $order = $this->orders->save(null, $this->payload($request));

        return redirect()->route('admin.purchase-orders.edit', $order)->with('success', 'Purchase order saved.');
    }

    public function edit(PurchaseOrder $purchaseOrder): Response
    {
        $purchaseOrder->load([
            'items.product',
            'supplier.translations',
            'warehouse',
            'receipts.admin',
            'receipts.warehouse',
            'receipts.item',
        ]);

        return Inertia::render('Catalog/PurchaseOrders/Form', $this->formProps($purchaseOrder));
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        try {
            $this->orders->save($purchaseOrder, $this->payload($request));
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', 'Purchase order updated.');
    }

    public function destroy(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        try {
            $this->orders->delete($purchaseOrder);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()->route('admin.purchase-orders.index')->with('success', 'Purchase order deleted.');
    }

    public function markOrdered(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        try {
            $this->orders->markOrdered($purchaseOrder);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', 'Marked as ordered.');
    }

    public function receive(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $data = $request->validate([
            'items' => ['nullable', 'array'],
            'items.*.id' => ['required_with:items', 'integer'],
            'items.*.qty' => ['nullable', 'numeric', 'min:0'],
        ]);
        try {
            $this->orders->receive($purchaseOrder, $data['items'] ?? [], auth('admin')->id());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', 'Stock received into the warehouse.');
    }

    public function reverseReceipt(
        Request $request,
        PurchaseOrder $purchaseOrder,
        PurchaseOrderReceipt $receipt
    ): RedirectResponse {
        if ((int) $receipt->purchase_order_id !== (int) $purchaseOrder->id) {
            abort(404);
        }

        try {
            $this->orders->reverseReceipt($receipt, auth('admin')->id());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', 'Receipt reversed.');
    }

    public function print(PurchaseOrder $purchaseOrder, PurchaseOrderDocument $document): HttpResponse
    {
        $purchaseOrder->load(['items', 'supplier.translations', 'warehouse']);

        return response($document->html($purchaseOrder), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    public function pdf(PurchaseOrder $purchaseOrder, PurchaseOrderDocument $document, DocumentPdf $pdf): HttpResponse
    {
        $purchaseOrder->load(['items', 'supplier.translations', 'warehouse']);
        $filename = 'purchase-order-'.($purchaseOrder->number ?: $purchaseOrder->id).'.pdf';

        return response($pdf->output($document->html($purchaseOrder)), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    public function cancel(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        try {
            $this->orders->cancel($purchaseOrder);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', 'Purchase order cancelled.');
    }

    public function export()
    {
        return $this->csvDownload('purchase-orders.csv', PurchaseOrderCsvSchema::exportCsv());
    }

    public function importSample()
    {
        return $this->csvDownload('purchase-orders-sample.csv', PurchaseOrderCsvSchema::sampleCsv());
    }

    public function import(Request $request): RedirectResponse
    {
        return $this->csvImport($request, PurchaseOrderCsvSchema::import(...), 'purchase orders');
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(Request $request): array
    {
        return $request->validate([
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')],
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')],
            'expected_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'discount_type' => ['nullable', 'string', Rule::in(['percent', 'fixed', ''])],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'shipping_cost' => ['nullable', 'numeric', 'min:0'],
            'items' => ['nullable', 'array'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.sku' => ['nullable', 'string', 'max:120'],
            'items.*.product_id' => ['nullable', 'integer'],
            'items.*.qty' => ['nullable', 'numeric', 'min:0'],
            'items.*.qty_ordered' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function formProps(PurchaseOrder $order): array
    {
        $totals = $order->exists
            ? $this->orders->presentTotals($order)
            : [
                'line_subtotal' => 0,
                'discount_type' => null,
                'discount_value' => 0,
                'discount_amount' => 0,
                'shipping_cost' => 0,
                'grand_total' => 0,
                'is_overdue' => false,
                'overdue_days' => 0,
                'expected_at' => null,
            ];

        $supplierHints = [];
        $lastCosts = [];
        if ($order->relationLoaded('items') && $order->items->isNotEmpty()) {
            $productIds = $order->items->pluck('product_id')->filter()->unique()->values()->all();
            if ($productIds !== [] && $order->supplier_id) {
                $pivots = DB::table('product_supplier')
                    ->where('supplier_id', $order->supplier_id)
                    ->whereIn('product_id', $productIds)
                    ->get()
                    ->keyBy('product_id');
                foreach ($productIds as $productId) {
                    $pivot = $pivots->get($productId);
                    if ($pivot) {
                        $supplierHints[(int) $productId] = [
                            'supplier_reference' => $pivot->supplier_reference,
                            'wholesale_price' => $pivot->wholesale_price !== null ? (float) $pivot->wholesale_price : null,
                        ];
                    }
                }

                $lastItems = PurchaseOrderItem::query()
                    ->whereIn('product_id', $productIds)
                    ->where('qty_received', '>', 0)
                    ->whereHas('purchaseOrder', function ($q) use ($order) {
                        $q->where('supplier_id', $order->supplier_id);
                        if ($order->id) {
                            $q->where('id', '!=', $order->id);
                        }
                    })
                    ->orderByDesc('id')
                    ->get(['product_id', 'unit_cost']);
                foreach ($lastItems as $last) {
                    $pid = (int) $last->product_id;
                    if (! array_key_exists($pid, $lastCosts)) {
                        $lastCosts[$pid] = (float) $last->unit_cost;
                    }
                }
            }
        }

        return [
            'order' => [
                'id' => $order->id,
                'number' => $order->number,
                'status' => $order->status ?: PurchaseOrder::STATUS_DRAFT,
                'supplier_id' => $order->supplier_id,
                'warehouse_id' => $order->warehouse_id,
                'expected_at' => $order->expected_at?->toDateString(),
                'notes' => $order->notes,
                'discount_type' => $order->discount_type,
                'discount_value' => (float) ($order->discount_value ?? 0),
                'shipping_cost' => (float) ($order->shipping_cost ?? 0),
                'is_overdue' => $totals['is_overdue'],
                'overdue_days' => $totals['overdue_days'],
                'items' => $order->relationLoaded('items')
                    ? $order->items->map(function ($item) use ($supplierHints, $lastCosts) {
                        $hint = $supplierHints[(int) $item->product_id] ?? null;

                        return [
                            'id' => $item->id,
                            'sku' => $item->sku,
                            'name' => $item->name,
                            'product_id' => $item->product_id,
                            'qty_ordered' => (float) $item->qty_ordered,
                            'qty_received' => (float) $item->qty_received,
                            'unit_cost' => (float) $item->unit_cost,
                            'line_total' => $item->lineTotal(),
                            'supplier_reference' => $hint['supplier_reference'] ?? null,
                            'wholesale_price' => $hint['wholesale_price'] ?? null,
                            'last_purchase_cost' => $lastCosts[(int) $item->product_id] ?? null,
                        ];
                    })->all()
                    : [],
                'receipts' => $order->relationLoaded('receipts')
                    ? $order->receipts->sortByDesc('id')->values()->map(fn (PurchaseOrderReceipt $receipt) => [
                        'id' => $receipt->id,
                        'purchase_order_item_id' => $receipt->purchase_order_item_id,
                        'product_id' => $receipt->product_id,
                        'sku' => $receipt->item?->sku,
                        'qty' => (float) $receipt->qty,
                        'unit_cost' => (float) $receipt->unit_cost,
                        'warehouse' => $receipt->warehouse?->name,
                        'admin' => $receipt->admin?->name,
                        'inventory_reference' => $receipt->inventory_reference,
                        'is_final' => (bool) $receipt->is_final,
                        'reversed_at' => $receipt->reversed_at?->toDateTimeString(),
                        'reversal_reference' => $receipt->reversal_reference,
                        'created_at' => $receipt->created_at?->toDateTimeString(),
                    ])->all()
                    : [],
            ],
            'totals' => $totals,
            'suppliers' => Supplier::query()->with('translations')->orderBy('id')->get()->map(fn (Supplier $row) => [
                'id' => $row->id,
                'name' => $row->displayName(),
            ]),
            'warehouses' => Warehouse::query()->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'code', 'is_default']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function listRow(PurchaseOrder $row): array
    {
        return [
            'id' => $row->id,
            'number' => $row->number,
            'status' => $row->status,
            'supplier' => $row->supplier?->displayName(),
            'warehouse' => $row->warehouse?->name,
            'items_count' => (int) $row->items_count,
            'expected_at' => $row->expected_at?->toDateString(),
            'is_overdue' => $row->isOverdue(),
            'overdue_days' => $row->overdueDays(),
            'grand_total' => $row->grandTotal(),
        ];
    }
}
