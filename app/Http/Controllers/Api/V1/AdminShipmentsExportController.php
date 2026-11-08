<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\GraphqlAdminAuth;
use App\Domain\Order\Models\Order;
use App\Domain\Shipping\Models\Shipment;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * REST CSV export for admin shipments datagrid.
 */
class AdminShipmentsExportController extends Controller
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

        $q = Shipment::query()->with('order')->orderByDesc('id');
        $orderNumber = trim((string) $request->query('orderNumber', $request->query('order_increment_id', '')));
        if ($orderNumber !== '') {
            $orderId = Order::query()->where('number', 'like', '%'.$orderNumber.'%')->value('id');
            if (! $orderId) {
                $q->whereRaw('0 = 1');
            } else {
                $q->where('order_id', $orderId);
            }
        }

        $rows = $q->limit(5000)->get();
        $filename = 'shipments-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, [
                'id', 'order_id', 'order_number', 'status', 'carrier', 'tracking_number', 'created_at',
            ]);
            foreach ($rows as $s) {
                fputcsv($out, [
                    $s->id,
                    $s->order_id,
                    (string) ($s->order?->number ?? ''),
                    (string) $s->status,
                    (string) ($s->carrier ?? ''),
                    (string) ($s->tracking_number ?? ''),
                    optional($s->created_at)?->toIso8601String(),
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
