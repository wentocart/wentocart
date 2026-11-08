<?php

namespace App\Domain\Customer\Services;

use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Payment\Models\PaymentMethod;
use Illuminate\Validation\ValidationException;

class NetTerms
{
    public static function isInvoice(PaymentMethod $method): bool
    {
        if ($method->code === 'invoice') {
            return true;
        }
        $config = is_array($method->config) ? $method->config : [];

        return ! empty($config['net_terms']);
    }

    public static function days(?Customer $customer): int
    {
        if (! $customer) {
            return 0;
        }
        $customer->loadMissing('company');

        return (int) ($customer->company?->net_days ?? 0);
    }

    public static function eligible(?Customer $customer): bool
    {
        return self::days($customer) > 0;
    }

    public static function creditLimit(?Customer $customer): float
    {
        if (! $customer) {
            return 0;
        }
        $customer->loadMissing('company');
        if ($customer->company_id && $customer->company) {
            return (float) $customer->company->credit_limit;
        }

        return (float) ($customer->credit_limit ?? 0);
    }

    public static function outstanding(?Customer $customer): float
    {
        if (! $customer) {
            return 0;
        }

        return (float) Order::query()
            ->where('customer_id', $customer->id)
            ->whereIn('payment_status', ['pending', 'partial', 'partially_refunded'])
            ->sum('grand_total');
    }

    public static function assertCanCharge(?Customer $customer, float $amount): void
    {
        if (! self::eligible($customer)) {
            throw ValidationException::withMessages([
                'payment' => 'Net terms are not available for this account.',
            ]);
        }
        $limit = self::creditLimit($customer);
        if ($limit <= 0) {
            return;
        }
        if (self::outstanding($customer) + $amount - 0.009 > $limit) {
            throw ValidationException::withMessages([
                'payment' => 'This order exceeds the account credit limit.',
            ]);
        }
    }
}
