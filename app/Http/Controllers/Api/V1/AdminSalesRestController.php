<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\GraphqlAdminOrderComposerDraft;
use App\Domain\Localization\Money;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderComment;
use App\Domain\Order\OrderStageCatalog;
use App\Domain\Order\OrderStageService;
use App\Domain\Order\Services\PaymentRefundService;
use App\Domain\Settings\DocumentCommerceSettings;
use App\Domain\Shipping\Models\Shipment;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use GraphQL\Error\UserError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Admin REST REST sales tree: /api/v1/console/{orders|re-orders|shipments|invoices|refunds|transactions}
 */
class AdminSalesRestController extends Controller
{
    public function ordersIndex(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', $request->query('limit', 20))));
        $page = max(1, (int) $request->query('page', 1));
        $q = Order::query()->orderByDesc('id');
        $status = trim((string) ($request->query('status') ?? ''));
        if ($status !== '') {
            $q->where('status', $status);
        }
        $term = trim((string) ($request->query('q') ?? $request->query('search') ?? ''));
        if ($term !== '') {
            $like = '%'.$term.'%';
            $q->where(function ($inner) use ($like) {
                $inner->where('number', 'like', $like)
                    ->orWhere('email', 'like', $like);
            });
        }
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $perPage)->get()
            ->map(fn (Order $o) => $this->orderRow($o, false))
            ->values()
            ->all();

