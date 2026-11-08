<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\GraphqlAdminAuth;
use App\Domain\Order\Models\Order;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * → /api/v1/console/transactions/export
 */
class AdminTransactionsExportController extends Controller
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

        $orderNumber = trim((string) $request->query('orderNumber', $request->query('order_increment_id', '')));
        $statusFilter = trim((string) $request->query('status', ''));
        $dateFrom = trim((string) $request->query('created_at_from', $request->query('date_from', '')));
        $dateTo = trim((string) $request->query('created_at_to', $request->query('date_to', '')));

        $orders = Order::query()
            ->when($orderNumber !== '', fn ($q) => $q->where('number', 'like', '%'.$orderNumber.'%'))
            ->whereNotNull('payment_details')
            ->orderByDesc('id')
            ->limit(2000)
            ->get(['id', 'number', 'payment_details', 'fulfillment_details', 'created_at']);

        $rows = [];
        foreach ($orders as $order) {
            $details = is_array($order->payment_details) ? $order->payment_details : [];
            $fulfillment = is_array($order->fulfillment_details) ? $order->fulfillment_details : [];
            $invoice = isset($fulfillment['invoice_number']) ? (string) $fulfillment['invoice_number'] : '';
            $txs = is_array($details['transactions'] ?? null) ? $details['transactions'] : [];
            if ($txs === [] && ! empty($details)) {
                $txs = [[
                    'transaction_id' => (string) ($details['transaction_id'] ?? ('TX-'.$order->id)),
                    'amount' => (float) ($details['amount'] ?? $order->grand_total ?? 0),
                    'status' => (string) ($details['status'] ?? $order->payment_status ?? 'paid'),
                    'payment_method' => (string) ($details['payment_method'] ?? ''),
                    'created_at' => optional($order->created_at)?->toIso8601String(),
                ]];
            }
            foreach ($txs as $tx) {
                if (! is_array($tx)) {
                    continue;
                }
                $status = (string) ($tx['status'] ?? 'paid');
                if ($statusFilter !== '' && strcasecmp($status, $statusFilter) !== 0) {
                    continue;
                }
                $created = (string) ($tx['created_at'] ?? optional($order->created_at)?->toIso8601String() ?? '');
                if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $dateFrom) && $created !== '') {
                    if (strncmp($created, substr($dateFrom, 0, 10), 10) < 0) {
                        continue;
                    }
                }
                if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $dateTo) && $created !== '') {
                    if (strncmp($created, substr($dateTo, 0, 10), 10) > 0) {
                        continue;
                    }
                }
                $rows[] = [
                    'id' => (string) ($tx['id'] ?? Str::uuid()),
                    'transaction_id' => (string) ($tx['transaction_id'] ?? ('TX-'.$order->id)),
                    'order_number' => (string) $order->number,
                    'invoice_number' => (string) ($tx['invoice_number'] ?? $invoice),
                    'amount' => (float) ($tx['amount'] ?? 0),
                    'status' => $status,
                    'payment_method' => (string) ($tx['payment_method'] ?? ''),
                    'created_at' => $created,
                ];
            }
        }

        $filename = 'transactions-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, [
                'id', 'transaction_id', 'order_number', 'invoice_number',
                'amount', 'status', 'payment_method', 'created_at',
            ]);
            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['id'],
                    $row['transaction_id'],
                    $row['order_number'],
                    $row['invoice_number'],
                    $row['amount'],
                    $row['status'],
                    $row['payment_method'],
                    $row['created_at'],
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
