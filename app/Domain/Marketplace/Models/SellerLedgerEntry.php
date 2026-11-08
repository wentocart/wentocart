<?php

namespace App\Domain\Marketplace\Models;

use App\Domain\Order\Models\Order;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only seller financial ledger.
 * Never update amount/direction/reference after create — post compensating entries instead.
 */
#[Fillable([
    'seller_id', 'seller_order_id', 'order_id', 'seller_earning_id', 'seller_withdrawal_id',
    'entry_type', 'direction', 'amount', 'currency_code', 'reference', 'description', 'meta',
])]
class SellerLedgerEntry extends Model
{
    public const TYPE_ORDER_EARNING = 'order_earning';

    public const TYPE_REFUND_ADJUSTMENT = 'refund_adjustment';

    public const TYPE_CANCEL_ADJUSTMENT = 'cancel_adjustment';

    public const TYPE_PAYOUT = 'payout';

    public const DIRECTION_CREDIT = 'credit';

    public const DIRECTION_DEBIT = 'debit';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'meta' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \RuntimeException('Seller ledger entries are immutable.');
        });

        static::deleting(function () {
            throw new \RuntimeException('Seller ledger entries cannot be deleted.');
        });
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function withdrawal(): BelongsTo
    {
        return $this->belongsTo(SellerWithdrawal::class, 'seller_withdrawal_id');
    }

    public function signedAmount(): float
    {
        $amount = (float) $this->amount;

        return $this->direction === self::DIRECTION_DEBIT ? -abs($amount) : abs($amount);
    }
}
