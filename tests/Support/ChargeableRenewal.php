<?php

namespace Tests\Support;

use App\Domain\Order\Models\Order;
use App\Domain\Payment\CheckoutLifecycle;
use App\Domain\Payment\Contracts\ChargesSavedMethods;
use App\Domain\Payment\Contracts\PaymentGateway;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Payment\Models\SavedPaymentMethod;
use App\Domain\Payment\PaymentCaptureResult;
use App\Domain\Payment\PaymentGatewayRegistry;
use App\Domain\Payment\SavedChargeResult;
use App\Domain\Settings\ShopSetting;
use App\Domain\Subscription\Models\CustomerSubscription;

/**
 * Gives a subscription a saved card the billing engine can charge.
 */
final class ChargeableRenewal
{
    public static function attach(CustomerSubscription $sub): void
    {
        ShopSetting::put('payments.charge_saved_methods', true);

        $method = PaymentMethod::query()->create([
            'code' => 'FAKE-'.uniqid(),
            'name' => 'Fake Recurring',
            'driver' => 'fake-recurring',
            'is_enabled' => true,
        ]);
        $sub->order?->updateTrusted(['payment_method_id' => $method->id]);

        app(PaymentGatewayRegistry::class)->register(new class implements ChargesSavedMethods, PaymentGateway
        {
            public function driver(): string
            {
                return 'fake-recurring';
            }

            public function checkoutLifecycle(): CheckoutLifecycle
            {
                return CheckoutLifecycle::OrderFirst;
            }

            public function capture(Order $order, array $payload = []): PaymentCaptureResult
            {
                return PaymentCaptureResult::pending();
            }

            public function supportsTransactionalCapture(): bool
            {
                return false;
            }

            public function storedReferenceIsUsable(?string $reference): bool
            {
                return $reference !== null && $reference !== '';
            }

            public function chargeStored(Order $order, string $reference, array $payload = []): SavedChargeResult
            {
                return SavedChargeResult::paid(['fake' => true]);
            }
        });

        SavedPaymentMethod::query()->create([
            'customer_id' => $sub->customer_id,
            'driver' => 'fake-recurring',
            'reference' => 'pm_saved_ok',
            'brand' => 'visa',
            'last4' => '4242',
            'is_default' => true,
        ]);
    }
}
