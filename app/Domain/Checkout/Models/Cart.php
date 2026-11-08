<?php

namespace App\Domain\Checkout\Models;

use App\Domain\Customer\Models\Customer;
use App\Domain\Ops\EntityObserver;
use App\Domain\Promo\Models\Coupon;
use App\Domain\Promo\Models\DiscountRule;
use App\Domain\Store\Concerns\ShopOwned;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['customer_id', 'session_id', 'coupon_id', 'discount_rule_id', 'meta', 'store_id'])]
#[ObservedBy([EntityObserver::class])]
class Cart extends Model
{
    use ShopOwned;

    protected static string $shopFamily = 'carts';

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function discountRule(): BelongsTo
    {
        return $this->belongsTo(DiscountRule::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function subtotal(): float
    {
        return (float) $this->items->sum(fn (CartItem $item) => $item->qty * (float) $item->unit_price);
    }
}
