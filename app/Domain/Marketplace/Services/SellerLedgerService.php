<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerLedgerEntry;
use App\Domain\Marketplace\Models\SellerEarning;
use App\Domain\Marketplace\Models\SellerOrder;
use App\Domain\Marketplace\Models\SellerWithdrawal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Append-only seller ledger (Phase 10).
 *
 * Balance = sum(credits) − sum(debits).
 * Available for payout = balance − amounts held in pending/approved withdrawals.
 *
 * Credits: order net earnings (commission already deducted from amount; meta stores commission).
 * Debits: refund adjustments, paid payouts.
 */
class SellerLedgerService
{
    public function balance(Seller $seller): float
    {
        $credits = (float) $seller->ledgerEntries()
            ->where('direction', SellerLedgerEntry::DIRECTION_CREDIT)
            ->sum('amount');
        $debits = (float) $seller->ledgerEntries()
            ->where('direction', SellerLedgerEntry::DIRECTION_DEBIT)
            ->sum('amount');

        return round($credits - $debits, 2);
    }

    /**
     * Funds withdrawable now = available earnings minus pending/approved payout holds.
     */
    public function availableBalance(Seller $seller): float
    {
        return app(SellerEarningAvailabilityService::class)->availableForPayout($seller);
    }

    /**
     * @return array{
     *   balance: float,
     *   available: float,
     *   pending: float,
     *   paid: float,
     *   credits: float,
     *   debits: float,
     *   held_payouts: float,
     *   entry_count: int
     * }
     */
    public function reconcile(Seller $seller): array
    {
        $credits = round((float) $seller->ledgerEntries()
            ->where('direction', SellerLedgerEntry::DIRECTION_CREDIT)
            ->sum('amount'), 2);
        $debits = round((float) $seller->ledgerEntries()
            ->where('direction', SellerLedgerEntry::DIRECTION_DEBIT)
            ->sum('amount'), 2);
        $wallet = app(SellerEarningAvailabilityService::class)->walletBalances($seller);
        $balance = round($credits - $debits, 2);

        return [
            'balance' => $balance,
            'available' => $wallet['available_for_payout'],
            'pending' => $wallet['pending'],
            'paid' => $wallet['paid'],
            'credits' => $credits,
            'debits' => $debits,
            'held_payouts' => $wallet['held_payouts'],
            'entry_count' => (int) $seller->ledgerEntries()->count(),
        ];
    }

    /**
     * Recognize net seller earning when parent payment is paid/captured.
     * Idempotent via unique reference.
     */
    public function creditOrderEarning(SellerOrder $sellerOrder): ?SellerLedgerEntry
    {
        $sellerOrder->loadMissing(['seller', 'earnings']);
        $reference = $this->earningReference($sellerOrder);

        $net = round(
            $sellerOrder->earnings
                ->where('status', SellerEarning::STATUS_AVAILABLE)
                ->sum(fn (SellerEarning $earning) => $earning->remainingNet()),
            2
        );
        if ($net <= 0) {
            return null;
        }

        $existing = SellerLedgerEntry::query()->where('reference', $reference)->first();
        if ($existing) {
            $delta = round($net - (float) $existing->amount, 2);
            if (abs($delta) < 0.001) {
                return $existing;
            }
            if ($delta < 0) {
                return $existing;
            }

            return $this->post(
                seller: $sellerOrder->seller,
                entryType: SellerLedgerEntry::TYPE_ORDER_EARNING,
                direction: SellerLedgerEntry::DIRECTION_CREDIT,
                amount: $delta,
                reference: $reference.':adjustment:'.(int) $existing->id,
                description: 'Additional seller net earning for order '.$sellerOrder->number,
                sellerOrder: $sellerOrder,
                meta: [
                    'commission_total' => (float) $sellerOrder->commission_total,
                    'incremental' => true,
                    'reverses_reference' => $reference,
                ],
            );
        }

        return $this->post(
            seller: $sellerOrder->seller,
            entryType: SellerLedgerEntry::TYPE_ORDER_EARNING,
            direction: SellerLedgerEntry::DIRECTION_CREDIT,
            amount: $net,
            reference: $reference,
            description: 'Seller net earning for order '.$sellerOrder->number,
            sellerOrder: $sellerOrder,
            meta: [
                'commission_total' => (float) $sellerOrder->commission_total,
                'commission_rate' => $sellerOrder->commission_rate !== null
                    ? (float) $sellerOrder->commission_rate
                    : null,
                'commission_type' => $sellerOrder->commission_type,
                'gross' => round((float) $sellerOrder->subtotal - (float) $sellerOrder->discount_total, 2),
            ],
        );
    }

