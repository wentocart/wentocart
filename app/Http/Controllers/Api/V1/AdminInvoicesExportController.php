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
class AdminInvoicesExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse|Response
    {
        GraphqlAdminAuth::bind($request);
        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin) {
            return response('Unauthenticated.', 401);
        }
        if (! $admin->hasPermission('orders.documents') && ! $admin->hasPermission('orders.manage')) {
            return response('Forbidden.', 403);
        }

        $stateFilter = strtolower(trim((string) $request->query('state', '')));
        $orderNumber = trim((string) $request->query('orderNumber', $request->query('order_increment_id', '')));

        $q = Order::query()->whereNotNull('fulfillment_details')->orderByDesc('id');
        if ($orderNumber !== '') {
            $q->where('number', 'like', '%'.$orderNumber.'%');
        }

        $rows = $q->limit(5000)->get()->filter(function (Order $o) use ($stateFilter) {
            $details = is_array($o->fulfillment_details) ? $o->fulfillment_details : [];
            if (empty($details['invoice_number'])) {
                return false;
            }
            $state = (string) ($details['invoice_state'] ?? '');
            if ($state === '') {
                $state = in_array((string) $o->payment_status, ['paid', 'partially_refunded', 'partial'], true)
                    ? 'paid'
                    : 'pending';
            }
            if ($stateFilter !== '' && $state !== $stateFilter) {
                return false;
            }

            return true;
        })->values();

        $filename = 'invoices-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, [
                'id', 'order_number', 'invoice_number', 'state', 'payment_status',
                'grand_total', 'currency_code', 'customer_email', 'created_at',
            ]);
            foreach ($rows as $o) {
                $details = is_array($o->fulfillment_details) ? $o->fulfillment_details : [];
                $state = (string) ($details['invoice_state'] ?? '');
                if ($state === '') {
                    $state = in_array((string) $o->payment_status, ['paid', 'partially_refunded', 'partial'], true)
                        ? 'paid'
                        : 'pending';
                }
                fputcsv($out, [
                    $o->id,
                    $o->number,
                    (string) ($details['invoice_number'] ?? ''),
                    $state,
                    (string) $o->payment_status,
                    $o->grand_total,
                    (string) $o->currency_code,
                    (string) ($o->email ?? ''),
                    optional($o->created_at)?->toIso8601String(),
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
