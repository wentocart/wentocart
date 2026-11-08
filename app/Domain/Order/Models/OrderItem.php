<?php

namespace App\Domain\Order\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Marketplace\Models\SellerOrderItem;
use App\Domain\Order\OrderItemObserver;
use App\Domain\Tax\Models\TaxClass;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ObservedBy([OrderItemObserver::class])]
#[Fillable([
    'order_id', 'product_id', 'parent_product_id', 'combination_id', 'tax_class_id', 'subscription_plan_id', 'sku', 'name', 'qty',
    'unit_price', 'tax_rate', 'tax_amount', 'total', 'customization', 'bundle_selection', 'gift_card_data', 'booking_date',
    'booking_end_date', 'rental_duration', 'rental_data', 'preorder_data', 'warehouse_id',
])]
class OrderItem extends Model
{
    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'returned_qty' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'tax_rate' => 'decimal:4',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'customization' => 'array',
            'bundle_selection' => 'array',
            'gift_card_data' => 'array',
            'booking_date' => 'date',
            'booking_end_date' => 'date',
            'rental_duration' => 'integer',
            'rental_data' => 'array',
            'preorder_data' => 'array',
        ];
    }

    public function returnableQty(): float
    {
        return max(0, (float) $this->qty - (float) ($this->returned_qty ?? 0));
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function sellerOrderItems(): HasMany
    {
        return $this->hasMany(SellerOrderItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function downloads(): HasMany
    {
        return $this->hasMany(\App\Domain\Order\Models\OrderDownload::class);
    }

    public function taxClass(): BelongsTo
    {
        return $this->belongsTo(TaxClass::class);
    }

    public function subscriptionPlan(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Subscription\Models\SubscriptionPlan::class, 'subscription_plan_id');
    }
}
