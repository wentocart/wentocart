<?php

namespace App\Domain\Promo\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'type', 'value', 'min_cart', 'max_uses', 'used_count', 'starts_at', 'expires_at', 'is_enabled'])]
class Coupon extends Model
{
    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'min_cart' => 'decimal:2',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_enabled' => 'boolean',
        ];
    }

    public function isValidFor(float $subtotal): bool
    {
        if (! $this->is_enabled) {
            return false;
        }

        if ($this->starts_at && $this->starts_at->isFuture()) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        if ($this->max_uses !== null && $this->used_count >= $this->max_uses) {
            return false;
        }

        if ($this->min_cart !== null && $subtotal < (float) $this->min_cart) {
            return false;
        }

        return true;
    }

    public function discountFor(float $subtotal): float
    {
        if ($this->type === 'percent') {
            return round($subtotal * ((float) $this->value / 100), 2);
        }

        return min((float) $this->value, $subtotal);
    }
}
