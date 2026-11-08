<?php

namespace App\Domain\Customer\Models;

use App\Domain\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['customer_id', 'product_id', 'price', 'starts_at', 'ends_at'])]
class CustomerProductPrice extends Model
{
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
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

    public function isLive(): bool
    {
        $now = now();
        if ($this->starts_at && $this->starts_at->isAfter($now)) {
            return false;
        }
        if ($this->ends_at && $this->ends_at->isBefore($now)) {
            return false;
        }

        return true;
    }
}