    /**
     * Compensating debit when a paid order is refunded.
     */
    public function debitRefundAdjustment(SellerOrder $sellerOrder): ?SellerLedgerEntry
    {
        return $this->debitReversal(
            $sellerOrder,
            SellerLedgerEntry::TYPE_REFUND_ADJUSTMENT,
            'refund_adjustment:seller_order:'.$sellerOrder->id,
            'Refund adjustment for order '.$sellerOrder->number,
        );
    }

    /**
     * Compensating debit for a completed marketplace return (partial or full slice).
     * Idempotent via return reference. Amount = seller_net_reversed on the return.
     */
    public function debitReturnAdjustment(\App\Domain\Marketplace\Models\SellerReturn $return): ?SellerLedgerEntry
    {
        $return->loadMissing(['seller', 'sellerOrder']);
        $seller = $return->seller;
        $sellerOrder = $return->sellerOrder;
        if (! $seller || ! $sellerOrder) {
            return null;
        }

        $creditRef = $this->earningReference($sellerOrder);
        $credit = SellerLedgerEntry::query()->where('reference', $creditRef)->first();
        if (! $credit) {
            return null;
        }

        return $this->post(
            seller: $seller,
            entryType: SellerLedgerEntry::TYPE_REFUND_ADJUSTMENT,
            direction: SellerLedgerEntry::DIRECTION_DEBIT,
            amount: (float) $return->seller_net_reversed,
            reference: 'refund_adjustment:return:'.$return->id,
            description: 'Return refund '.$return->number,
            sellerOrder: $sellerOrder,
            meta: [
                'seller_return_id' => (int) $return->id,
                'reverses' => $creditRef,
                'commission_reversed' => (float) $return->commission_reversed,
            ],
        );
    }

    /**
     * Partial cancel/refund debit with an explicit amount and unique reference.
     */
    public function debitPartialAdjustment(
        SellerOrder $sellerOrder,
        float $amount,
        string $reference,
        string $entryType,
        string $description,
    ): ?SellerLedgerEntry {
        $sellerOrder->loadMissing('seller');
        $creditRef = $this->earningReference($sellerOrder);
        if (! SellerLedgerEntry::query()->where('reference', $creditRef)->exists()) {
            return null;
        }

        return $this->post(
            seller: $sellerOrder->seller,
            entryType: $entryType,
            direction: SellerLedgerEntry::DIRECTION_DEBIT,
            amount: $amount,
            reference: $reference,
            description: $description,
            sellerOrder: $sellerOrder,
            meta: ['reverses' => $creditRef, 'partial' => true],
        );
    }

    /**
     * Compensating debit when a seller cancels after ledger recognition.
     * Idempotent. No-op when no prior earning credit exists (unpaid / never recognized).
     */
    public function debitCancelAdjustment(SellerOrder $sellerOrder): ?SellerLedgerEntry
    {
        return $this->debitReversal(
            $sellerOrder,
            SellerLedgerEntry::TYPE_CANCEL_ADJUSTMENT,
            'cancel_adjustment:seller_order:'.$sellerOrder->id,
            'Cancel adjustment for order '.$sellerOrder->number,
        );
    }

    protected function debitReversal(
        SellerOrder $sellerOrder,
        string $entryType,
        string $reference,
        string $description,
    ): ?SellerLedgerEntry {
        $sellerOrder->loadMissing('seller');
        $creditRef = $this->earningReference($sellerOrder);
        $credit = SellerLedgerEntry::query()->where('reference', $creditRef)->first();
        if (! $credit) {
            return null;
        }

        $existingReverse = SellerLedgerEntry::query()
            ->where('reference', $reference)
            ->first();
        if ($existingReverse) {
            return $existingReverse;
        }

        $remaining = $this->unreversedCreditAmount($sellerOrder, $credit);
        if ($remaining <= 0) {
            return null;
        }

        return $this->post(
            seller: $sellerOrder->seller,
            entryType: $entryType,
            direction: SellerLedgerEntry::DIRECTION_DEBIT,
            amount: $remaining,
            reference: $reference,
            description: $description,
            sellerOrder: $sellerOrder,
            meta: ['reverses' => $creditRef],
        );
    }

