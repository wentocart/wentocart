<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerOrder;
use App\Domain\Marketplace\SellerPaymentRequestSchema;
use Illuminate\Validation\ValidationException;

/**
 * admin records external payout.
 */
class SellerPaymentRequestService
{
    public const STATUS_REQUESTED = 'requested';

    public const STATUS_PAID = 'paid';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_CANCELED = 'canceled';

    public function request(Seller $seller, SellerOrder $order): SellerOrder
    {
        SellerPaymentRequestSchema::ensure();
        if ((int) $order->seller_id !== (int) $seller->id) {
            abort(404);
        }
        if (in_array((string) $order->seller_payout_status, [self::STATUS_REQUESTED, self::STATUS_PAID], true)) {
            throw ValidationException::withMessages([
                'payment' => 'Payment has already been requested or paid for this order.',
            ]);
        }
        if ((string) $order->seller_payout_status === self::STATUS_REFUNDED
            || (string) $order->payment_status === 'refunded') {
            throw ValidationException::withMessages([
                'payment' => 'Refunded orders cannot request payment.',
            ]);
        }
        if (! in_array((string) $order->status, ['completed', 'delivered', 'shipped'], true)
            && (string) $order->payment_status !== 'paid') {
            // Allow when core payment is paid and seller order is processing/completed.
            if (! in_array((string) $order->status, ['processing', 'completed', 'delivered', 'shipped'], true)) {
                throw ValidationException::withMessages([
                    'payment' => 'Order is not ready for a payment request.',
                ]);
            }
        }

        $order->forceFill([
            'seller_payout_status' => self::STATUS_REQUESTED,
            'seller_payout_requested_at' => now(),
        ])->save();

        return $order->fresh();
    }

    public function pay(SellerOrder $order, ?string $comment = null): SellerOrder
    {
        SellerPaymentRequestSchema::ensure();
        if ((string) $order->seller_payout_status !== self::STATUS_REQUESTED) {
            throw ValidationException::withMessages([
                'payment' => 'Only requested payment orders can be paid.',
            ]);
        }
        $order->forceFill([
            'seller_payout_status' => self::STATUS_PAID,
            'seller_payout_paid_at' => now(),
            'seller_payout_comment' => $comment !== null && trim($comment) !== '' ? trim($comment) : 'Paid Out of system',
        ])->save();

        return $order->fresh();
    }
}
