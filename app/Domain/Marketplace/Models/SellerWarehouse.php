<?php

namespace App\Domain\Marketplace\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'seller_id', 'code', 'name', 'description',
    'country_code', 'state', 'city', 'street', 'postcode',
    'contact_name', 'contact_email', 'contact_phone', 'fax',
    'latitude', 'longitude', 'priority', 'is_enabled',
])]
class SellerWarehouse extends Model
{
    protected $table = 'seller_warehouses';

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'priority' => 'integer',
            'is_enabled' => 'boolean',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }
}
