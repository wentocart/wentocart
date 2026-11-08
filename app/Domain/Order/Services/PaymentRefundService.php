<?php

namespace App\Domain\Order\Services;

use App\Domain\Order\Models\Order;
use App\Domain\Payment\Contracts\RefundsPayments;
use App\Domain\Payment\PaymentGatewayRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Idempotent customer payment refunds through the configured gateway (when supported).
 */
class PaymentRefundService
{
    public function __construct(
        protected PaymentGatewayRegistry $gateways,
    ) {}

    /**
     * Refund up to $amount on a paid/partially-refunded order and update payment state.
     * No-op when the order is not in a refundable payment state or amount is zero.
     */
    public function refundAmount(Order $order, float $amount, string $comment = ''): void
    {
        DB::transaction(function () use ($order, $amount, $comment) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $locked->loadMissing('paymentMethod');

            if (! in_array($locked->payment_status, ['paid', 'partially_refunded'], true)) {
                return;
            }

            $already = round((float) $locked->refunded_total, 2);
            $remaining = round(max(0, (float) $locked->grand_total - $already), 2);
            $amount = round(min(max(0, $amount), $remaining), 2);
            if ($amount <= 0) {
                return;
            }

            $driver = (string) ($locked->paymentMethod?->driver ?: 'offline');
            try {
                $gateway = $this->gateways->get($driver);
                if ($gateway instanceof RefundsPayments) {
                    $result = $gateway->refund($locked, $amount);
                    if ($result->status === 'failed') {
                        throw ValidationException::withMessages([
                            'payment' => $result->message ?: 'Payment gateway refund failed.',
                        ]);
                    }
                }
            } catch (ValidationException $e) {
                throw $e;
            } catch (\Throwable $e) {
                report($e);
                throw ValidationException::withMessages([
                    'payment' => 'Could not refund through the payment driver.',
                ]);
            }

            $newRefunded = round($already + $amount, 2);
            $full = $newRefunded + 0.009 >= (float) $locked->grand_total;

            Order::withoutEvents(function () use ($locked, $newRefunded, $full, $comment) {
                // Totals / payment_status are not mass-assignable — use trusted write.
                $locked->updateTrusted([
                    'refunded_total' => $newRefunded,
                    'payment_status' => $full ? 'refunded' : 'partially_refunded',
                ]);
                if ($comment !== '') {
                    $locked->statusHistories()->create([
                        'status' => $locked->status,
                        'comment' => $comment,
                    ]);
                }
            });
        });
    }
}
