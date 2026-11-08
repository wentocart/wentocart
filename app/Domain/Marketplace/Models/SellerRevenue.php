<?php

namespace App\Domain\Marketplace\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'seller_id', 'period_date', 'period_type',
    'order_count', 'item_count', 'gross_total', 'commission_total', 'net_total',
])]
class SellerRevenue extends Model
{
    protected function casts(): array
    {
        return [
            'period_date' => 'date',
            'gross_total' => 'decimal:2',
            'commission_total' => 'decimal:2',
            'net_total' => 'decimal:2',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }
}