        return response()->json(['data' => $rows])->withHeaders([
            'X-Total-Count' => (string) $total,
            'X-Page' => (string) $page,
            'X-Per-Page' => (string) $perPage,
        ]);
    }

    public function ordersShow(int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $order = $this->findOrder($id);
        if (! $order) {
            return response()->json(['message' => 'Order not found.', 'error' => 'not_found'], 404);
        }

        return response()->json(['data' => $this->orderRow($order, true)]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $order = $this->findOrder($id);
        if (! $order) {
            return response()->json(['message' => 'Order not found.', 'error' => 'not_found'], 404);
        }
        $status = strtolower((string) $order->status);
        if (in_array($status, ['closed', 'fraud', 'cancelled', 'canceled', 'refunded', 'completed', 'delivered'], true)) {
            return response()->json([
                'message' => 'Order cannot be cancelled from status '.$order->status.'.',
                'error' => 'cancel_error',
            ], 422);
        }
        OrderStageCatalog::seed();
        try {
            app(OrderStageService::class)->apply(
                $order,
                'cancelled',
                trim((string) ($request->input('comment') ?? 'Cancelled via Admin Sales REST')),
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => 'cancel_error'], 422);
        }

        return response()->json([
            'message' => 'Order cancelled successfully.',
            'data' => $this->orderRow($order->fresh(), false),
        ]);
    }

    public function comment(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $order = $this->findOrder($id);
        if (! $order) {
            return response()->json(['message' => 'Order not found.', 'error' => 'not_found'], 404);
        }
        $body = trim((string) ($request->input('comment') ?? $request->input('body') ?? ''));
        if ($body === '') {
            return response()->json(['message' => 'Comment is required.', 'error' => 'validation'], 422);
        }
        $notify = $request->boolean('customer_notified') || $request->boolean('customerNotified');
        $comment = OrderComment::query()->create([
            'order_id' => $order->id,
            'author_type' => 'admin',
            'author_id' => Auth::guard('admin')->id(),
            'visibility' => $notify ? OrderComment::VISIBILITY_CUSTOMER : OrderComment::VISIBILITY_PRIVATE,
            'body' => $body,
        ]);

        return response()->json([
            'message' => 'Comment added successfully.',
            'data' => [
                'id' => (int) $comment->id,
                'orderId' => (int) $order->id,
                'comment' => (string) $comment->body,
                'customerNotified' => $notify ? 1 : 0,
                'createdAt' => optional($comment->created_at)?->toIso8601String(),
            ],
        ], 201);
    }

    public function reOrder(string $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        try {
            $cart = GraphqlAdminOrderComposerDraft::createFromOrder($id);
        } catch (UserError $e) {
            $message = $e->getMessage();
            $status = str_contains(strtolower($message), 'not found') ? 404 : 422;

            return response()->json(['message' => $message, 'error' => 'reorder_error'], $status);
        }

        return response()->json([
            'message' => 'Reorder composer draft created.',
            'data' => [
                'cartId' => $cart['cart_id'] ?? null,
                'customerId' => $cart['customer_id'] ?? null,
                'itemsCount' => $cart['item_count'] ?? 0,
            ],
            'cart' => $cart,
        ], 201);
    }

    public function reOrderSaveAddress(Request $request, string $id): JsonResponse
    {
        return app(AdminOrderComposerDraftRestController::class)->saveAddresses($request, $id);
    }

    public function reOrderSaveShipping(Request $request, string $id): JsonResponse
    {
        if (! $request->filled('shippingMethod') && $request->filled('shipping_method')) {
            $request->merge(['shippingMethod' => $request->input('shipping_method')]);
        }

        return app(AdminOrderComposerDraftRestController::class)->setShippingMethod($request, $id);
    }

    public function reOrderSavePayment(Request $request, string $id): JsonResponse
    {
        return app(AdminOrderComposerDraftRestController::class)->setPaymentMethod($request, $id);
    }

    public function reOrderSaveOrder(Request $request, string $id): JsonResponse
    {
        return app(AdminOrderComposerDraftRestController::class)->place($request, $id);
    }

    public function shipmentsIndex(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $limit = min(100, max(1, (int) $request->query('limit', $request->query('per_page', 20))));
        $q = Shipment::query()->orderByDesc('id');
        $orderId = (int) ($request->query('order_id') ?? $request->query('orderId') ?? 0);
        if ($orderId > 0) {
            $q->where('order_id', $orderId);
        }
        $rows = $q->limit($limit)->get()->map(fn (Shipment $s) => $this->shipmentRow($s))->values()->all();

        return response()->json(['data' => $rows]);
    }

    public function shipmentsShow(int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $shipment = Shipment::query()->find($id);
        if (! $shipment) {
            return response()->json(['message' => 'Shipment not found.', 'error' => 'not_found'], 404);
        }

        return response()->json(['data' => $this->shipmentRow($shipment)]);
    }

    public function storeShipment(Request $request, int $orderId): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $order = $this->findOrder($orderId);
        if (! $order) {
            return response()->json(['message' => 'Order not found.', 'error' => 'not_found'], 404);
        }
        $status = strtolower((string) $order->status);
        if (in_array($status, ['closed', 'cancelled', 'canceled', 'fraud'], true)) {
            return response()->json(['message' => 'Order cannot be shipped.', 'error' => 'shipment_error'], 422);
        }
        $order->loadMissing('items');
        if ($order->items->isEmpty()) {
            return response()->json(['message' => 'There is nothing to ship on this order.', 'error' => 'shipment_error'], 422);
        }
        $shipment = Shipment::query()->create([
            'order_id' => $order->id,
            'status' => Shipment::STATUS_PROCESSING,
            'carrier' => trim((string) ($request->input('carrier') ?? '')) ?: null,
            'tracking_number' => trim((string) ($request->input('tracking_number') ?? $request->input('trackingNumber') ?? '')) ?: null,
            'notes' => trim((string) ($request->input('notes') ?? 'Created via Admin Sales REST')) ?: null,
        ]);

        return response()->json([
            'message' => 'Shipment created successfully.',
            'data' => $this->shipmentRow($shipment),
        ], 201);
    }

    public function invoicesIndex(Request $request): JsonResponse
    {
        if ($denied = $this->guardDocuments()) {
            return $denied;
        }
        $limit = min(100, max(1, (int) $request->query('limit', $request->query('per_page', 20))));
        $q = Order::query()
            ->where(function ($inner) {
                $inner->whereNotNull('fulfillment_details->invoice_number')
                    ->orWhere('payment_status', 'paid')
                    ->orWhere('amount_paid', '>', 0)
                    ->orWhereIn('status', ['processing', 'completed', 'delivered']);
            })
            ->orderByDesc('id');
        $rows = $q->limit($limit)->get()->map(fn (Order $o) => $this->invoiceRow($o))->values()->all();

        return response()->json(['data' => $rows]);
    }

    public function invoicesShow(int $id): JsonResponse
    {
        if ($denied = $this->guardDocuments()) {
            return $denied;
        }
        $order = $this->findOrder($id);
        if (! $order) {
            return response()->json(['message' => 'Invoice not found.', 'error' => 'not_found'], 404);
        }

        return response()->json(['data' => $this->invoiceRow($order)]);
    }

    public function storeInvoice(Request $request, int $orderId): JsonResponse
    {
        if ($denied = $this->guardDocuments()) {
            return $denied;
        }
        if (! DocumentCommerceSettings::invoicesEnabled()) {
            return response()->json(['message' => 'Invoices are disabled.', 'error' => 'invoices_disabled'], 422);
        }
        $order = $this->findOrder($orderId);
        if (! $order) {
            return response()->json(['message' => 'Order not found.', 'error' => 'not_found'], 404);
        }
        $status = strtolower((string) $order->status);
        if (in_array($status, ['closed', 'cancelled', 'canceled', 'fraud'], true)) {
            return response()->json(['message' => 'Order cannot be invoiced.', 'error' => 'invoice_error'], 422);
        }
        DocumentCommerceSettings::formatInvoiceNumber($order);
        $order->refresh();
        $txRecorded = false;
        if ($request->boolean('can_create_transaction') || $request->boolean('canCreateTransaction')) {
            $due = round(max(0, (float) $order->grand_total - (float) ($order->amount_paid ?? 0)), 2);
            if ($due >= 0.01) {
                $this->recordPayment($order, $due);
                $order->refresh();
                $txRecorded = true;
            }
        }

        return response()->json([
            'message' => 'Invoice created successfully.',
            'data' => array_merge($this->invoiceRow($order), ['transactionRecorded' => $txRecorded]),
        ], 201);
    }

    public function refundsIndex(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $limit = min(100, max(1, (int) $request->query('limit', $request->query('per_page', 20))));
        $rows = Order::query()
            ->where('refunded_total', '>', 0)
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (Order $o) => $this->refundRow($o))
            ->values()
            ->all();

        return response()->json(['data' => $rows]);
    }

    public function refundsShow(int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $order = $this->findOrder($id);
        if (! $order || (float) ($order->refunded_total ?? 0) <= 0) {
            return response()->json(['message' => 'Refund not found.', 'error' => 'not_found'], 404);
        }

        return response()->json(['data' => $this->refundRow($order)]);
    }

    public function storeRefund(Request $request, int $orderId): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $order = $this->findOrder($orderId);
        if (! $order) {
            return response()->json(['message' => 'Order not found.', 'error' => 'not_found'], 404);
        }
        $amount = (float) ($request->input('refund')['grand_total']
            ?? $request->input('amount')
            ?? $request->input('grand_total')
            ?? 0);
        if ($amount <= 0) {
            $amount = max(0, round((float) $order->grand_total - (float) ($order->refunded_total ?? 0), 2));
        }
        if ($amount <= 0) {
            return response()->json(['message' => 'Refund amount must be greater than zero.', 'error' => 'validation'], 422);
        }
        $before = round((float) ($order->refunded_total ?? 0), 2);
        try {
            app(PaymentRefundService::class)->refundAmount(
                $order,
                $amount,
                trim((string) ($request->input('comment') ?? 'Refund via Admin Sales REST')),
            );
        } catch (ValidationException $e) {
            $messages = collect($e->errors())->flatten()->filter()->values()->all();

            return response()->json(['message' => $messages[0] ?? 'Refund failed.', 'error' => 'refund_error'], 422);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => 'refund_error'], 422);
        }
        $fresh = $order->fresh();
        if (round((float) ($fresh->refunded_total ?? 0), 2) <= $before) {
            return response()->json([
                'message' => 'Refund was not applied. Order payment status must be paid or partially_refunded.',
                'error' => 'refund_error',
            ], 422);
        }

        return response()->json([
            'message' => 'Refund created successfully.',
            'data' => $this->refundRow($fresh),
        ], 201);
    }

    public function transactionsIndex(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $limit = min(200, max(1, (int) $request->query('limit', $request->query('per_page', 50))));
        $rows = $this->collectTransactions($limit);

        return response()->json(['data' => $rows]);
    }

    public function transactionsShow(int $id): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        foreach ($this->collectTransactions(200) as $row) {
            if ((int) ($row['id'] ?? 0) === $id || (int) ($row['orderId'] ?? 0) === $id) {
                return response()->json(['data' => $row]);
            }
        }

        return response()->json(['message' => 'Transaction not found.', 'error' => 'not_found'], 404);
    }

    public function storeTransaction(Request $request): JsonResponse
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $orderId = (int) ($request->input('order_id') ?? $request->input('orderId') ?? 0);
        $orderNumber = trim((string) ($request->input('order_number') ?? $request->input('orderNumber') ?? ''));
        $order = $orderId > 0
            ? $this->findOrder($orderId)
            : ($orderNumber !== '' ? Order::query()->where('number', $orderNumber)->first() : null);
        if (! $order) {
            return response()->json(['message' => 'Order not found.', 'error' => 'not_found'], 404);
        }
        $amount = (float) ($request->input('amount') ?? 0);
        if ($amount < 0.01) {
            $amount = round(max(0, (float) $order->grand_total - (float) ($order->amount_paid ?? 0)), 2);
        }
        if ($amount < 0.01) {
            return response()->json(['message' => 'Nothing is due on this order.', 'error' => 'validation'], 422);
        }
        $tx = $this->recordPayment($order, $amount, trim((string) ($request->input('payment_method') ?? $request->input('paymentMethod') ?? '')));

        return response()->json([
            'message' => 'Transaction recorded successfully.',
            'data' => $tx,
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function orderRow(Order $o, bool $withItems): array
    {
        $payload = [
            'id' => (int) $o->id,
            'incrementId' => (string) ($o->number ?? ''),
            'status' => (string) ($o->status ?? ''),
            'paymentStatus' => (string) ($o->payment_status ?? ''),
            'email' => (string) ($o->email ?? ''),
            'customerId' => $o->customer_id ? (int) $o->customer_id : null,
            'subTotal' => (float) ($o->subtotal ?? 0),
            'grandTotal' => (float) ($o->grand_total ?? 0),
            'formattedGrandTotal' => Money::format((float) ($o->grand_total ?? 0)),
            'amountPaid' => (float) ($o->amount_paid ?? 0),
            'refundedTotal' => (float) ($o->refunded_total ?? 0),
            'currencyCode' => (string) ($o->currency_code ?? 'USD'),
            'createdAt' => optional($o->created_at)?->toIso8601String(),
        ];
        if ($withItems) {
            $o->loadMissing('items');
            $payload['items'] = $o->items->map(fn ($item) => [
                'id' => (int) $item->id,
                'sku' => (string) ($item->sku ?? ''),
                'name' => (string) ($item->name ?? ''),
                'qty' => (float) ($item->qty ?? 0),
                'price' => (float) ($item->unit_price ?? $item->price ?? 0),
                'total' => (float) ($item->total ?? 0),
            ])->values()->all();
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function shipmentRow(Shipment $s): array
    {
        return [
            'id' => (int) $s->id,
            'orderId' => (int) $s->order_id,
            'status' => (string) ($s->status ?? ''),
            'carrier' => (string) ($s->carrier ?? ''),
            'trackingNumber' => (string) ($s->tracking_number ?? ''),
            'createdAt' => optional($s->created_at)?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceRow(Order $o): array
    {
        $details = is_array($o->fulfillment_details) ? $o->fulfillment_details : [];

        return [
            'id' => (int) $o->id,
            'orderId' => (int) $o->id,
            'incrementId' => (string) ($details['invoice_number'] ?? $o->number ?? ''),
            'state' => (string) ($o->payment_status ?: $o->status ?: 'pending'),
            'grandTotal' => (float) ($o->grand_total ?? 0),
            'formattedGrandTotal' => Money::format((float) ($o->grand_total ?? 0)),
            'createdAt' => optional($o->created_at)?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function refundRow(Order $o): array
    {
        return [
            'id' => (int) $o->id,
            'orderId' => (int) $o->id,
            'incrementId' => (string) ($o->number ?? ''),
            'refundedTotal' => (float) ($o->refunded_total ?? 0),
            'grandTotal' => (float) ($o->grand_total ?? 0),
            'paymentStatus' => (string) ($o->payment_status ?? ''),
            'currencyCode' => (string) ($o->currency_code ?? 'USD'),
            'updatedAt' => optional($o->updated_at)?->toIso8601String(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectTransactions(int $limit): array
    {
        $orders = Order::query()
            ->where(function ($q) {
                $q->whereNotNull('payment_details')
                    ->orWhere('amount_paid', '>', 0);
            })
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'number', 'payment_details', 'amount_paid', 'created_at']);
        $rows = [];
        foreach ($orders as $order) {
            $details = is_array($order->payment_details) ? $order->payment_details : [];
            $txs = is_array($details['transactions'] ?? null) ? $details['transactions'] : [];
            if ($txs === [] && ((float) ($order->amount_paid ?? 0) > 0 || $details !== [])) {
                $txs = [[
                    'id' => $order->id,
                    'transaction_id' => (string) ($details['transaction_id'] ?? ('TX-'.$order->id)),
                    'amount' => (float) ($details['amount'] ?? $order->amount_paid ?? 0),
                    'status' => (string) ($details['status'] ?? 'paid'),
                    'payment_method' => $details['payment_method'] ?? 'manual',
                    'created_at' => optional($order->created_at)?->toIso8601String(),
                ]];
            }
            foreach ($txs as $tx) {
                if (! is_array($tx)) {
                    continue;
                }
                $rows[] = [
                    'id' => (int) ($tx['_id'] ?? $order->id),
                    'orderId' => (int) $order->id,
                    'orderIncrementId' => (string) $order->number,
                    'transactionId' => (string) ($tx['transaction_id'] ?? $tx['transactionId'] ?? ('TX-'.$order->id)),
                    'amount' => (float) ($tx['amount'] ?? 0),
                    'status' => (string) ($tx['status'] ?? 'paid'),
                    'paymentMethod' => (string) ($tx['payment_method'] ?? $tx['paymentMethod'] ?? 'manual'),
                    'createdAt' => (string) ($tx['created_at'] ?? $tx['createdAt'] ?? optional($order->created_at)?->toIso8601String()),
                ];
                if (count($rows) >= $limit) {
                    return $rows;
                }
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function recordPayment(Order $order, float $amount, string $method = ''): array
    {
        $grand = round((float) $order->grand_total, 2);
        $paid = round((float) ($order->amount_paid ?? 0), 2);
        $due = round(max(0, $grand - $paid), 2);
        $amount = round(min($due > 0 ? $due : $amount, max($amount, 0.01)), 2);
        $newPaid = round($paid + $amount, 2);
        $newDue = round(max(0, $grand - $newPaid), 2);
        if ($method === '') {
            $method = 'manual';
        }
        $details = is_array($order->payment_details) ? $order->payment_details : [];
        $txs = is_array($details['transactions'] ?? null) ? $details['transactions'] : [];
        $txId = 'TX-'.strtoupper(Str::random(10));
        $createdAt = now()->toIso8601String();
        $tx = [
            'id' => (string) Str::uuid(),
            '_id' => (int) $order->id,
            'transaction_id' => $txId,
            'transactionId' => $txId,
            'order_number' => $order->number,
            'orderId' => (string) $order->id,
            'amount' => $amount,
            'payment_method' => $method,
            'paymentMethod' => $method,
            'status' => 'paid',
            'created_at' => $createdAt,
            'createdAt' => $createdAt,
        ];
        $txs[] = $tx;
        $details['transactions'] = $txs;
        $details['paid_total'] = $newPaid;
        $order->updateTrusted([
            'amount_paid' => $newPaid,
            'amount_due' => $newDue,
            'payment_status' => $newDue <= 0.009 ? 'paid' : 'partial',
            'payment_details' => $details,
        ]);

        return [
            'id' => (int) $order->id,
            'orderId' => (int) $order->id,
            'transactionId' => $txId,
            'amount' => $amount,
            'status' => 'paid',
            'paymentMethod' => $method,
            'createdAt' => $createdAt,
        ];
    }

    private function findOrder(int|string $id): ?Order
    {
        if (is_numeric($id)) {
            return Order::query()->find((int) $id);
        }

        return Order::query()->where('number', (string) $id)->first();
    }

    private function guard(): ?JsonResponse
    {
        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin) {
            return response()->json(['message' => 'Unauthenticated.', 'error' => 'unauthenticated'], 401);
        }
        if (! $admin->hasPermission('orders.manage')) {
            return response()->json(['message' => 'Forbidden.', 'error' => 'forbidden'], 403);
        }

        return null;
    }

    private function guardDocuments(): ?JsonResponse
    {
        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin) {
            return response()->json(['message' => 'Unauthenticated.', 'error' => 'unauthenticated'], 401);
        }
        if (! $admin->hasPermission('orders.documents') && ! $admin->hasPermission('orders.manage')) {
            return response()->json(['message' => 'Forbidden.', 'error' => 'forbidden'], 403);
        }

        return null;
    }
}
