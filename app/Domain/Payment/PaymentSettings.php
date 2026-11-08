<?php

namespace App\Domain\Payment;

use App\Domain\Order\Models\Order;
use App\Domain\Settings\ShopSetting;
use Illuminate\Support\Facades\URL;

final class PaymentSettings
{
    public static function sandboxPay(): bool
    {
        if (app()->environment('production')) {
            return false;
        }

        return ShopSetting::bool('payments.sandbox_pay', true);
    }

    public static function sandboxPayUrl(string $driver, Order $order, string $route = 'pay.show'): string
    {
        return URL::temporarySignedRoute($route, now()->addHours(12), [
            'driver' => strtolower(trim($driver)),
            'number' => $order->number,
        ]);
    }

    public static function sandboxCapture(string $driver, Order $order, string $missingKeysMessage): PaymentCaptureResult
    {
        if (self::sandboxPay()) {
            return PaymentCaptureResult::redirect(self::sandboxPayUrl($driver, $order), ['sandbox' => true]);
        }

        return PaymentCaptureResult::failed($missingKeysMessage);
    }

    public static function paypalClientId(?array $methodConfig = null): string
    {
        return trim((string) ($methodConfig['client_id'] ?? ShopSetting::get('payments.paypal.client_id', '')));
    }

    public static function paypalSecret(?array $methodConfig = null): string
    {
        return trim((string) ($methodConfig['secret'] ?? ShopSetting::get('payments.paypal.secret', '')));
    }

    public static function paypalSandbox(?array $methodConfig = null): bool
    {
        if (array_key_exists('sandbox', $methodConfig ?? [])) {
            return (bool) $methodConfig['sandbox'];
        }

        return ShopSetting::bool('payments.paypal.sandbox', ! app()->environment('production'));
    }

    public static function razorpayKey(?array $methodConfig = null): string
    {
        return trim((string) ($methodConfig['key_id'] ?? ShopSetting::get('payments.razorpay.key_id', '')));
    }

    public static function razorpaySecret(?array $methodConfig = null): string
    {
        return trim((string) ($methodConfig['key_secret'] ?? ShopSetting::get('payments.razorpay.key_secret', '')));
    }
}
