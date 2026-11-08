<?php

namespace App\Domain\Customer\Models;

use App\Domain\Ops\EntityObserver;
use App\Domain\Store\Concerns\ShopLookupAssigned;
use App\Domain\Store\Models\ShopLookup;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'label', 'first_name', 'last_name', 'phone', 'company', 'tax_id', 'line1', 'line2',
    'city', 'state', 'postal_code', 'country_code', 'is_default_billing', 'is_default_shipping',
])]
#[ObservedBy([EntityObserver::class])]
class CustomerAddress extends Model
{
    use ShopLookupAssigned;

    public static function shopLookupFamily(): string
    {
        return ShopLookup::FAMILY_ADDRESS;
    }

    protected function casts(): array
    {
        return [
            'is_default_billing' => 'boolean',
            'is_default_shipping' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
