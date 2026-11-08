<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Customer\Models\Customer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'product_id',
    'customer_id',
    'product_variant_id',
    'email',
    'first_name',
    'last_name',
    'notified_at',
])]
class CustomerStockAlert extends Model
{
    protected $table = 'customer_stock_alerts';

    protected function casts(): array
    {
        return [
            'product_variant_id' => 'integer',
            'notified_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function isPending(): bool
    {
        return $this->notified_at === null;
    }

    public function isGuest(): bool
    {
        return $this->customer_id === null;
    }

    public function statusLabel(): string
    {
        return $this->isPending() ? 'pending' : 'notified';
    }

    public function displayName(): string
    {
        $name = trim(((string) $this->first_name).' '.((string) $this->last_name));
        if ($name !== '') {
            return $name;
        }
        if ($this->relationLoaded('customer') && $this->customer) {
            return $this->customer->displayName();
        }

        return (string) $this->email;
    }
}
