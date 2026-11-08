<?php

namespace App\Domain\Settings;

final class ReturnSettings
{
    /** @var list<string> */
    public const APPROVAL_OPTIONS = ['required', 'automatic'];

    /** @var list<string> */
    public const REFUND_OPTIONS = ['credit_slip', 'voucher'];

    /** @var list<string> */
    public const SHIPPING_COST_OPTIONS = ['yes', 'no'];

    /** @var list<string> */
    public const RESTOCK_OPTIONS = ['automatic', 'manual'];

    public static function enabled(): bool
    {
        return ShopSetting::bool('returns.enabled', true);
    }

    public static function windowDays(): int
    {
        $value = (int) ShopSetting::get('returns.window_days', 14);

        return max(0, min(365, $value));
    }

    public static function reasonRequired(): bool
    {
        return ShopSetting::bool('returns.reason_required', true);
    }

    public static function approval(): string
    {
        $raw = trim((string) ShopSetting::get('returns.approval', 'required'));

        return in_array($raw, self::APPROVAL_OPTIONS, true) ? $raw : 'required';
    }

    public static function partialReturns(): bool
    {
        return ShopSetting::bool('returns.partial', true);
    }

    public static function canonicalRefundMethod(string $raw): string
    {
        return match (strtolower(trim($raw))) {
            'voucher', 'store_credit', 'configurable' => 'voucher',
            default => 'credit_slip',
        };
    }

    public static function refundMethod(): string
    {
        return self::canonicalRefundMethod((string) ShopSetting::get('returns.refund_method', 'credit_slip'));
    }

    public static function canonicalRefundShipping(string $raw): string
    {
        return strtolower(trim($raw)) === 'yes' ? 'yes' : 'no';
    }

    public static function refundShipping(): string
    {
        return self::canonicalRefundShipping((string) ShopSetting::get('returns.refund_shipping', 'no'));
    }

    public static function restock(): string
    {
        $raw = trim((string) ShopSetting::get('returns.restock', 'automatic'));

        return in_array($raw, self::RESTOCK_OPTIONS, true) ? $raw : 'automatic';
    }

    public static function restockingFee(): bool
    {
        return ShopSetting::bool('returns.restocking_fee', false);
    }

    public static function restockingFeePercent(): float
    {
        if (! self::restockingFee()) {
            return 0.0;
        }

        $value = (float) ShopSetting::get('returns.restocking_fee_percent', 10);

        return max(0, min(100, $value));
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(): array
    {
        return [
            'enabled' => self::enabled(),
            'window_days' => self::windowDays(),
            'reason_required' => self::reasonRequired(),
            'approval' => self::approval(),
            'partial' => self::partialReturns(),
            'refund_method' => self::refundMethod(),
            'refund_shipping' => self::refundShipping(),
            'restock' => self::restock(),
            'restocking_fee' => self::restockingFee(),
            'restocking_fee_percent' => self::restockingFee()
                ? self::restockingFeePercent()
                : (float) ShopSetting::get('returns.restocking_fee_percent', 10),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function storefrontPayload(): array
    {
        return [
            'enabled' => self::enabled(),
            'window_days' => self::windowDays(),
            'reason_required' => self::reasonRequired(),
            'partial' => self::partialReturns(),
        ];
    }
}
