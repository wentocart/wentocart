<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerOrderItem;
use App\Domain\Order\Models\OrderItem;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/**
 */
class SellerBookingsRestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $seller = $this->owner($request);
        if ($seller instanceof JsonResponse) {
            return $seller;
        }
        if (! Schema::hasColumn('order_items', 'booking_date')) {
            return response()->json(['data' => [], 'meta' => ['total' => 0]]);
        }

        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min(50, (int) $request->query('per_page', $request->query('limit', 20))));
        $from = $request->query('from') ?? $request->query('date_from');
        $to = $request->query('to') ?? $request->query('date_to');

        $orderItemIds = SellerOrderItem::query()
            ->where('seller_id', $seller->id)
            ->pluck('order_item_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        $query = OrderItem::query()
            ->with(['order:id,number,status,email,grand_total,created_at'])
            ->whereNotNull('booking_date')
            ->whereIn('id', $orderItemIds !== [] ? $orderItemIds : [0])
            ->orderBy('booking_date')
            ->orderBy('id');

        if (is_string($from) && $from !== '') {
            $query->whereDate('booking_date', '>=', $from);
        }
        if (is_string($to) && $to !== '') {
            $query->whereDate('booking_date', '<=', $to);
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (OrderItem $item) => [
                'id' => (int) $item->id,
                'orderId' => (int) $item->order_id,
                'orderNumber' => (string) ($item->order?->number ?? ''),
                'orderStatus' => (string) ($item->order?->status ?? ''),
                'qty' => (float) $item->qty,
                'name' => (string) $item->name,
                'sku' => (string) $item->sku,
                'from' => optional($item->booking_date)?->toDateString(),
                'to' => optional($item->booking_end_date ?? $item->booking_date)?->toDateString(),
                'price' => (float) ($item->order?->grand_total ?? $item->total),
                'customerEmail' => (string) ($item->order?->email ?? ''),
                'bookingDate' => optional($item->booking_date)?->toDateString(),
            ])->values()->all(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * @return Seller|JsonResponse
     */
    private function owner(Request $request): Seller|JsonResponse
    {
        $attr = $request->attributes->get('seller');
        if ($attr instanceof Seller) {
            return $attr;
        }
        $customer = Auth::guard('customer')->user();
        if (! $customer) {
            return response()->json(['message' => 'Unauthenticated.', 'error' => 'unauthenticated'], 401);
        }
        $seller = Seller::query()->where('customer_id', $customer->id)->first();
        if (! $seller || $seller->status !== Seller::STATUS_APPROVED) {
            return response()->json(['message' => 'Seller shop not found.', 'error' => 'not_found'], 404);
        }

        return $seller;
    }
}
