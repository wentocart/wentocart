<?php

namespace App\Domain\Customer\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Store\Concerns\ShopLookupAssigned;
use App\Domain\Store\Models\ShopLookup;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'product_id', 'customer_id', 'body', 'status', 'is_approved', 'rejected_reason',
])]
class ProductQuestion extends Model
{
    use ShopLookupAssigned;

    public static function shopLookupFamily(): string
    {
        return ShopLookup::FAMILY_QUESTION;
    }

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected function casts(): array
    {
        return ['is_approved' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (ProductQuestion $question) {
            $status = $question->status ?: ($question->is_approved ? self::STATUS_APPROVED : self::STATUS_PENDING);
            $question->status = $status;
            $question->is_approved = $status === self::STATUS_APPROVED;
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(ProductAnswer::class);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('is_approved', true);
    }

    public function approve(): void
    {
        $this->forceFill([
            'status' => self::STATUS_APPROVED,
            'is_approved' => true,
            'rejected_reason' => null,
        ])->save();
    }

    public function reject(?string $reason = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_REJECTED,
            'is_approved' => false,
            'rejected_reason' => $reason,
        ])->save();
    }

    public function pending(): void
    {
        $this->forceFill([
            'status' => self::STATUS_PENDING,
            'is_approved' => false,
            'rejected_reason' => null,
        ])->save();
    }
}
