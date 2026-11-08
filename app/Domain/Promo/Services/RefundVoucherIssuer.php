<?php

namespace App\Domain\Promo\Services;

use App\Domain\Order\Models\Order;
use App\Domain\Promo\Models\DiscountRule;
use Illuminate\Support\Str;

class RefundVoucherIssuer
{
    /**
     * Create a one-use, customer-scoped amount-off coupon for a refund.
     * Appears on the customer My vouchers page and can be applied at checkout.
     */
    public function issue(Order $order, ?float $amount = null): DiscountRule
    {
        if (! $order->customer_id) {
            throw new \InvalidArgumentException('A customer account is required to issue a refund voucher.');
        }

        $value = round($amount ?? (float) $order->grand_total, 2);
        if ($value <= 0) {
            throw new \InvalidArgumentException('Refund voucher amount must be greater than zero.');
        }

        $currency = strtoupper((string) ($order->currency_code ?: 'INR'));
        $code = $this->uniqueCode($order);

        $rule = new DiscountRule([
            'name' => 'Refund voucher — order #'.$order->number,
            'apply_as' => 'coupon',
            'code' => $code,
            'action' => 'amount',
            'value' => $value,
            'currency_code' => $currency,
            'priority' => 10,
            'stop_further' => false,
            'min_cart' => null,
            'min_qty' => null,
            'max_discount' => null,
            'max_uses' => 1,
            'used_count' => 0,
            'starts_at' => null,
            'expires_at' => null,
            'is_enabled' => true,
        ]);
        $rule->save();

        $rule->conditions()->create([
            'type' => 'customer',
            'value_ids' => [(int) $order->customer_id],
        ]);

        return $rule->refresh()->load('conditions');
    }

    protected function uniqueCode(Order $order): string
    {
        $prefix = 'RFND-'.preg_replace('/\W+/', '', (string) $order->number);
        if ($prefix === 'RFND-') {
            $prefix = 'RFND-'.$order->id;
        }

        for ($i = 0; $i < 8; $i++) {
            $code = strtoupper($prefix.'-'.Str::upper(Str::random(4)));
            if (! DiscountRule::query()->where('code', $code)->exists()) {
                return $code;
            }
        }

        return strtoupper($prefix.'-'.Str::upper(Str::random(8)));
    }
}
