<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Models\CustomerStockAlert;
use App\Domain\Catalog\Services\CustomerStockAlertService;
use App\Domain\Settings\ProductSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StockAlertController extends Controller
{
    public function index(Request $request, CustomerStockAlertService $alerts): Response
    {
        $enabled = ProductSettings::outOfStockNotification();
        $paginator = $enabled
            ? $alerts->listForAdmin($request)
            : CustomerStockAlert::query()->whereRaw('0 = 1')->paginate(20);

        $locale = app()->getLocale();
        $rows = collect($paginator->items())->map(fn (CustomerStockAlert $row) => $alerts->presentRow($row, $locale))->values();

        return Inertia::render('Catalog/StockAlerts', [
            'enabled' => $enabled,
            'alerts' => [
                'data' => $rows,
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'links' => $paginator->linkCollection()->toArray(),
            ],
            'filters' => [
                'product' => (string) $request->query('product', ''),
                'customer' => (string) $request->query('customer', ''),
                'status' => (string) $request->query('status', 'all'),
                'sort' => (string) $request->query('sort', 'id'),
                'dir' => (string) $request->query('dir', 'desc'),
            ],
        ]);
    }

    public function destroy(int $alert): RedirectResponse
    {
        CustomerStockAlert::query()->whereKey($alert)->delete();

        return back()->with('success', 'Stock alert removed.');
    }

    public function resend(int $alert, CustomerStockAlertService $alerts): RedirectResponse
    {
        if (! ProductSettings::outOfStockNotification()) {
            return back()->with('error', 'Out of stock notifications are disabled.');
        }

        $row = CustomerStockAlert::query()->with('product')->findOrFail($alert);
        $variantId = (int) $row->product_variant_id;
        if (! $row->product || ! $alerts->targetIsInStock($row->product, $variantId > 0 ? $variantId : null)) {
            return back()->with('error', 'Cannot resend: this product is not available.');
        }

        if ($alerts->resend((int) $row->id)) {
            return back()->with('success', 'Stock alert email resent.');
        }

        return back()->with('error', 'Could not resend the stock alert email.');
    }

    public function bulk(Request $request, CustomerStockAlertService $alerts): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:delete,resend'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'min:1'],
        ]);

        if ($data['action'] === 'delete') {
            $deleted = $alerts->deleteMany($data['ids']);

            return back()->with('success', $deleted === 1
                ? '1 stock alert removed.'
                : $deleted.' stock alerts removed.');
        }

        if (! ProductSettings::outOfStockNotification()) {
            return back()->with('error', 'Out of stock notifications are disabled.');
        }

        $result = $alerts->resendMany($data['ids']);
        $parts = [];
        if ($result['sent'] > 0) {
            $parts[] = $result['sent'] === 1
                ? '1 email resent'
                : $result['sent'].' emails resent';
        }
        if ($result['skipped'] > 0) {
            $parts[] = $result['skipped'] === 1
                ? '1 skipped (unavailable)'
                : $result['skipped'].' skipped (unavailable)';
        }
        if ($result['failed'] > 0) {
            $parts[] = $result['failed'] === 1
                ? '1 failed'
                : $result['failed'].' failed';
        }

        if ($result['sent'] === 0 && $result['failed'] === 0) {
            return back()->with('error', $parts !== []
                ? implode('. ', $parts).'.'
                : 'No emails were resent.');
        }

        $message = $parts !== [] ? implode('. ', $parts).'.' : 'Stock alert emails processed.';
        if ($result['failed'] > 0 || $result['skipped'] > 0) {
            return back()->with('warning', $message);
        }

        return back()->with('success', $message);
    }
}
