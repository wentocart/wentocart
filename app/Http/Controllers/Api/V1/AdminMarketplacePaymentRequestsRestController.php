<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Marketplace\Models\SellerOrder;
use App\Domain\Marketplace\SellerPaymentRequestSchema;
use App\Domain\Marketplace\Services\SellerPaymentRequestService;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 */
class AdminMarketplacePaymentRequestsRestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        SellerPaymentRequestSchema::ensure();
        $status = (string) ($request->query('status') ?? SellerPaymentRequestService::STATUS_REQUESTED);
        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min(50, (int) $request->query('per_page', 20)));

        $query = SellerOrder::query()
            ->with(['seller:id,shop_name,shop_slug'])
            ->whereNotNull('seller_payout_status')
            ->orderByDesc('seller_payout_requested_at')
            ->orderByDesc('id');
        if ($status !== '' && $status !== 'all') {
            $query->where('seller_payout_status', $status);
        }
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);
        $unpaid = SellerOrder::query()
            ->where('seller_payout_status', SellerPaymentRequestService::STATUS_REQUESTED)
            ->sum('earning_total');

        return response()->json([
            'data' => collect($paginator->items())->map(fn (SellerOrder $o) => $this->row($o))->values()->all(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'payableOrders' => SellerOrder::query()
                    ->where('seller_payout_status', SellerPaymentRequestService::STATUS_REQUESTED)
                    ->count(),
                'unpaidPayoutAmount' => round((float) $unpaid, 2),
            ],
        ]);
    }

    /** Marketplace → Sales → Transactions (paid payment requests). */
    public function transactions(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        SellerPaymentRequestSchema::ensure();
        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min(50, (int) $request->query('per_page', 20)));
        $from = $request->query('from');
        $to = $request->query('to');

        $query = SellerOrder::query()
            ->with(['seller:id,shop_name,shop_slug'])
            ->where('seller_payout_status', SellerPaymentRequestService::STATUS_PAID)
            ->orderByDesc('seller_payout_paid_at')
            ->orderByDesc('id');
        if (is_string($from) && $from !== '') {
            $query->whereDate('seller_payout_paid_at', '>=', $from);
        }
        if (is_string($to) && $to !== '') {
            $query->whereDate('seller_payout_paid_at', '<=', $to);
        }
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (SellerOrder $o) => [
                'id' => (int) $o->id,
                'transactionId' => (string) $o->number,
                'sellerId' => (int) $o->seller_id,
                'sellerName' => (string) ($o->seller?->shop_name ?? ''),
                'comment' => (string) ($o->seller_payout_comment ?? 'Manual Payment'),
                'total' => (float) $o->earning_total,
                'commission' => (float) $o->commission_total,
                'paidAt' => optional($o->seller_payout_paid_at)?->toIso8601String(),
            ])->values()->all(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function pay(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        SellerPaymentRequestSchema::ensure();
        $order = SellerOrder::query()->find($id);
        if (! $order) {
            return response()->json(['message' => 'Payment request not found.', 'error' => 'not_found'], 404);
        }
        $data = $request->validate([
            'comment' => ['nullable', 'string', 'max:500'],
        ]);
        $paid = app(SellerPaymentRequestService::class)->pay($order, $data['comment'] ?? null);

        return response()->json([
            'message' => 'Seller is Paid successfully!',
            'data' => $this->row($paid),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(SellerOrder $o): array
    {
        return [
            'id' => (int) $o->id,
            'number' => (string) $o->number,
            'sellerId' => (int) $o->seller_id,
            'shopName' => (string) ($o->seller?->shop_name ?? ''),
            'earningTotal' => (float) $o->earning_total,
            'commissionTotal' => (float) $o->commission_total,
            'status' => (string) ($o->seller_payout_status ?? ''),
            'requestedAt' => optional($o->seller_payout_requested_at)?->toIso8601String(),
            'paidAt' => optional($o->seller_payout_paid_at)?->toIso8601String(),
            'comment' => (string) ($o->seller_payout_comment ?? ''),
        ];
    }

    private function guard(): ?JsonResponse
    {
        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin) {
            return response()->json(['message' => 'Unauthenticated.', 'error' => 'unauthenticated'], 401);
        }

        return null;
    }
}
