<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Order\Models\Order;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'gift_card_id', 'type', 'amount', 'balance_before', 'balance_after', 'order_id', 'metadata',
])]
class GiftCardTransaction extends Model
{
    public const TYPE_CREDIT = 'credit';
    public const TYPE_REDEMPTION = 'redemption';
    public const TYPE_ADJUSTMENT = 'adjustment';
    public const TYPE_CANCEL = 'cancel';
    public const TYPE_HOLD = 'hold';
    public const TYPE_RELEASE = 'release';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_before' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    public function giftCard(): BelongsTo
    {
        return $this->belongsTo(GiftCard::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
