<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'product_id', 'order_id', 'order_item_id', 'customer_id', 'code',
    'initial_balance', 'current_balance', 'selected_amount',
    'recipient_name', 'recipient_email', 'sender_name', 'gift_message',
    'issued_at', 'expires_at', 'status',
])]
class GiftCard extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'initial_balance' => 'decimal:2',
            'current_balance' => 'decimal:2',
            'selected_amount' => 'decimal:2',
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(GiftCardTransaction::class)->orderBy('created_at');
    }

    public function isRedeemable(): bool
    {
        if (! in_array($this->status, ['active'], true)) {
            return false;
        }
        if ($this->current_balance <= 0) {
            return false;
        }
        if ($this->expires_at && now()->gt($this->expires_at)) {
            return false;
        }

        return true;
    }

    /**
     * Transient status (not persisted) so the admin UI can flag a card whose
     * expiry has passed but which has not been swept yet.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && now()->gt($this->expires_at);
    }
}
