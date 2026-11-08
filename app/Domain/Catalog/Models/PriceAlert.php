<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Customer\Models\Customer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['customer_id', 'product_id', 'last_price', 'email', 'notified_at'])]
class PriceAlert extends Model
{
    protected function casts(): array
    {
        return [
            'last_price' => 'decimal:2',
            'notified_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
