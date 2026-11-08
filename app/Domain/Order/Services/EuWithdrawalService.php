<?php

namespace App\Domain\Order\Services;

use App\Domain\Customer\Models\Customer;
use App\Domain\Order\EuWithdrawalSchema;
use App\Domain\Order\Models\EuWithdrawalRequest;
use App\Domain\Order\Models\Order;
use App\Domain\Shipping\Models\Shipment;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class EuWithdrawalService
{
    public const WITHDRAWAL_DAYS = 14;

    public function create(Customer $customer, Order $order, ?string $reason = null): EuWithdrawalRequest
    {
        EuWithdrawalSchema::ensure();

        if ((int) $order->customer_id !== (int) $customer->id) {
            abort(403, 'This order does not belong to the current customer.');
        }

        $this->assertEligible($order);

        $open = EuWithdrawalRequest::query()
            ->where('order_id', $order->id)
            ->whereIn('status', [EuWithdrawalRequest::STATUS_PENDING, EuWithdrawalRequest::STATUS_APPROVED])
            ->exists();
        if ($open) {
            throw ValidationException::withMessages([
                'order' => 'This order already has an open EU withdrawal request.',
            ]);
        }

        return EuWithdrawalRequest::query()->create([
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'reference' => EuWithdrawalSchema::newReference(),
            'status' => EuWithdrawalRequest::STATUS_PENDING,
            'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
            'requested_at' => now(),
        ]);
    }

    public function approve(EuWithdrawalRequest $request, ?int $adminId = null, ?string $note = null): EuWithdrawalRequest
    {
        if ($request->status !== EuWithdrawalRequest::STATUS_PENDING) {
            throw ValidationException::withMessages(['status' => 'This withdrawal request cannot be approved.']);
        }
        $request->update([
            'status' => EuWithdrawalRequest::STATUS_APPROVED,
            'decided_at' => now(),
            'admin_note' => $note ?? $request->admin_note,
        ]);

        return $request->fresh(['order', 'customer']);
    }

    public function reject(EuWithdrawalRequest $request, ?int $adminId = null, ?string $note = null): EuWithdrawalRequest
    {
        if (! in_array($request->status, [EuWithdrawalRequest::STATUS_PENDING, EuWithdrawalRequest::STATUS_APPROVED], true)) {
            throw ValidationException::withMessages(['status' => 'This withdrawal request cannot be rejected.']);
        }
        $request->update([
            'status' => EuWithdrawalRequest::STATUS_REJECTED,
            'decided_at' => now(),
            'admin_note' => $note ?? $request->admin_note,
        ]);

        return $request->fresh(['order', 'customer']);
    }

    public function complete(EuWithdrawalRequest $request, ?string $note = null): EuWithdrawalRequest
    {
        if ($request->status !== EuWithdrawalRequest::STATUS_APPROVED) {
            throw ValidationException::withMessages(['status' => 'Approve the withdrawal before marking it completed.']);
        }
        $request->update([
            'status' => EuWithdrawalRequest::STATUS_COMPLETED,
            'decided_at' => now(),
            'admin_note' => $note ?? $request->admin_note,
        ]);

        return $request->fresh(['order', 'customer']);
    }

    /** @return array<string, mixed> */
    public function present(EuWithdrawalRequest $row): array
    {
        $row->loadMissing('order:id,number,status,created_at');

        return [
            'id' => (int) $row->id,
            'reference' => $row->reference,
            'status' => $row->status,
            'reason' => $row->reason,
            'admin_note' => $row->admin_note,
            'order_id' => (int) $row->order_id,
            'order_number' => $row->order?->number,
            'requested_at' => optional($row->requested_at)?->toIso8601String(),
            'decided_at' => optional($row->decided_at)?->toIso8601String(),
            'created_at' => optional($row->created_at)?->toIso8601String(),
        ];
    }

    public function isEligible(Order $order): bool
    {
        try {
            $this->assertEligible($order);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    protected function assertEligible(Order $order): void
    {
        if (in_array((string) $order->status, ['cancelled', 'refunded'], true)) {
            throw ValidationException::withMessages(['order' => 'This order is not eligible for EU withdrawal.']);
        }
        if ($this->isDigitalOnlyOrder($order)) {
            throw ValidationException::withMessages(['order' => 'Digital-only orders are not eligible for EU withdrawal.']);
        }
        if (now()->gt($this->withdrawalDeadline($order))) {
            throw ValidationException::withMessages(['order' => 'The 14-day EU withdrawal period for this order has ended.']);
        }
    }

    protected function isDigitalOnlyOrder(Order $order): bool
    {
        $order->loadMissing('items.product');
        if ($order->items->isEmpty()) {
            return true;
        }
        foreach ($order->items as $item) {
            $product = $item->product;
            if (! $product) {
                continue;
            }
            if ($product->requiresShipping()) {
                return false;
            }
        }

        return true;
    }

    protected function withdrawalDeadline(Order $order): Carbon
    {
        $order->loadMissing('shipments');
        $deliveredAt = $order->shipments
            ->where('status', Shipment::STATUS_DELIVERED)
            ->pluck('delivered_at')
            ->filter()
            ->max();
        $start = $deliveredAt instanceof Carbon
            ? $deliveredAt
            : ($order->created_at ?? now());

        return $start->copy()->addDays(self::WITHDRAWAL_DAYS)->endOfDay();
    }
}
