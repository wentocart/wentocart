<?php

namespace App\Domain\Marketplace\Models;

use App\Domain\Order\Models\Order;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'seller_id', 'order_id', 'number', 'customer_email', 'currency_code',
    'shipping_method_id', 'commission_source', 'commission_type', 'commission_rule_id',
    'billing_address', 'shipping_address',
])]
class SellerOrder extends Model
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

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'shipping_total' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'commission_rate' => 'decimal:2',
            'commission_value' => 'decimal:2',
            'commission_total' => 'decimal:2',
            'earning_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'billing_address' => 'array',
            'shipping_address' => 'array',
        ];
    }

    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Shipping\Models\ShippingMethod::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SellerOrderItem::class);
    }

    public function earnings(): HasMany
    {
        return $this->hasMany(SellerEarning::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(SellerLedgerEntry::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(\App\Domain\Shipping\Models\Shipment::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SellerReturn::class);
    }

    /** Commissionable merchandise (item totals minus allocated discounts). Tax/shipping excluded. */
    public function commissionableGross(): float
    {
        return round(max(0, (float) $this->subtotal - (float) $this->discount_total), 2);
    }
}
