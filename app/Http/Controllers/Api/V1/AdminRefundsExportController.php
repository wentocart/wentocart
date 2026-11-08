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
class AdminRefundsExportController extends Controller
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

        $q = Order::query()
            ->where(function ($inner) {
                $inner->where('refunded_total', '>', 0)
                    ->orWhereIn('payment_status', ['refunded', 'partially_refunded']);
            })
            ->orderByDesc('id');
        $orderNumber = trim((string) $request->query('orderNumber', $request->query('order_increment_id', '')));
        if ($orderNumber !== '') {
            $q->where('number', 'like', '%'.$orderNumber.'%');
        }

        $rows = $q->limit(5000)->get();
        $filename = 'refunds-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, [
                'order_id', 'order_number', 'payment_status', 'grand_total', 'refunded_total',
                'currency_code', 'customer_email', 'created_at',
            ]);
            foreach ($rows as $o) {
                fputcsv($out, [
                    $o->id,
                    $o->number,
                    (string) $o->payment_status,
                    $o->grand_total,
                    $o->refunded_total ?? 0,
                    (string) $o->currency_code,
                    (string) ($o->email ?? ''),
                    optional($o->created_at)?->toIso8601String(),
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
