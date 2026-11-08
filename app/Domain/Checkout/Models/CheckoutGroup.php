<?php

namespace App\Domain\Checkout\Models;

use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Models\Order;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Store\Concerns\ShopOwned;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CheckoutGroup extends Model
{
    use ShopOwned;

    protected static string $shopFamily = 'orders';

    protected $fillable = [
        'reference', 'email', 'payment_status', 'currency_code', 'payment_details',
    ];

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function createTrusted(array $attributes): self
    {
        $row = new static;
        $row->forceFill($attributes)->save();

        return $row;
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
            'grand_total' => 'decimal:2',
            'capture_amount' => 'decimal:2',
            'payment_details' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
