<?php

namespace App\Domain\Customer\Services;

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerCredit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StoreCreditService
{
    public function balance(Customer $customer): float
    {
        $last = CustomerCredit::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('id')
            ->value('balance');

        return round((float) ($last ?? 0), 2);
    }

    public function credit(Customer $customer, float $amount, string $reason, ?int $orderId = null, ?int $adminId = null): CustomerCredit
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['credit' => 'Credit amount must be greater than zero.']);
        }

        return DB::transaction(function () use ($customer, $amount, $reason, $orderId, $adminId) {
            // Serialize per-customer ledger writes so concurrent checkouts
            // cannot compute the same running balance twice.
            Customer::query()->whereKey($customer->id)->lockForUpdate()->first();
            $balance = round($this->balance($customer) + $amount, 2);

            return CustomerCredit::query()->create([
                'customer_id' => $customer->id,
                'amount' => $amount,
                'balance' => $balance,
                'reason' => $reason,
                'order_id' => $orderId,
                'admin_id' => $adminId,
            ]);
        });
    }

    public function debit(Customer $customer, float $amount, string $reason, ?int $orderId = null): CustomerCredit
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['credit' => 'Debit amount must be greater than zero.']);
        }

        return DB::transaction(function () use ($customer, $amount, $reason, $orderId) {
            // Serialize per-customer ledger writes so two simultaneous
            // debits cannot both pass the balance check.
            Customer::query()->whereKey($customer->id)->lockForUpdate()->first();
            $current = $this->balance($customer);
            if ($amount - 0.009 > $current) {
                throw ValidationException::withMessages(['credit' => 'Store credit balance is too low.']);
            }
            $balance = round($current - $amount, 2);

            return CustomerCredit::query()->create([
                'customer_id' => $customer->id,
                'amount' => -$amount,
                'balance' => $balance,
                'reason' => $reason,
                'order_id' => $orderId,
            ]);
        });
    }
}
