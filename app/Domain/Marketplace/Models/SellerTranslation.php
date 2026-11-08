<?php

namespace App\Domain\Marketplace\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'seller_id', 'language_code', 'shop_name', 'description',
    'store_policy', 'shipping_policy', 'return_policy', 'vacation_message',
])]
class SellerTranslation extends Model
{
    public $timestamps = false;

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }
}
