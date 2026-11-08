<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Warehouse;
use App\Domain\Catalog\Services\CatalogInventoryListService;
use App\Domain\Catalog\Services\CatalogInventoryService;
use App\Domain\Catalog\Support\InventoryCsvSchema;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    public function index(Request $request): Response
    {
        $list = app(CatalogInventoryListService::class);
        $filters = $list->filtersFromRequest($request);

        return Inertia::render('Catalog/Inventory/Index', [
            'inventory' => $list->paginate($filters),
            'filters' => $filters,
            'warehouses' => Warehouse::query()
                ->where('is_enabled', true)
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'is_default'])
                ->map(fn (Warehouse $row) => [
                    'id' => $row->id,
                    'name' => $row->name,
                    'code' => $row->code,
                    'is_default' => (bool) $row->is_default,
                ])
                ->values()
                ->all(),
        ]);
    }

    public function export()
    {
        return $this->csvDownload('inventory.csv', InventoryCsvSchema::exportCsv());
    }

    public function importSample()
    {
        return $this->csvDownload('inventory-sample.csv', InventoryCsvSchema::sampleCsv());
    }

    public function import(Request $request): RedirectResponse
    {
        return $this->csvImport($request, InventoryCsvSchema::import(...), 'stock rows');
    }

    public function adjust(Request $request, Product $product): RedirectResponse
    {
        $data = $request->validate([
            'delta' => ['required', 'integer', 'not_in:0'],
            'note' => ['nullable', 'string', 'max:500'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
        ]);

        app(CatalogInventoryService::class)->adjust(
            $product,
            (int) $data['delta'],
            $data['note'] ?? null,
            isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null,
        );

        return back()->with('success', 'Stock updated.');
    }

    public function bulkAdjust(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
            'delta' => ['required', 'integer', 'not_in:0'],
            'note' => ['nullable', 'string', 'max:500'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
        ]);

        $result = app(CatalogInventoryService::class)->bulkAdjust(
            $data['ids'],
            (int) $data['delta'],
            $data['note'] ?? null,
            isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null,
        );

        return back()->with('success', $result['updated'].' product(s) updated.');
    }

    public function transfer(Request $request, Product $product): RedirectResponse
    {
        $data = $request->validate([
            'from_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'to_warehouse_id' => ['required', 'integer', 'exists:warehouses,id', 'different:from_warehouse_id'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            app(CatalogInventoryService::class)->transfer(
                $product,
                (int) $data['from_warehouse_id'],
                (int) $data['to_warehouse_id'],
                (float) $data['qty'],
                $data['note'] ?? null,
            );
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', 'Stock transferred between warehouses.');
    }

    public function journal(Request $request): Response
    {
        $sku = trim($request->string('sku')->toString());
        $name = trim($request->string('name')->toString());
        $action = trim($request->string('action')->toString());
        $reference = trim($request->string('reference')->toString());
        $warehouse = trim($request->string('warehouse')->toString());
        $created = trim($request->string('created')->toString());
        $qtyMin = $request->input('qty_min');
        $qtyMax = $request->input('qty_max');
        $sort = $request->string('sort')->toString() ?: 'id';
        $dir = $request->string('dir')->toString() === 'asc' ? 'asc' : 'desc';
        $allowedSort = ['id', 'sku', 'name', 'action', 'qty', 'reference', 'warehouse', 'created_at'];
        if (! in_array($sort, $allowedSort, true)) {
            $sort = 'id';
        }

        $query = \App\Domain\Catalog\Models\InventoryMovement::query()
            ->with(['product.translations', 'warehouse:id,name,code'])
            ->when($sku !== '', fn ($q) => $q->whereHas('product', fn ($p) => $p->where('sku', 'like', '%'.$sku.'%')))
            ->when($name !== '', fn ($q) => $q->whereHas('product.translations', fn ($t) => $t->where('name', 'like', '%'.$name.'%')))
            ->when($action !== '', fn ($q) => $q->where('action', $action))
            ->when($reference !== '', fn ($q) => $q->where('reference', 'like', '%'.$reference.'%'))
            ->when($warehouse !== '', function ($q) use ($warehouse) {
                $q->whereHas('warehouse', function ($w) use ($warehouse) {
                    $w->where(function ($inner) use ($warehouse) {
                        $inner->where('code', 'like', '%'.$warehouse.'%')
                            ->orWhere('name', 'like', '%'.$warehouse.'%');
                    });
                });
            })
            ->when($created !== '', fn ($q) => $q->whereDate('created_at', $created))
            ->when($qtyMin !== null && $qtyMin !== '', fn ($q) => $q->where('qty', '>=', (float) $qtyMin))
            ->when($qtyMax !== null && $qtyMax !== '', fn ($q) => $q->where('qty', '<=', (float) $qtyMax));

        match ($sort) {
            'sku' => $query->orderBy(
                \App\Domain\Catalog\Models\Product::query()->select('sku')->whereColumn('id', 'inventory_movements.product_id')->limit(1),
                $dir
            )->orderBy('id'),
            'name' => $query->orderBy(
                \App\Domain\Catalog\Models\ProductTranslation::query()
                    ->select('name')
                    ->whereColumn('product_id', 'inventory_movements.product_id')
                    ->limit(1),
                $dir
            )->orderBy('id'),
            'warehouse' => $query->orderBy(
                \App\Domain\Catalog\Models\Warehouse::query()
                    ->select('code')
                    ->whereColumn('id', 'inventory_movements.warehouse_id')
                    ->limit(1),
                $dir
            )->orderBy('id'),
            default => $query->orderBy($sort === 'created_at' ? 'created_at' : $sort, $dir)->orderBy('id'),
        };

        $movements = $query
            ->adminPaginate()
            ->through(fn ($row) => [
                'id' => $row->id,
                'product_id' => $row->product_id,
                'sku' => $row->product?->sku,
                'name' => $row->product?->translation()?->name,
                'action' => $row->action,
                'qty' => (float) $row->qty,
                'reference' => $row->reference,
                'warehouse_id' => $row->warehouse_id,
                'warehouse_code' => $row->warehouse?->code,
                'warehouse_name' => $row->warehouse?->name,
                'created_at' => optional($row->created_at)->toIso8601String(),
            ]);

        return Inertia::render('Catalog/Inventory/Journal', [
            'movements' => $movements,
            'filters' => [
                'sku' => $sku,
                'name' => $name,
                'action' => $action,
                'reference' => $reference,
                'warehouse' => $warehouse,
                'created' => $created,
                'qty_min' => $qtyMin !== null && $qtyMin !== '' ? (string) $qtyMin : '',
                'qty_max' => $qtyMax !== null && $qtyMax !== '' ? (string) $qtyMax : '',
                'sort' => $sort,
                'dir' => $dir,
            ],
        ]);
    }
}
