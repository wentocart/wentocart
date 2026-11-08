<?php

namespace App\Domain\Payment\Contracts;

use App\Domain\Order\Models\Order;
use App\Domain\Payment\SavedChargeResult;

/**
 * Optional capability: a gateway that can charge a customer again later, without the
 * customer being present.
 *
 * Subscriptions need this. A normal checkout is customer-initiated and can redirect to
 * a hosted page; a renewal fires from cron with nobody watching, so the gateway must be
 * able to reuse a stored payment method.
 *
 * Gateways that cannot do this simply do not implement the interface, and the
 * subscription billing engine falls back to sending a payment link.
 */
interface ChargesSavedMethods
{
    /**
     * Charge a stored payment method off-session.
     *
     * @param  array<string, mixed>  $payload  extra context (e.g. idempotency key, customer ref)
     */
    public function chargeStored(Order $order, string $reference, array $payload = []): SavedChargeResult;

    /**
     * Whether a stored reference is usable for this gateway (not expired/revoked).
     */
    public function storedReferenceIsUsable(?string $reference): bool;
}
