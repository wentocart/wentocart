<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerEarning;
use App\Domain\Marketplace\Models\SellerOrder;
use App\Domain\Marketplace\Models\SellerWithdrawal;
use App\Domain\Order\Models\Order;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 11 — configurable pending → available → paid earning lifecycle.
 *
 * Ledger credits are posted when earnings become available (not at payment capture).
 */
class SellerEarningAvailabilityService
{
    public const MODE_ON_PAYMENT = 'on_payment';

    public const MODE_ON_DELIVERY = 'on_delivery';

    public const MODE_AFTER_RETURN_WINDOW = 'after_return_window';

    public function handleOrderPaid(Order $order): void
    {
        if (MarketplaceSettings::earningReleaseMode() !== self::MODE_ON_PAYMENT) {
            return;
        }

        SellerOrder::query()
            ->where('order_id', $order->id)
            ->get()
            ->each(fn (SellerOrder $sellerOrder) => $this->releaseSellerOrder($sellerOrder));
    }

    public function handleSellerOrderDelivered(SellerOrder $sellerOrder, CarbonInterface $deliveredAt): void
    {
        $sellerOrder->refresh();
        if (! $this->canSchedule($sellerOrder)) {
            return;
        }

        $mode = MarketplaceSettings::earningReleaseMode();
        if ($mode === self::MODE_ON_DELIVERY) {
            $this->releaseSellerOrder($sellerOrder);

            return;
        }

        if ($mode === self::MODE_AFTER_RETURN_WINDOW) {
            $availableAt = Carbon::parse($deliveredAt)->addDays(MarketplaceSettings::returnWindowDays());
            $this->scheduleRelease($sellerOrder, $availableAt);
        }
    }

    public function scheduleRelease(SellerOrder $sellerOrder, CarbonInterface $availableAt): void
    {
        if (! $this->canSchedule($sellerOrder)) {
            return;
        }

        DB::transaction(function () use ($sellerOrder, $availableAt) {
            $locked = SellerOrder::query()->whereKey($sellerOrder->id)->lockForUpdate()->firstOrFail();
            if (! $this->canSchedule($locked)) {
                return;
            }

            SellerEarning::query()
                ->where('seller_order_id', $locked->id)
                ->where('status', SellerEarning::STATUS_PENDING)
                ->update(['available_at' => $availableAt]);

            if ($availableAt->lte(now())) {
                $this->releaseSellerOrder($locked);
            }
        });
    }

    /**
     * Release pending earnings for one seller order and credit the ledger (idempotent).
     */
    public function releaseSellerOrder(SellerOrder $sellerOrder): bool
    {
        if (! $this->canRelease($sellerOrder)) {
            return false;
        }

        return DB::transaction(function () use ($sellerOrder) {
            $locked = SellerOrder::query()->whereKey($sellerOrder->id)->lockForUpdate()->firstOrFail();
            if (! $this->canRelease($locked)) {
                return false;
            }

            $pending = SellerEarning::query()
                ->where('seller_order_id', $locked->id)
                ->where('status', SellerEarning::STATUS_PENDING)
                ->lockForUpdate()
                ->get();

            $now = now();
            if ($pending->isNotEmpty()) {
                foreach ($pending as $earning) {
                    if ($earning->remainingNet() <= 0.009) {
                        continue;
                    }
                    if ($earning->available_at && $earning->available_at->isFuture()) {
                        continue;
                    }

                    $earning->update([
                        'status' => SellerEarning::STATUS_AVAILABLE,
                        'released_at' => $now,
                        'available_at' => $earning->available_at ?? $now,
                    ]);
                }
            }

            $hasAvailable = SellerEarning::query()
                ->where('seller_order_id', $locked->id)
                ->where('status', SellerEarning::STATUS_AVAILABLE)
                ->whereRaw('(net_amount - refunded_net - paid_net) > 0.009')
                ->exists();

            if ($hasAvailable) {
                app(SellerLedgerService::class)->creditOrderEarning($locked->fresh());
            }

            Log::info('marketplace.earning.released', [
                'seller_order_id' => $locked->id,
                'seller_id' => $locked->seller_id,
                'order_id' => $locked->order_id,
            ]);

            return true;
        });
    }

