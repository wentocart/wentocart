<?php

namespace App\Domain\Payment\Models;

use App\Domain\Checkout\Models\Cart;
use App\Domain\Order\Models\Order;
use App\Domain\Payment\PaymentCheckoutStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PaymentCheckout extends Model
{
    protected $fillable = [
        'token',
        'store_id',
        'cart_id',
        'customer_id',
        'email',
        'payment_method_id',
        'driver',
        'status',
        'amount',
        'currency_code',
        'provider_reference',
        'idempotency_key',
        'checkout_snapshot',
        'payment_proof',
        'meta',
        'order_id',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'checkout_snapshot' => 'array',
            'payment_proof' => 'array',
            'meta' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public static function issueToken(): string
    {
        return 'pc_'.Str::lower(Str::random(40));
    }

    public function checkoutStatus(): PaymentCheckoutStatus
    {
        return PaymentCheckoutStatus::tryFrom((string) $this->status)
            ?? PaymentCheckoutStatus::RequiresPayment;
    }

    public function isFulfilled(): bool
    {
        return $this->order_id !== null
            && $this->checkoutStatus() === PaymentCheckoutStatus::Fulfilled;
    }
}
