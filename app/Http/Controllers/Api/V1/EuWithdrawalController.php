<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Order\EuWithdrawalSchema;
use App\Domain\Order\Models\EuWithdrawalRequest;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Services\EuWithdrawalService;
use App\Domain\Settings\ShopSetting;
use App\Domain\Store\Models\Store;
use App\Domain\Store\StoreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Account withdrawals + Shop REST `/api/v1/store/eu-withdrawals*`.
 */
class EuWithdrawalController extends Controller
{
    public function accountIndex(Request $request, EuWithdrawalService $withdrawals): JsonResponse
    {
        EuWithdrawalSchema::ensure();
        $customer = $request->user('customer');
        $rows = EuWithdrawalRequest::query()
            ->where('customer_id', $customer->id)
            ->with('order:id,number')
            ->latest('id')
            ->get();

        return response()->json([
            'withdrawals' => $rows->map(fn (EuWithdrawalRequest $row) => $withdrawals->present($row))->values(),
        ]);
    }

    public function createForOrder(Request $request, string $number, EuWithdrawalService $withdrawals): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $customer = $request->user('customer');
        $order = $customer->orders()->where('number', $number)->firstOrFail();
        $row = $withdrawals->create($customer, $order, $data['reason'] ?? null);

        return response()->json(['withdrawal' => $withdrawals->present($row)], 201);
    }

    /** GET /api/v1/store/eu-withdrawal-status */
    public function status(): JsonResponse
    {
        $enabled = ShopSetting::bool('privacy.eu_withdrawal_enabled', true)
            || ShopSetting::bool('orders.eu_withdrawal_enabled', true);
        $store = app(StoreContext::class)->store()
            ?? Store::query()->where('is_default', true)->first();
        $code = (string) ($store?->code ?: 'default');

        return response()->json([
            'id' => $code,
            'channel' => $code,
            'enabled' => $enabled,
        ]);
    }

    /** GET /api/v1/store/eu-withdrawals */
    public function index(Request $request): JsonResponse
    {
        if (! $this->featureEnabled()) {
            return response()->json(['message' => 'EU withdrawal is disabled.', 'success' => false], 404);
        }
        EuWithdrawalSchema::ensure();
        $customer = $request->user('customer');
        $rows = EuWithdrawalRequest::query()
            ->where('customer_id', $customer->id)
            ->with(['order', 'customer'])
            ->latest('id')
            ->get()
            ->map(fn (EuWithdrawalRequest $row) => $this->withdrawalRow($row))
            ->values()
            ->all();

        return response()->json($rows);
    }

    /** GET /api/v1/store/eu-withdrawals/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        if (! $this->featureEnabled()) {
            return response()->json(['message' => 'EU withdrawal is disabled.', 'success' => false], 404);
        }
        EuWithdrawalSchema::ensure();
        $customer = $request->user('customer');
        $row = EuWithdrawalRequest::query()
            ->where('customer_id', $customer->id)
            ->with(['order', 'customer'])
            ->find($id);
        if (! $row) {
            return response()->json(['message' => 'EU withdrawal not found.', 'success' => false], 404);
        }

        return response()->json($this->withdrawalRow($row));
    }

    /** POST /api/v1/store/eu-withdrawals */
    public function store(Request $request, EuWithdrawalService $withdrawals): JsonResponse
    {
        if (! $this->featureEnabled()) {
            return response()->json(['message' => 'EU withdrawal is disabled.', 'success' => false], 404);
        }
        $orderId = (int) ($request->input('order_id') ?? $request->input('orderId') ?? 0);
        if ($orderId < 1) {
            return response()->json(['message' => 'The order id field is required.', 'success' => false], 400);
        }
        $reason = $request->input('reason_text') ?? $request->input('reason');
        $customer = $request->user('customer');
        $order = $customer->orders()->whereKey($orderId)->first();
        if (! $order) {
            return response()->json(['message' => 'Order not found.', 'success' => false], 404);
        }

        EuWithdrawalSchema::ensure();
        $existing = EuWithdrawalRequest::query()
            ->where('order_id', $order->id)
            ->whereIn('status', [EuWithdrawalRequest::STATUS_PENDING, EuWithdrawalRequest::STATUS_APPROVED])
            ->with(['order', 'customer'])
            ->first();
        if ($existing) {
            return response()->json($this->withdrawalRow($existing), 201);
        }

        try {
            $row = $withdrawals->create($customer, $order, is_string($reason) ? $reason : null);
        } catch (ValidationException $e) {
            $flat = collect($e->errors())->flatten()->implode(' ');

            return response()->json([
                'message' => $flat !== '' ? $flat : 'Unable to file withdrawal.',
                'success' => false,
            ], 400);
        }

        return response()->json($this->withdrawalRow($row->load(['order', 'customer'])), 201);
    }

    /** POST /api/v1/store/eu-withdrawals/guest */
    public function guestStore(Request $request, EuWithdrawalService $withdrawals): JsonResponse
    {
        if (! $this->featureEnabled()) {
            return response()->json(['message' => 'EU withdrawal is disabled.', 'success' => false], 404);
        }
        $increment = trim((string) ($request->input('order_increment_id') ?? $request->input('orderIncrementId') ?? ''));
        $email = strtolower(trim((string) ($request->input('email') ?? '')));
        if ($increment === '' || $email === '') {
            return response()->json([
                'message' => 'The order increment id and email fields are required.',
                'success' => false,
            ], 400);
        }
        $reason = $request->input('reason_text') ?? $request->input('reason');

        $order = Order::query()
            ->where('number', $increment)
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();
        if (! $order) {
            return response()->json(['message' => 'Guest order not found.', 'success' => false], 404);
        }

        EuWithdrawalSchema::ensure();
        $existing = EuWithdrawalRequest::query()
            ->where('order_id', $order->id)
            ->whereIn('status', [EuWithdrawalRequest::STATUS_PENDING, EuWithdrawalRequest::STATUS_APPROVED])
            ->with(['order', 'customer'])
            ->first();
        if ($existing) {
            return response()->json($this->withdrawalRow($existing, true), 201);
        }

        try {
            if (! $withdrawals->isEligible($order)) {
                return response()->json(['message' => 'Order is not eligible for EU withdrawal.', 'success' => false], 400);
            }
            $row = EuWithdrawalRequest::query()->create([
                'customer_id' => $order->customer_id,
                'order_id' => $order->id,
                'reference' => EuWithdrawalSchema::newReference(),
                'status' => EuWithdrawalRequest::STATUS_PENDING,
                'reason' => is_string($reason) && trim($reason) !== '' ? trim($reason) : null,
                'requested_at' => now(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage(), 'success' => false], 400);
        }

        return response()->json($this->withdrawalRow($row->load(['order', 'customer']), true), 201);
    }

    private function featureEnabled(): bool
    {
        return ShopSetting::bool('privacy.eu_withdrawal_enabled', true)
            || ShopSetting::bool('orders.eu_withdrawal_enabled', true);
    }

    /**
     * @return array<string, mixed>
     */
    private function withdrawalRow(EuWithdrawalRequest $row, bool $forceGuest = false): array
    {
        $row->loadMissing(['order', 'customer']);
        $order = $row->order;
        $customer = $row->customer;
        $status = match ((string) $row->status) {
            EuWithdrawalRequest::STATUS_REJECTED => 'declined',
            EuWithdrawalRequest::STATUS_COMPLETED => 'refunded',
            default => 'received',
        };
        $isGuest = $forceGuest || $row->customer_id === null || ($order && $order->customer_id === null);

        return [
            'id' => (int) $row->id,
            'uuid' => (string) ($row->reference ?? ''),
            'orderId' => (int) $row->order_id,
            'orderIncrementId' => (string) ($order?->number ?? ''),
            'isGuest' => (bool) $isGuest,
            'customerEmail' => (string) ($customer?->email ?? $order?->email ?? ''),
            'status' => $status,
            'reasonText' => $row->reason,
            'receivedAt' => optional($row->requested_at ?? $row->created_at)?->toIso8601String(),
            'confirmationSentAt' => optional($row->requested_at ?? $row->created_at)?->toIso8601String(),
            'declinedAt' => $status === 'declined' ? optional($row->decided_at)?->toIso8601String() : null,
            'declinedReason' => $status === 'declined' ? (string) ($row->admin_note ?? '') : null,
            'refundedAt' => $status === 'refunded' ? optional($row->decided_at)?->toIso8601String() : null,
            'refundNote' => $status === 'refunded' ? (string) ($row->admin_note ?? '') : null,
            'createdAt' => optional($row->created_at)?->toIso8601String(),
            'updatedAt' => optional($row->updated_at)?->toIso8601String(),
        ];
    }
}
