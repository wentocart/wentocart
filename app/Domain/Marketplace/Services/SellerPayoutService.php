<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerEarning;
use App\Domain\Marketplace\Models\SellerLedgerEntry;
use App\Domain\Marketplace\Models\SellerWithdrawal;
use App\Domain\Marketplace\Events\MarketplaceEvent;
use App\Domain\Marketplace\Services\MarketplaceLifecycleBroker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Phase 12 — seller payout lifecycle.
 *
 * REQUESTED → UNDER_REVIEW → PROCESSING → PAID
 *                 ↓               ↓
 *             REJECTED         FAILED
 *
 * Holding statuses reserve available balance. Ledger debit only on PAID.
 * FAILED / REJECTED release the hold without consuming ledger balance.
 */
class SellerPayoutService
{
    public function availablePayoutAmount(Seller $seller): float
    {
        $this->creditReleasedEarnings($seller);

        return app(SellerLedgerService::class)->availableBalance($seller);
    }

    /**
     * Available earnings are payable only after a ledger credit. Repair rows that
     * were marked available without that credit so the payout form matches the list.
     */
    protected function creditReleasedEarnings(Seller $seller): void
    {
        \App\Domain\Marketplace\Models\SellerOrder::query()
            ->where('seller_id', $seller->id)
            ->whereHas('earnings', fn ($query) => $query->where('status', SellerEarning::STATUS_AVAILABLE))
            ->get()
            ->each(fn (\App\Domain\Marketplace\Models\SellerOrder $order) => app(SellerLedgerService::class)->creditOrderEarning($order));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function request(Seller $seller, array $data): SellerWithdrawal
    {
        $amount = round((float) $data['amount'], 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Enter a payout amount greater than zero.']);
        }

        $methodId = ($data['seller_payment_method_id'] ?? '') === ''
            ? null
            : (int) $data['seller_payment_method_id'];
        if ($methodId) {
            $ownsMethod = $seller->paymentMethods()->whereKey($methodId)->exists();
            if (! $ownsMethod) {
                throw ValidationException::withMessages([
                    'seller_payment_method_id' => 'Choose a payout method that belongs to this seller.',
                ]);
            }
        }

        return DB::transaction(function () use ($seller, $data, $amount, $methodId) {
            Seller::query()->whereKey($seller->id)->lockForUpdate()->firstOrFail();
            $available = $this->availablePayoutAmount($seller);
            if ($amount > $available) {
                throw ValidationException::withMessages([
                    'amount' => 'Requested amount is more than available seller earnings ('
                        .number_format($available, 2)
                        .' available). Pending earnings stay unpaid until Marketplace settings release them.',
                ]);
            }

            $row = SellerWithdrawal::createTrusted([
                'seller_id' => $seller->id,
                'seller_payment_method_id' => $methodId,
                'amount' => $amount,
                'status' => SellerWithdrawal::STATUS_REQUESTED,
                'note' => $data['note'] ?? null,
            ]);

            Log::info('marketplace.payout.requested', [
                'seller_withdrawal_id' => $row->id,
                'seller_id' => $seller->id,
                'amount' => $amount,
            ]);

            $fresh = $row->fresh();
            app(MarketplaceLifecycleBroker::class)->dispatch(MarketplaceEvent::payoutRequested($fresh));

            return $fresh;
        });
    }

    /**
     * Admin / system transition. Actions: review, approve, reject, process, pay, fail, retry.
     * `approve` is an alias for `review`. `pay` may jump from requested/under_review for admin convenience.
     */
    public function process(SellerWithdrawal $row, string $action, ?int $adminId = null, ?string $note = null): SellerWithdrawal
    {
        $action = $action === 'approve' ? 'review' : $action;
        if (! in_array($action, ['review', 'reject', 'process', 'pay', 'fail', 'retry'], true)) {
            abort(422, 'Unknown payout action.');
        }

        $result = DB::transaction(function () use ($row, $action, $adminId, $note) {
            $locked = SellerWithdrawal::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
            Seller::query()->whereKey($locked->seller_id)->lockForUpdate()->firstOrFail();

            // Idempotent terminal: already paid.
            if ($action === 'pay' && $locked->status === SellerWithdrawal::STATUS_PAID) {
                return ['row' => $locked->fresh(['seller', 'paymentMethod', 'ledgerEntries']), 'event' => null];
            }
            if ($action === 'fail' && $locked->status === SellerWithdrawal::STATUS_FAILED) {
                return ['row' => $locked->fresh(['seller', 'paymentMethod']), 'event' => null];
            }
            if ($action === 'reject' && $locked->status === SellerWithdrawal::STATUS_REJECTED) {
                return ['row' => $locked->fresh(['seller', 'paymentMethod']), 'event' => null];
            }

            match ($action) {
                'review' => $this->transition($locked, SellerWithdrawal::STATUS_UNDER_REVIEW),
                'reject' => $this->reject($locked),
                'process' => $this->transition($locked, SellerWithdrawal::STATUS_PROCESSING),
                'pay' => $this->markPaid($locked),
                'fail' => $this->markFailed($locked),
                'retry' => $this->retryFailed($locked),
            };

            if ($note !== null && $note !== '') {
                $locked->note = $note;
            }
            $locked->processed_by = $adminId;
            $locked->processed_at = now();
            $locked->save();

            Log::info('marketplace.payout.'.$action, [
                'seller_withdrawal_id' => $locked->id,
                'seller_id' => $locked->seller_id,
                'status' => $locked->status,
                'admin_id' => $adminId,
            ]);

            $fresh = $locked->fresh(['seller', 'paymentMethod', 'ledgerEntries']);
            $event = match ($action) {
                'review' => MarketplaceEvent::payoutApproved($fresh, $adminId),
                'reject' => MarketplaceEvent::payoutRejected($fresh, $adminId, $note),
                'pay' => MarketplaceEvent::payoutPaid($fresh, $adminId),
                'fail' => MarketplaceEvent::payoutFailed($fresh, $adminId, $note),
                default => null,
            };

            return ['row' => $fresh, 'event' => $event];
        });

        if ($result['event'] ?? null) {
            app(MarketplaceLifecycleBroker::class)->dispatch($result['event']);
        }

        return $result['row'];
    }

    protected function transition(SellerWithdrawal $locked, string $to): void
    {
        if ($locked->status === $to) {
            return;
        }
        if (! $locked->canTransitionTo($to)) {
            abort(422, "Cannot change payout from {$locked->status} to {$to}.");
        }
        $locked->status = $to;
    }

    protected function reject(SellerWithdrawal $locked): void
    {
        if ($locked->status === SellerWithdrawal::STATUS_PAID) {
            abort(422, 'A paid payout cannot be rejected.');
        }
        if (! in_array($locked->status, [
            SellerWithdrawal::STATUS_REQUESTED,
            SellerWithdrawal::STATUS_UNDER_REVIEW,
        ], true)) {
            abort(422, 'Only requested or under-review payouts can be rejected.');
        }
        $locked->status = SellerWithdrawal::STATUS_REJECTED;
    }

    protected function markPaid(SellerWithdrawal $locked): void
    {
        if ($locked->status === SellerWithdrawal::STATUS_PAID) {
            return;
        }

        // Allow pay from requested / under_review / processing (admin shortcut to PAID).
        if (! in_array($locked->status, [
            SellerWithdrawal::STATUS_REQUESTED,
            SellerWithdrawal::STATUS_UNDER_REVIEW,
            SellerWithdrawal::STATUS_PROCESSING,
        ], true)) {
            abort(422, 'Only open payouts can be marked paid.');
        }

        $seller = $locked->seller;
        if (! $seller) {
            abort(422, 'Seller is missing for this payout.');
        }

        // Re-check that ledger still covers the payout (safety against races).
        $ledger = app(SellerLedgerService::class);
        $balance = $ledger->balance($seller);
        if ((float) $locked->amount > $balance + 0.001) {
            abort(422, 'Payout amount exceeds current ledger balance.');
        }

        // Peek which earnings would be consumed (for ledger meta) without mutating yet.
        $earningIds = $this->peekAvailableEarningIds($seller, (float) $locked->amount);

        $entry = $ledger->debitPayout($locked, $earningIds);
        if (! $entry) {
            abort(422, 'Could not post payout ledger debit.');
        }

        // Only after a successful ledger debit — mark earnings and finalize status.
        $this->markEarningsPaid($seller, (float) $locked->amount);
        $locked->status = SellerWithdrawal::STATUS_PAID;
    }

    protected function markFailed(SellerWithdrawal $locked): void
    {
        if ($locked->status !== SellerWithdrawal::STATUS_PROCESSING) {
            abort(422, 'Only processing payouts can be marked failed.');
        }
        // No ledger debit yet — releasing the hold restores available balance.
        $locked->status = SellerWithdrawal::STATUS_FAILED;
    }

    protected function retryFailed(SellerWithdrawal $locked): void
    {
        if ($locked->status !== SellerWithdrawal::STATUS_FAILED) {
            abort(422, 'Only failed payouts can be retried.');
        }

        $seller = Seller::query()->whereKey($locked->seller_id)->lockForUpdate()->firstOrFail();
        $available = $this->availablePayoutAmount($seller);
        if ((float) $locked->amount > $available) {
            throw ValidationException::withMessages([
                'amount' => 'Cannot retry: amount exceeds available seller earnings.',
            ]);
        }

        $locked->status = SellerWithdrawal::STATUS_UNDER_REVIEW;
    }

    /**
     * @return list<int>
     */
    protected function peekAvailableEarningIds(Seller $seller, float $amount): array
    {
        $remaining = round($amount, 2);
        $ids = [];
        $rows = $seller->earnings()
            ->where('status', SellerEarning::STATUS_AVAILABLE)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($rows as $earning) {
            $net = $earning->remainingNet();
            if ($net <= 0) {
                continue;
            }
            if ($remaining <= 0.009) {
                break;
            }
            $ids[] = (int) $earning->id;
            $remaining = round($remaining - min($net, $remaining), 2);
        }

        return $ids;
    }

    /**
     * Consume available earning rows (FIFO), supporting partial row consumption.
     *
     * @return list<int>
     */
    protected function markEarningsPaid(Seller $seller, float $amount): array
    {
        $remaining = round($amount, 2);
        $consumed = [];
        $rows = $seller->earnings()
            ->where('status', SellerEarning::STATUS_AVAILABLE)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($rows as $earning) {
            if ($remaining <= 0.009) {
                break;
            }
            $net = $earning->remainingNet();
            if ($net <= 0) {
                continue;
            }

            $take = round(min($net, $remaining), 2);
            $maxPayable = round(max(0, (float) $earning->net_amount - (float) $earning->refunded_net), 2);
            $newPaid = round(min($maxPayable, (float) $earning->paid_net + $take), 2);
            $fullyPaid = $newPaid + 0.009 >= $maxPayable;

            $earning->update([
                'paid_net' => $newPaid,
                'status' => $fullyPaid ? SellerEarning::STATUS_PAID : SellerEarning::STATUS_AVAILABLE,
            ]);
            $consumed[] = (int) $earning->id;
            $remaining = round($remaining - $take, 2);
        }

        if ($remaining > 0.009) {
            abort(422, 'Could not consume enough earning rows for payout.');
        }

        return $consumed;
    }
}
