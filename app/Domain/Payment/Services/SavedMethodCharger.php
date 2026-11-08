<?php

namespace App\Domain\Payment\Services;

use App\Domain\Order\Models\Order;
use App\Domain\Payment\Contracts\ChargesSavedMethods;
use App\Domain\Payment\Models\SavedPaymentMethod;
use App\Domain\Payment\PaymentGatewayRegistry;
use App\Domain\Payment\SavedChargeResult;
use App\Domain\Settings\ShopSetting;

/**
 * Charges a customer again without them being present (subscription renewals).
 *
 * Three things this must get right:
 *
 *  1. Only gateways that declare the capability are used — otherwise we would silently
 *     "succeed" without money moving, which is exactly the bug this replaces.
 *  2. Every attempt carries a stable idempotency key, so a timeout/retry cannot
 *     double-charge the customer.
 *  3. A bank challenge (SCA/3DS) is a *deferred* outcome, not a failure: the customer
 *     gets a link to complete it, and the subscription stays recoverable.
 */
class SavedMethodCharger
{
    public function __construct(
        protected PaymentGatewayRegistry $gateways,
    ) {}

    /**
     * Is off-session charging available for this order's gateway?
     */
    public function canCharge(Order $order): bool
    {
        $driver = $this->driverFor($order);

        return $driver !== null
            && $this->gateways->has($driver)
            && $this->gateways->get($driver) instanceof ChargesSavedMethods;
    }

    /**
     * The customer's usable saved method for this order's gateway, if any.
     */
    public function methodFor(Order $order): ?SavedPaymentMethod
    {
        $driver = $this->driverFor($order);
        if ($driver === null || ! $order->customer_id) {
            return null;
        }

        return SavedPaymentMethod::query()
            ->where('customer_id', $order->customer_id)
            ->where('driver', $driver)
            ->whereNull('revoked_at')
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get()
            ->first(fn(SavedPaymentMethod $m) => $m->isUsable());
    }

    /**
     * Attempt the off-session charge.
     *
     * Returns null when the capability or a saved method is unavailable, so the caller
     * can fall back to a payment link rather than reporting a spurious failure.
     */
    public function charge(Order $order, string $idempotencyKey): ?SavedChargeResult
    {
        if (! $this->canCharge($order)) {
            return null;
        }

        $method = $this->methodFor($order);
        if (! $method) {
            return null;
        }

        $driver = $this->driverFor($order);
        $gateway = $this->gateways->get($driver);
        if (! $gateway instanceof ChargesSavedMethods) {
            return null;
        }

        if (! $gateway->storedReferenceIsUsable($method->reference)) {
            $method->revoke();

            return null;
        }

        try {
            $result = $gateway->chargeStored($order, $method->reference, [
                'idempotency_key' => $idempotencyKey,
                'customer_ref' => $method->gateway_customer_ref,
                'amount' => (float) $order->grand_total,
                'currency' => (string) $order->currency_code,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return SavedChargeResult::failed('The payment gateway could not be reached.');
        }

        if ($result->needsCustomerAction() && $result->redirectUrl) {
            $method->forceFill(['pending_action_url' => $result->redirectUrl])->save();
        }

        return $result;
    }

    /**
     * Stable per-attempt key: same subscription + same due date + same attempt number
     * must always produce the same key, so a cron retry cannot charge twice.
     */
    public function idempotencyKey(string $scope, int|string $id, string $periodKey, int $attempt = 1): string
    {
        return substr(hash('sha256', $scope . '|' . $id . '|' . $periodKey . '|' . $attempt), 0, 40);
    }

    protected function driverFor(Order $order): ?string
    {
        $order->loadMissing('paymentMethod');
        $driver = strtolower(trim((string) ($order->paymentMethod?->driver ?: '')));

        return $driver === '' ? null : $driver;
    }

    /**
     * Whether the store is allowed to charge saved cards at all. Merchants that only
     * take manual/offline payments must not have money taken automatically.
     */
    public static function enabled(): bool
    {
        return (bool) ShopSetting::get('payments.charge_saved_methods', true);
    }
}
