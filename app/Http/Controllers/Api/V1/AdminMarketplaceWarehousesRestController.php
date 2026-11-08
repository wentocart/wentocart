<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Marketplace\Models\SellerWarehouse;
use App\Domain\Marketplace\SellerWarehouseSchema;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Marketplace → Configure → Warehouses (read-only). */
class AdminMarketplaceWarehousesRestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (! Auth::guard('admin')->user() instanceof Admin) {
            return response()->json(['message' => 'Unauthenticated.', 'error' => 'unauthenticated'], 401);
        }
        SellerWarehouseSchema::ensure();
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));
        $page = max(1, (int) $request->query('page', 1));
        $q = SellerWarehouse::query()
            ->with('seller:id,shop_name,shop_slug,first_name,last_name')
            ->orderByDesc('id');
        $sellerId = (int) ($request->query('seller_id') ?? 0);
        if ($sellerId > 0) {
            $q->where('seller_id', $sellerId);
        }
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $perPage)->get()->map(fn (SellerWarehouse $s) => [
            'id' => (int) $s->id,
            'code' => (string) $s->code,
            'name' => (string) $s->name,
            'sellerId' => (int) $s->seller_id,
            'sellerName' => trim(($s->seller?->first_name ?? '').' '.($s->seller?->last_name ?? '')),
            'shopName' => (string) ($s->seller?->shop_name ?? ''),
            'isEnabled' => (bool) $s->is_enabled,
            'priority' => (int) $s->priority,
        ])->values()->all();

        return response()->json([
            'data' => $rows,
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'readOnly' => true],
        ]);
    }
}
