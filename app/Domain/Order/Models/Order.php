<?php

namespace App\Domain\Order\Models;

use App\Domain\Customer\Models\Customer;
use App\Domain\Marketplace\Models\SellerOrder;
use App\Domain\Order\OrderObserver;
use App\Domain\Ops\StaffActivityObserver;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Shipping\Models\Shipment;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Domain\Store\Concerns\ShopOwned;
use App\Domain\Store\Models\Store;
use App\Models\Admin;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ObservedBy([OrderObserver::class, StaffActivityObserver::class])]
#[Fillable([
    'email', 'status', 'channel', 'currency_code', 'currency_rate',
    'tax_details', 'payment_method_id', 'shipping_method_id',
    'payment_details', 'fulfillment_details', 'billing_address', 'shipping_address', 'customer_note',
])]
class Order extends Model
{
    use ShopOwned;

    protected static string $shopFamily = 'orders';

    /**
     * Trusted writes (checkout, admin). Sensitive totals/ids are not mass-assignable.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function createTrusted(array $attributes): self
    {
        $order = new static;
        $order->forceFill($attributes)->save();

        return $order;
    }

    /**
     * Admin links may pass either the numeric id or the public order number.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $field ??= $this->getRouteKeyName();
        $query = $this->newQuery();

        if ($field === $this->getRouteKeyName() && ! is_numeric($value)) {
            return $query->where('number', (string) $value)->firstOrFail();
        }

        return $query->where($field, $value)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateTrusted(array $attributes): bool
    {
        return $this->forceFill($attributes)->save();
    }

    protected function casts(): array
    {
        return [
            'currency_rate' => 'decimal:6',
            'subtotal' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'tax_details' => 'array',
            'shipping_total' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'fee_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'refunded_total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'amount_due' => 'decimal:2',
            'payment_details' => 'array',
            'fulfillment_details' => 'array',
            'billing_address' => 'array',
            'shipping_address' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function assignedAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'assigned_admin_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function shopReturns(): HasMany
    {
        return $this->hasMany(ShopReturn::class);
    }

    public function euWithdrawalRequests(): HasMany
    {
        return $this->hasMany(EuWithdrawalRequest::class);
    }

    public function sellerOrders(): HasMany
    {
        return $this->hasMany(SellerOrder::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(OrderStage::class, 'order_stage_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function checkoutGroup(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Checkout\Models\CheckoutGroup::class);
    }

    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class);
    }
}
