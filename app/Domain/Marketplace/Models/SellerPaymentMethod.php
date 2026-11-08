<?php

namespace App\Domain\Marketplace\Models;

use App\Domain\Payment\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'seller_id', 'payment_method_id', 'type', 'name',
    'account_name', 'account_number', 'bank_name', 'routing_number',
    'paypal_email', 'notes', 'is_default', 'is_enabled', 'config',
])]
class SellerPaymentMethod extends Model
{
    public const TYPES = [
        'bank' => 'Bank transfer',
        'paypal' => 'PayPal',
        'upi' => 'UPI',
        'other' => 'Other',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_enabled' => 'boolean',
            'config' => 'array',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }
}
