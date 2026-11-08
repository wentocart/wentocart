<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\GraphqlAdminAuth;
use App\Domain\Order\Models\Order;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 */
class AdminOrdersExportController extends Controller
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

        $q = Order::query()->with(['customer:id,name,email', 'paymentMethod:id,name'])->orderByDesc('id');

        $status = trim((string) $request->query('status', ''));
        if ($status !== '') {
            $q->where('status', $status);
        }
        $orderNumber = trim((string) $request->query('orderNumber', $request->query('order_id', $request->query('order_increment_id', ''))));
        if ($orderNumber !== '') {
            $q->where('number', 'like', '%'.$orderNumber.'%');
        }
        $email = trim((string) $request->query('email', ''));
        if ($email !== '') {
            $q->where('email', 'like', '%'.$email.'%');
        }
        $dateFrom = trim((string) $request->query('date_from', ''));
        if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $q->whereDate('created_at', '>=', $dateFrom);
        }
        $dateTo = trim((string) $request->query('date_to', ''));
        if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $q->whereDate('created_at', '<=', $dateTo);
        }

        $rows = $q->limit(10000)->get();
        $filename = 'orders-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, [
                'id', 'number', 'email', 'customer', 'status', 'payment_status',
                'payment_method', 'grand_total', 'currency', 'created_at',
            ]);
            foreach ($rows as $order) {
                fputcsv($out, [
                    $order->id,
                    $order->number,
                    (string) ($order->email ?? ''),
                    (string) ($order->customer?->name ?? ''),
                    (string) $order->status,
                    (string) $order->payment_status,
                    (string) ($order->paymentMethod?->name ?? ''),
                    $order->grand_total,
                    (string) $order->currency_code,
                    optional($order->created_at)?->toIso8601String(),
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
