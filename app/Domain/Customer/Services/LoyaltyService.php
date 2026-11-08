<?php

namespace App\Domain\Customer\Services;

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\LoyaltyMovement;
use App\Domain\Order\Models\Order;
use App\Domain\Settings\LoyaltySettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LoyaltyService
{
    public function balance(Customer $customer): int
    {
        if (! Schema::hasTable('loyalty_movements')) {
            return 0;
        }

        return (int) LoyaltyMovement::query()->where('customer_id', $customer->id)->sum('points');
    }

    public function earn(Customer $customer, Order $order): void
    {
        if (! LoyaltySettings::enabled()) {
            return;
        }
        $points = (int) floor((float) $order->grand_total * LoyaltySettings::earnPercent() / 100);
        if ($points < 1) {
            return;
        }
        $ref = 'earn:'.$order->number;
        if (LoyaltyMovement::query()->where('customer_id', $customer->id)->where('reference', $ref)->exists()) {
            return;
        }
        LoyaltyMovement::query()->create([
            'customer_id' => $customer->id,
            'points' => $points,
            'type' => LoyaltyMovement::TYPE_EARN,
            'reference' => $ref,
            'order_id' => $order->id,
        ]);
    }

    public function redeem(Customer $customer, int $points, string $reference, ?int $orderId = null): void
    {
        if ($points < 1) {
            return;
        }
        DB::transaction(function () use ($customer, $points, $reference, $orderId) {
            // Serialize per-customer redemption so concurrent checkouts
            // cannot both spend the same points.
            Customer::query()->whereKey($customer->id)->lockForUpdate()->first();
            if (LoyaltyMovement::query()->where('customer_id', $customer->id)->where('reference', $reference)->exists()) {
                return;
            }
            $available = $this->balance($customer);
            $use = min($points, $available);
            if ($use < 1) {
                return;
            }
            LoyaltyMovement::query()->create([
                'customer_id' => $customer->id,
                'points' => -$use,
                'type' => LoyaltyMovement::TYPE_REDEEM,
                'reference' => $reference,
                'order_id' => $orderId,
            ]);
        });
    }

    public function valueOf(int $points): float
    {
        return round(max(0, $points) * LoyaltySettings::pointValue(), 2);
    }

    public function pointsForAmount(float $amount): int
    {
        $value = LoyaltySettings::pointValue();
        if ($value <= 0) {
            return 0;
        }

        return (int) floor(max(0, $amount) / $value);
    }
}
