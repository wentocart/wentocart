<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\ReturnCustomField;
use App\Domain\Order\Models\ReturnMessage;
use App\Domain\Order\Models\ReturnReason;
use App\Domain\Order\Models\ShopReturn;
use App\Domain\Order\Services\ShopReturnService;
use App\Domain\Settings\ReturnSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Shop REST RMA REST under /api/v1/store/* — wraps ShopReturnService.
 */
class ShopReturnsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        if (! $customer) {
            return $this->unauth();
        }

        $rows = ShopReturn::query()
            ->where('customer_id', $customer->id)
            ->with('items')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (ShopReturn $r) => $this->present($r))
            ->values()
            ->all();

        return response()->json($rows, 200, [
            'X-Total-Count' => (string) count($rows),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $customer = $this->customer($request);
        if (! $customer) {
            return $this->unauth();
        }
        $row = $this->owned($customer, $id);
        if (! $row) {
            return response()->json(['message' => 'Return not found.', 'success' => false], 404);
        }

        return response()->json($this->present($row));
    }

    public function store(Request $request, ShopReturnService $returns): JsonResponse
    {
        $customer = $this->customer($request);
        if (! $customer) {
            return $this->unauth();
        }
        if (! ReturnSettings::enabled()) {
            return response()->json(['message' => 'Returns are disabled.', 'success' => false], 400);
        }

        $orderId = (int) ($request->input('order_id') ?? $request->input('orderId') ?? 0);
        $orderItemId = (int) ($request->input('order_item_id') ?? $request->input('orderItemId') ?? 0);
        $qty = (float) ($request->input('rma_qty') ?? $request->input('qty') ?? 0);
        $reasonId = (int) ($request->input('rma_reason_id') ?? $request->input('reason_id') ?? $request->input('reasonId') ?? 0);
        $reasonText = trim((string) ($request->input('reason') ?? $request->input('information') ?? ''));
        $resolution = trim((string) ($request->input('resolution_type') ?? $request->input('resolution') ?? 'return'));
        $note = trim((string) ($request->input('customer_note') ?? $request->input('message') ?? ''));

        $order = Order::query()->whereKey($orderId)->where('customer_id', $customer->id)->first();
        if (! $order) {
            return response()->json(['message' => 'Order not found.', 'success' => false], 404);
        }

        $lines = [];
        if ($orderItemId > 0 && $qty > 0) {
            $lines[] = ['order_item_id' => $orderItemId, 'qty' => $qty];
        } elseif (is_array($request->input('items'))) {
            foreach ($request->input('items') as $line) {
                if (! is_array($line)) {
                    continue;
                }
                $lines[] = [
                    'order_item_id' => (int) ($line['order_item_id'] ?? $line['orderItemId'] ?? 0),
                    'qty' => (float) ($line['qty'] ?? $line['rma_qty'] ?? 0),
                ];
            }
        }
        if ($lines === []) {
            return response()->json(['message' => 'order_item_id and rma_qty are required.', 'success' => false], 422);
        }

        if ($reasonId < 1 && $reasonText === '' && ReturnSettings::reasonRequired()) {
            $first = ReturnReason::query()->where('is_enabled', true)->orderBy('position')->first();
            $reasonId = $first ? (int) $first->id : 0;
            if ($reasonId < 1) {
                $reasonText = 'Customer return request';
            }
        }

        try {
            $row = $returns->request($customer, $order, $lines, [
                'reason' => $reasonText !== '' ? $reasonText : ($resolution === 'cancel_items' ? 'Cancel items' : 'Return'),
                'reason_id' => $reasonId > 0 ? $reasonId : null,
                'resolution' => $resolution === 'cancel_items' ? 'refund' : 'refund',
                'customer_note' => $note !== '' ? $note : null,
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => collect($e->errors())->flatten()->first() ?: 'Unable to create return.',
                'success' => false,
            ], 422);
        }

        return response()->json($this->present($row->fresh('items')), 201);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $customer = $this->customer($request);
        if (! $customer) {
            return $this->unauth();
        }
        $row = $this->owned($customer, $id);
        if (! $row) {
            return response()->json(['message' => 'Return not found.', 'success' => false], 404);
        }
        if ($row->status !== ShopReturn::STATUS_REQUESTED) {
            return response()->json(['message' => 'Only pending returns can be cancelled.', 'success' => false], 422);
        }
        $row->update([
            'status' => ShopReturn::STATUS_REJECTED,
            'rejected_at' => now(),
            'admin_note' => trim((string) ($row->admin_note ? $row->admin_note.' ' : '').'[cancelled by customer]'),
        ]);

        return response()->json($this->present($row->fresh('items')));
    }

    public function reopen(Request $request, int $id): JsonResponse
    {
        $customer = $this->customer($request);
        if (! $customer) {
            return $this->unauth();
        }
        $row = $this->owned($customer, $id);
        if (! $row) {
            return response()->json(['message' => 'Return not found.', 'success' => false], 404);
        }
        if ($row->status !== ShopReturn::STATUS_REJECTED) {
            return response()->json(['message' => 'Only cancelled/declined returns can be reopened.', 'success' => false], 422);
        }
        $row->update([
            'status' => ShopReturn::STATUS_REQUESTED,
            'rejected_at' => null,
            'requested_at' => now(),
        ]);

        return response()->json($this->present($row->fresh('items')));
    }

    public function close(Request $request, int $id): JsonResponse
    {
        $customer = $this->customer($request);
        if (! $customer) {
            return $this->unauth();
        }
        $row = $this->owned($customer, $id);
        if (! $row) {
            return response()->json(['message' => 'Return not found.', 'success' => false], 404);
        }
        if (! in_array($row->status, [ShopReturn::STATUS_REQUESTED, ShopReturn::STATUS_APPROVED, ShopReturn::STATUS_RECEIVED], true)) {
            return response()->json(['message' => 'This return cannot be closed.', 'success' => false], 422);
        }
        $row->update([
            'status' => ShopReturn::STATUS_REFUNDED,
            'refunded_at' => $row->refunded_at ?? now(),
            'admin_note' => trim((string) ($row->admin_note ? $row->admin_note.' ' : '').'[closed by customer]'),
        ]);

        return response()->json($this->present($row->fresh('items')));
    }

    public function returnableOrders(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        if (! $customer) {
            return $this->unauth();
        }
        if (! ReturnSettings::enabled()) {
            return response()->json([]);
        }

        $orders = Order::query()
            ->where('customer_id', $customer->id)
            ->with('items')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->filter(function (Order $order) {
                foreach ($order->items as $item) {
                    if ((float) $item->returnableQty() > 0) {
                        return true;
                    }
                }

                return false;
            })
            ->map(fn (Order $order) => [
                'id' => (int) $order->id,
                'incrementId' => (string) $order->number,
                'status' => (string) $order->status,
                'grandTotal' => (float) $order->grand_total,
                'createdAt' => optional($order->created_at)?->toIso8601String(),
            ])
            ->values()
            ->all();

        return response()->json($orders);
    }

    public function returnableItems(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        if (! $customer) {
            return $this->unauth();
        }
        $orderId = (int) ($request->query('order_id') ?? $request->query('orderId') ?? 0);
        if ($orderId < 1) {
            return response()->json(['message' => 'order_id is required.', 'success' => false], 422);
        }
        $order = Order::query()->whereKey($orderId)->where('customer_id', $customer->id)->with('items')->first();
        if (! $order) {
            return response()->json(['message' => 'Order not found.', 'success' => false], 404);
        }
        if (! ReturnSettings::enabled()) {
            return response()->json([]);
        }

        $out = [];
        foreach ($order->items as $item) {
            $qty = (float) $item->returnableQty();
            if ($qty <= 0) {
                continue;
            }
            $out[] = [
                'id' => (int) $item->id,
                'orderItemId' => (int) $item->id,
                'order_item_id' => (int) $item->id,
                'sku' => (string) $item->sku,
                'name' => (string) $item->name,
                'qty' => $qty,
                'rmaQty' => $qty,
                'forReturnQuantity' => $qty,
                'forCancelQuantity' => $qty,
                'price' => (float) $item->unit_price,
            ];
        }

        return response()->json($out);
    }

    public function reasons(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        if (! $customer) {
            return $this->unauth();
        }

        $rows = ReturnReason::query()
            ->where('is_enabled', true)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (ReturnReason $r) => [
                'id' => (int) $r->id,
                'title' => (string) $r->name,
                'name' => (string) $r->name,
                'code' => (string) $r->code,
                'status' => 1,
            ])
            ->values()
            ->all();

        return response()->json($rows);
    }

    public function customFields(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        if (! $customer) {
            return $this->unauth();
        }
        if (! Schema::hasTable('return_custom_fields')) {
            return response()->json([]);
        }

        $rows = ReturnCustomField::query()
            ->where('is_enabled', true)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (ReturnCustomField $f) => [
                'id' => (int) $f->id,
                'code' => (string) $f->code,
                'label' => (string) $f->label,
                'type' => (string) $f->type,
                'isRequired' => (bool) $f->is_required,
                'options' => $f->options ?? [],
            ])
            ->values()
            ->all();

        return response()->json($rows);
    }

    public function messages(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        if (! $customer) {
            return $this->unauth();
        }
        $returnId = (int) ($request->query('return_id') ?? $request->query('returnId') ?? 0);
        $row = $this->owned($customer, $returnId);
        if (! $row) {
            return response()->json(['message' => 'Return not found.', 'success' => false], 404);
        }
        if (! Schema::hasTable('return_messages')) {
            return response()->json([]);
        }

        $msgs = ReturnMessage::query()
            ->where('order_return_id', $row->id)
            ->orderBy('id')
            ->get()
            ->map(fn (ReturnMessage $m) => [
                'id' => (int) $m->id,
                'returnId' => (int) $row->id,
                'message' => (string) $m->body,
                'body' => (string) $m->body,
                'isAdmin' => (bool) $m->is_admin,
                'createdAt' => optional($m->created_at)?->toIso8601String(),
            ])
            ->values()
            ->all();

        return response()->json($msgs);
    }

    public function sendMessage(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        if (! $customer) {
            return $this->unauth();
        }
        $returnId = (int) ($request->input('return_id') ?? $request->input('returnId') ?? 0);
        $row = $this->owned($customer, $returnId);
        if (! $row) {
            return response()->json(['message' => 'Return not found.', 'success' => false], 404);
        }
        $body = trim((string) ($request->input('message') ?? $request->input('body') ?? ''));
        if ($body === '') {
            return response()->json(['message' => 'message is required.', 'success' => false], 422);
        }
        if (! Schema::hasTable('return_messages')) {
            return response()->json(['message' => 'Messaging unavailable.', 'success' => false], 503);
        }

        $msg = ReturnMessage::query()->create([
            'order_return_id' => $row->id,
            'customer_id' => $customer->id,
            'body' => $body,
            'is_admin' => false,
        ]);

        return response()->json([
            'id' => (int) $msg->id,
            'returnId' => (int) $row->id,
            'message' => (string) $msg->body,
            'body' => (string) $msg->body,
            'isAdmin' => false,
            'createdAt' => optional($msg->created_at)?->toIso8601String(),
        ], 201);
    }

    private function customer(Request $request): ?Customer
    {
        $user = $request->user('customer');

        return $user instanceof Customer ? $user : null;
    }

    private function unauth(): JsonResponse
    {
        return response()->json([
            'detail' => 'Unauthenticated. Please login to perform this action',
            'message' => 'Unauthenticated. Please login to perform this action',
        ], 401);
    }

    private function owned(Customer $customer, int $id): ?ShopReturn
    {
        if ($id < 1) {
            return null;
        }

        return ShopReturn::query()
            ->whereKey($id)
            ->where('customer_id', $customer->id)
            ->with('items')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ShopReturn $row): array
    {
        $status = (string) $row->status;
        $canCancel = $status === ShopReturn::STATUS_REQUESTED;
        $canReopen = $status === ShopReturn::STATUS_REJECTED;
        $canClose = in_array($status, [ShopReturn::STATUS_REQUESTED, ShopReturn::STATUS_APPROVED, ShopReturn::STATUS_RECEIVED], true);

        return [
            'id' => (int) $row->id,
            '_id' => (int) $row->id,
            'number' => (string) $row->number,
            'orderId' => (int) $row->order_id,
            'customerId' => (int) $row->customer_id,
            'status' => $status,
            'statusTitle' => ucfirst($status),
            'reason' => $row->reason,
            'rmaReasonId' => $row->reason_id ? (int) $row->reason_id : null,
            'resolution' => (string) ($row->resolution ?? 'refund'),
            'customerNote' => $row->customer_note,
            'refundTotal' => (float) $row->refund_total,
            'canClose' => $canClose,
            'canReopen' => $canReopen,
            'canCancel' => $canCancel,
            'isExpired' => false,
            'createdAt' => optional($row->created_at)?->toIso8601String(),
            'updatedAt' => optional($row->updated_at)?->toIso8601String(),
            'items' => $row->items->map(fn ($item) => [
                'id' => (int) $item->id,
                'orderItemId' => (int) $item->order_item_id,
                'qty' => (float) $item->qty,
                'rmaQty' => (float) $item->qty,
                'lineTotal' => (float) $item->line_total,
            ])->values()->all(),
            'success' => true,
        ];
    }
}
