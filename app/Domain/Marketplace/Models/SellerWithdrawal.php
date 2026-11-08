<?php

namespace App\Domain\Marketplace\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Seller payout request (Phase 12 lifecycle).
 *
 * REQUESTED → UNDER_REVIEW → PROCESSING → PAID
 *                 ↓               ↓
 *             REJECTED         FAILED
 *
 * Holding statuses reserve available balance until paid / rejected / failed.
 */
#[Fillable([
    'seller_id', 'seller_payment_method_id', 'note', 'payout_reference', 'payout_rail', 'processed_by', 'processed_at',
])]
class SellerWithdrawal extends Model
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function createTrusted(array $attributes): self
    {
        $row = new static;
        $row->forceFill($attributes)->save();

        return $row;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateTrusted(array $attributes): bool
    {
        return $this->forceFill($attributes)->save();
    }

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_FAILED = 'failed';

    public const STATUS_PAID = 'paid';

    /** @deprecated Use STATUS_REQUESTED */
    public const STATUS_PENDING = self::STATUS_REQUESTED;

    /** @deprecated Use STATUS_UNDER_REVIEW */
    public const STATUS_APPROVED = self::STATUS_UNDER_REVIEW;

    public const STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_REJECTED,
        self::STATUS_PROCESSING,
        self::STATUS_FAILED,
        self::STATUS_PAID,
    ];

    /** Statuses that reserve available balance (not yet paid/released). */
    public const HOLDING_STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_PROCESSING,
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'processed_at' => 'datetime',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(SellerPaymentMethod::class, 'seller_payment_method_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(SellerLedgerEntry::class, 'seller_withdrawal_id');
    }

    public function isHolding(): bool
    {
        return in_array($this->status, self::HOLDING_STATUSES, true);
    }

    public function canTransitionTo(string $to): bool
    {
        $map = [
            self::STATUS_REQUESTED => [self::STATUS_UNDER_REVIEW, self::STATUS_REJECTED, self::STATUS_PAID],
            self::STATUS_UNDER_REVIEW => [self::STATUS_PROCESSING, self::STATUS_REJECTED, self::STATUS_PAID],
            self::STATUS_PROCESSING => [self::STATUS_PAID, self::STATUS_FAILED],
            self::STATUS_FAILED => [self::STATUS_UNDER_REVIEW, self::STATUS_PROCESSING],
            self::STATUS_REJECTED => [],
            self::STATUS_PAID => [],
        ];

        return in_array($to, $map[$this->status] ?? [], true);
    }
}
