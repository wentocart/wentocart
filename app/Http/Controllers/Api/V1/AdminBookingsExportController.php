<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\GraphqlAdminAuth;
use App\Domain\Order\Models\OrderItem;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 */
class AdminBookingsExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse|Response
    {
        GraphqlAdminAuth::bind($request);
        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin) {
            return response('Unauthenticated.', 401);
        }
        if (! $admin->hasPermission('orders.manage') && ! $admin->hasPermission('orders.documents')) {
            return response('Forbidden.', 403);
        }

        if (! Schema::hasColumn('order_items', 'booking_date')) {
            return response()->streamDownload(function () {
                $out = fopen('php://output', 'w');
                if ($out !== false) {
                    fputcsv($out, ['id', 'order_id', 'order_number', 'product_id', 'sku', 'name', 'qty', 'booking_date', 'booking_end_date', 'created_at']);
                    fclose($out);
                }
            }, 'bookings-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $q = OrderItem::query()
            ->with('order:id,number')
            ->whereNotNull('booking_date')
            ->orderByDesc('id');

        $orderId = (int) $request->query('order_id', $request->query('orderId', 0));
        if ($orderId > 0) {
            $q->where('order_id', $orderId);
        }
        $productId = (int) $request->query('product_id', $request->query('productId', 0));
        if ($productId > 0) {
            $q->where('product_id', $productId);
        }
        $fromFrom = trim((string) $request->query('from_from', ''));
        if ($fromFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromFrom)) {
            $q->whereDate('booking_date', '>=', $fromFrom);
        }
        $fromTo = trim((string) $request->query('from_to', ''));
        if ($fromTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromTo)) {
            $q->whereDate('booking_date', '<=', $fromTo);
        }

        $rows = $q->limit(5000)->get();
        $filename = 'bookings-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, [
                'id', 'order_id', 'order_number', 'product_id', 'sku', 'name',
                'qty', 'booking_date', 'booking_end_date', 'created_at',
            ]);
            foreach ($rows as $item) {
                fputcsv($out, [
                    $item->id,
                    $item->order_id,
                    (string) ($item->order?->number ?? ''),
                    $item->product_id,
                    (string) $item->sku,
                    (string) $item->name,
                    $item->qty,
                    optional($item->booking_date)?->toDateString(),
                    optional($item->booking_end_date)?->toDateString(),
                    optional($item->created_at)?->toIso8601String(),
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