    protected function unreversedCreditAmount(SellerOrder $sellerOrder, SellerLedgerEntry $credit): float
    {
        $debited = round((float) SellerLedgerEntry::query()
            ->where('seller_order_id', $sellerOrder->id)
            ->where('direction', SellerLedgerEntry::DIRECTION_DEBIT)
            ->whereIn('entry_type', [
                SellerLedgerEntry::TYPE_REFUND_ADJUSTMENT,
                SellerLedgerEntry::TYPE_CANCEL_ADJUSTMENT,
            ])
            ->sum('amount'), 2);

        return round(max(0, (float) $credit->amount - $debited), 2);
    }

    /**
     * Consume ledger balance when a payout is marked paid.
     * Idempotent via unique reference payout:withdrawal:{id}.
     *
     * @param  list<int>  $earningIds  Seller earning rows this payout consumed (Phase 12).
     */
    public function debitPayout(SellerWithdrawal $withdrawal, array $earningIds = []): ?SellerLedgerEntry
    {
        $withdrawal->loadMissing('seller');
        $seller = $withdrawal->seller;
        if (! $seller) {
            return null;
        }

        return $this->post(
            seller: $seller,
            entryType: SellerLedgerEntry::TYPE_PAYOUT,
            direction: SellerLedgerEntry::DIRECTION_DEBIT,
            amount: (float) $withdrawal->amount,
            reference: $this->payoutReference($withdrawal),
            description: 'Payout #'.$withdrawal->id,
            sellerOrder: null,
            sellerWithdrawal: $withdrawal,
            meta: [
                'seller_withdrawal_id' => (int) $withdrawal->id,
                'status' => SellerWithdrawal::STATUS_PAID,
                'earning_ids' => array_values(array_map('intval', $earningIds)),
            ],
        );
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    protected function post(
        ?Seller $seller,
        string $entryType,
        string $direction,
        float $amount,
        string $reference,
        string $description,
        ?SellerOrder $sellerOrder = null,
        ?SellerWithdrawal $sellerWithdrawal = null,
        ?array $meta = null,
    ): ?SellerLedgerEntry {
        if (! $seller) {
            Log::warning('marketplace.ledger.missing_seller', [
                'reference' => $reference,
                'entry_type' => $entryType,
            ]);

            return null;
        }

        $amount = round(abs($amount), 2);
        if ($amount <= 0) {
            return null;
        }

        $existing = SellerLedgerEntry::query()->where('reference', $reference)->first();
        if ($existing) {
            return $existing;
        }

        try {
            return DB::transaction(function () use (
                $seller,
                $sellerOrder,
                $sellerWithdrawal,
                $entryType,
                $direction,
                $amount,
                $reference,
                $description,
                $meta
            ) {
                $locked = SellerLedgerEntry::query()->where('reference', $reference)->lockForUpdate()->first();
                if ($locked) {
                    return $locked;
                }

                return SellerLedgerEntry::query()->create([
                    'seller_id' => $seller->id,
                    'seller_order_id' => $sellerOrder?->id,
                    'order_id' => $sellerOrder?->order_id,
                    'seller_earning_id' => null,
                    'seller_withdrawal_id' => $sellerWithdrawal?->id,
                    'entry_type' => $entryType,
                    'direction' => $direction,
                    'amount' => $amount,
                    'currency_code' => $sellerOrder?->currency_code ?: 'USD',
                    'reference' => $reference,
                    'description' => $description,
                    'meta' => $meta,
                ]);
            });
        } catch (\Throwable $e) {
            $again = SellerLedgerEntry::query()->where('reference', $reference)->first();
            if ($again) {
                return $again;
            }
            Log::warning('marketplace.ledger.post_failed', [
                'seller_id' => $seller->id,
                'reference' => $reference,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    protected function earningReference(SellerOrder $sellerOrder): string
    {
        return 'order_earning:seller_order:'.$sellerOrder->id;
    }

    protected function payoutReference(SellerWithdrawal $withdrawal): string
    {
        return 'payout:withdrawal:'.$withdrawal->id;
    }
}
