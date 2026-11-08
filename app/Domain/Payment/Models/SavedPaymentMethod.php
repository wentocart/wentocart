<?php

namespace App\Domain\Payment\Models;

use App\Domain\Customer\Models\Customer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'customer_id',
    'driver',
    'reference',
    'gateway_customer_ref',
    'brand',
    'last4',
    'exp_month',
    'exp_year',
    'is_default',
    'revoked_at',
    'pending_action_url',
])]
class SavedPaymentMethod extends Model
{
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'exp_month' => 'integer',
            'exp_year' => 'integer',
            'revoked_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * A card that is past its expiry cannot be charged off-session.
     */
    public function isExpired(): bool
    {
        if (! $this->exp_year) {
            return false;
        }
        $endOfMonth = \Illuminate\Support\Carbon::create($this->exp_year, $this->exp_month ?: 12, 1)->endOfMonth();

        return $endOfMonth->isPast();
    }

    public function isUsable(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired() && $this->reference !== '';
    }

    /**
     * "Visa •••• 4242" — safe for display, never the full number.
     */
    public function label(): string
    {
        $brand = $this->brand ? ucfirst($this->brand) : 'Card';

        return $this->last4 ? $brand . ' •••• ' . $this->last4 : $brand;
    }

    public function revoke(): void
    {
        $this->forceFill(['revoked_at' => now(), 'is_default' => false])->save();
    }
}
