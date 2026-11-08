<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\OrderDownload;
use App\Domain\Order\Services\OrderDownloadService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shop REST GET /api/v1/store/customer-downloadable-products (+ /{id}).
 */
class ShopCustomerDownloadablesController extends Controller
{
    public function index(Request $request, OrderDownloadService $downloads): JsonResponse
    {
        $customer = $request->user('customer');
        if (! $customer instanceof Customer) {
            return response()->json([
                'detail' => 'Unauthenticated. Please login to perform this action',
                'message' => 'Unauthenticated. Please login to perform this action',
            ], 403);
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min(50, (int) $request->query('per_page', 10)));
        $statusFilter = strtolower(trim((string) $request->query('status', '')));

        $q = OrderDownload::query()
            ->with(['attachment.translations', 'order', 'orderItem', 'product.translations'])
            ->whereIn('order_id', $customer->orders()->select('id'))
            ->orderByDesc('id');

        $all = $q->get()
            ->map(fn (OrderDownload $row) => $this->map($row, $downloads, $customer))
            ->values();

        if (in_array($statusFilter, ['available', 'expired', 'pending'], true)) {
            $all = $all->filter(fn (array $row) => ($row['status'] ?? '') === $statusFilter)->values();
        }

        $total = $all->count();
        $slice = $all->slice(($page - 1) * $perPage, $perPage)->values()->all();

        return response()->json($slice, 200, [
            'X-Total-Count' => (string) $total,
            'X-Page' => (string) $page,
            'X-Per-Page' => (string) $perPage,
            'X-Total-Pages' => (string) max(1, (int) ceil($total / $perPage)),
        ]);
    }

    public function show(Request $request, int|string $id, OrderDownloadService $downloads): JsonResponse
    {
        $customer = $request->user('customer');
        if (! $customer instanceof Customer) {
            return response()->json([
                'detail' => 'Unauthenticated. Please login to perform this action',
                'message' => 'Unauthenticated. Please login to perform this action',
            ], 403);
        }

        $row = OrderDownload::query()
            ->with(['attachment.translations', 'order', 'orderItem', 'product.translations'])
            ->whereKey((int) $id)
            ->whereIn('order_id', $customer->orders()->select('id'))
            ->first();
        if (! $row) {
            return response()->json([
                'detail' => 'Downloadable product not found',
                'message' => 'Downloadable product not found',
            ], 404);
        }

        return response()->json($this->map($row, $downloads, $customer));
    }

    /**
     * @return array<string, mixed>
     */
    private function map(OrderDownload $row, OrderDownloadService $downloads, Customer $customer): array
    {
        $presented = $downloads->present($row, app()->getLocale(), withContext: true);
        $available = (bool) ($presented['available'] ?? false);
        $expired = (bool) ($presented['expired'] ?? false);
        $status = $available ? 'available' : ($expired ? 'expired' : 'pending');
        $bought = (int) ($presented['max_uses'] ?? 0);
        $used = (int) ($presented['uses'] ?? 0);
        $remaining = $presented['remaining'] ?? null;
        $base = rtrim((string) request()->getSchemeAndHttpHost(), '/');

        $payload = [
            'id' => (int) $row->id,
            'productName' => $presented['product']['name'] ?? null,
            'name' => $presented['name'] ?? null,
            'fileName' => $presented['filename'] ?? ($presented['original_filename'] ?? null),
            'type' => 'file',
            'file' => $presented['filename'] ?? null,
            'downloadBought' => $bought,
            'downloadUsed' => $used,
            'downloadCanceled' => 0,
            'status' => $status,
            'remainingDownloads' => $bought <= 0 ? null : (int) $remaining,
            'order' => '/api/v1/store/customer-orders/'.(int) $row->order_id,
            'customer' => '/api/v1/store/customers/'.(int) $customer->id,
            'downloadUrl' => $base.'/api/v1/store/customer-downloadable-products/'.$row->id.'/download',
            'createdAt' => optional($row->created_at)?->toIso8601String(),
            'updatedAt' => optional($row->updated_at)?->toIso8601String(),
        ];

        return $payload;
    }
}
