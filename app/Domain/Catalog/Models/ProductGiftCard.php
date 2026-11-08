<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'product_id', 'amount_type', 'min_amount', 'max_amount', 'default_amount', 'amount_step',
    'delivery_method', 'recipient_name_enabled', 'recipient_name_required',
    'recipient_email_enabled', 'recipient_email_required', 'sender_name_enabled', 'sender_name_required',
    'gift_message_enabled', 'gift_message_required', 'validity_type', 'validity_days', 'expiry_date',
    'allow_online_redemption', 'allow_offline_redemption', 'usage_type', 'allow_partial_redemption',
    'code_length', 'code_prefix', 'code_charset', 'code_uppercase',
    'min_order_amount', 'max_order_amount', 'restrictions',
])]
class ProductGiftCard extends Model
{
    protected function casts(): array
    {
        return [
            'min_amount' => 'decimal:2',
            'max_amount' => 'decimal:2',
            'default_amount' => 'decimal:2',
            'amount_step' => 'decimal:2',
            'expiry_date' => 'date',
            'recipient_name_enabled' => 'boolean',
            'recipient_name_required' => 'boolean',
            'recipient_email_enabled' => 'boolean',
            'recipient_email_required' => 'boolean',
            'sender_name_enabled' => 'boolean',
            'sender_name_required' => 'boolean',
            'gift_message_enabled' => 'boolean',
            'gift_message_required' => 'boolean',
            'allow_online_redemption' => 'boolean',
            'allow_offline_redemption' => 'boolean',
            'allow_partial_redemption' => 'boolean',
            'code_uppercase' => 'boolean',
            'min_order_amount' => 'decimal:2',
            'max_order_amount' => 'decimal:2',
            'restrictions' => 'array',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function amounts(): HasMany
    {
        return $this->hasMany(ProductGiftCardAmount::class, 'product_id', 'product_id')->orderBy('position');
    }
}