    /**
     * Release all pending earnings whose available_at has passed.
     */
    public function releaseDueEarnings(): int
    {
        $sellerOrderIds = SellerEarning::query()
            ->where('status', SellerEarning::STATUS_PENDING)
            ->whereNotNull('available_at')
            ->where('available_at', '<=', now())
            ->distinct()
            ->pluck('seller_order_id')
            ->filter()
            ->values();

        if ($sellerOrderIds->isEmpty()) {
            return 0;
        }

        $sellerOrders = SellerOrder::query()
            ->whereIn('id', $sellerOrderIds)
            ->get()
            ->keyBy('id');

        $released = 0;
        foreach ($sellerOrderIds as $sellerOrderId) {
            $sellerOrder = $sellerOrders->get($sellerOrderId);
            if ($sellerOrder && $this->releaseSellerOrder($sellerOrder)) {
                $released++;
            }
        }

        return $released;
    }

    /**
     * @return array{
     *   pending: float,
     *   available: float,
     *   paid: float,
     *   held_payouts: float,
     *   available_for_payout: float,
     *   ledger_balance: float
     * }
     */
    public function walletBalances(Seller $seller): array
    {
        $buckets = app(MarketplaceStatsService::class)->earningRemainingByStatus($seller);
        $pending = $buckets['pending'];
        $available = $buckets['available'];
        $paid = $buckets['paid'];

        $held = round((float) $seller->withdrawals()
            ->whereIn('status', SellerWithdrawal::HOLDING_STATUSES)
            ->sum('amount'), 2);

        $ledgerRow = $seller->ledgerEntries()
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN direction = ? THEN amount ELSE 0 END), 0) as credits, COALESCE(SUM(CASE WHEN direction = ? THEN amount ELSE 0 END), 0) as debits",
                [\App\Domain\Marketplace\Models\SellerLedgerEntry::DIRECTION_CREDIT, \App\Domain\Marketplace\Models\SellerLedgerEntry::DIRECTION_DEBIT]
            )
            ->first();
        $ledgerBalance = round((float) ($ledgerRow->credits ?? 0) - (float) ($ledgerRow->debits ?? 0), 2);

        return [
            'pending' => $pending,
            'available' => $available,
            'paid' => $paid,
            'held_payouts' => $held,
            'available_for_payout' => round(max(0, min($available, $ledgerBalance) - $held), 2),
            'ledger_balance' => $ledgerBalance,
        ];
    }

    public function availableForPayout(Seller $seller): float
    {
        return $this->walletBalances($seller)['available_for_payout'];
    }

    protected function canSchedule(SellerOrder $sellerOrder): bool
    {
        if (in_array($sellerOrder->status, ['cancelled', 'refunded'], true)) {
            return false;
        }

        if (! in_array($sellerOrder->payment_status, ['paid', 'partial'], true)) {
            return false;
        }

        return $this->hasReleasableEarnings($sellerOrder);
    }

    protected function canRelease(SellerOrder $sellerOrder): bool
    {
        if (! $this->canSchedule($sellerOrder)) {
            return false;
        }

        $mode = MarketplaceSettings::earningReleaseMode();

        if ($mode === self::MODE_ON_DELIVERY && $sellerOrder->status !== 'completed') {
            return false;
        }

        if ($mode === self::MODE_AFTER_RETURN_WINDOW) {
            $pending = SellerEarning::query()
                ->where('seller_order_id', $sellerOrder->id)
                ->where('status', SellerEarning::STATUS_PENDING);

            if ((clone $pending)->whereNull('available_at')->exists()) {
                return false;
            }

            if ((clone $pending)->where('available_at', '>', now())->exists()) {
                return false;
            }
        }

        return true;
    }

    protected function hasReleasableEarnings(SellerOrder $sellerOrder): bool
    {
        return SellerEarning::query()
            ->where('seller_order_id', $sellerOrder->id)
            ->whereIn('status', [SellerEarning::STATUS_PENDING, SellerEarning::STATUS_AVAILABLE])
            ->get()
            ->contains(fn (SellerEarning $earning) => $earning->remainingNet() > 0.009);
    }
}
