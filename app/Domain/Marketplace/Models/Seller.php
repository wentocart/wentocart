<?php

namespace App\Domain\Marketplace\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Localization\Models\Language;
use App\Domain\Store\Concerns\ShopOwned;
use App\Models\Admin;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'store_id', 'customer_id', 'status',
    'first_name', 'last_name', 'shop_name', 'shop_slug', 'profile_name',
    'email', 'phone', 'contact_name', 'contact_phone', 'contact_email',
    'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country_code',
    'description', 'tax_id', 'logo_path', 'banner_path', 'commission_rate',
    'facebook_url', 'twitter_url', 'instagram_url', 'youtube_url',
    'is_active', 'vacation_enabled', 'vacation_message',
    'store_policy', 'shipping_policy', 'return_policy',
    'payout_bank_account', 'payout_bank_ifsc', 'payout_upi_id', 'payout_paypal_email',
    'review_note', 'reviewed_by', 'reviewed_at', 'blocked_at', 'communications_blocked_at',
    'health_score', 'health_band', 'health_cancellation_rate', 'health_avg_rating',
    'health_flag_count', 'health_refreshed_at',
    'is_featured', 'featured_position',
    'minimum_order_amount',
])]
class Seller extends Model
{
    use ShopOwned;

    protected static string $shopFamily = 'customers';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_BLOCKED = 'blocked';

    /**
     * Lifecycle vocabulary used by the master plan, mapped onto existing DB values:
     * ACTIVE = approved, SUSPENDED = blocked.
     */
    public const LIFECYCLE_ACTIVE = self::STATUS_APPROVED;

    public const LIFECYCLE_SUSPENDED = self::STATUS_BLOCKED;

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_BLOCKED,
    ];

    protected function casts(): array
    {
        return [
            'commission_rate' => 'decimal:2',
            'minimum_order_amount' => 'decimal:2',
            'is_active' => 'boolean',
            'vacation_enabled' => 'boolean',
            'reviewed_at' => 'datetime',
            'blocked_at' => 'datetime',
            'communications_blocked_at' => 'datetime',
            'health_cancellation_rate' => 'decimal:4',
            'health_avg_rating' => 'decimal:2',
            'health_refreshed_at' => 'datetime',
            'is_featured' => 'boolean',
        ];
    }

    public function isPublic(): bool
    {
        return $this->isActive() && ($this->is_active ?? true);
    }

    /** Operational / portal “ACTIVE” seller (status = approved). */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isApproved(): bool
    {
        return $this->isActive();
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isBlocked(): bool
    {
        return $this->status === self::STATUS_BLOCKED;
    }

    /** Suspended sellers keep history but cannot operate the portal. */
    public function isSuspended(): bool
    {
        return $this->isBlocked();
    }

    public function canAccessPortal(): bool
    {
        return $this->isActive();
    }

    public function canReapply(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function displayName(): string
    {
        return trim($this->first_name.' '.$this->last_name) ?: $this->shop_name;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function translations(): HasMany
    {
        return $this->hasMany(SellerTranslation::class);
    }

    public function translation(?string $locale = null): ?SellerTranslation
    {
        if ($this->relationLoaded('translations')) {
            $hit = Language::pickTranslation($this->translations, $locale);

            return $hit instanceof SellerTranslation ? $hit : null;
        }

        $locale ??= Language::currentCode();
        $default = Language::defaultCode();

        return $this->translations()
            ->whereIn('language_code', array_values(array_unique([$locale, $default])))
            ->get()
            ->pipe(fn ($rows) => Language::pickTranslation($rows, $locale));
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewed_by');
    }

    public function products(): HasMany
    {
        return $this->hasMany(SellerProduct::class);
    }

    public function catalogProducts(): HasMany
    {
        return $this->hasMany(Product::class, 'seller_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(SellerOrder::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(SellerCommission::class);
    }

    public function earnings(): HasMany
    {
        return $this->hasMany(SellerEarning::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(SellerLedgerEntry::class);
    }

    public function revenues(): HasMany
    {
        return $this->hasMany(SellerRevenue::class);
    }

    public function shippingMethods(): HasMany
    {
        return $this->hasMany(SellerShippingMethod::class);
    }

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(SellerPaymentMethod::class);
    }

    public function discounts(): HasMany
    {
        return $this->hasMany(SellerDiscount::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(SellerCategory::class);
    }

    public function features(): HasMany
    {
        return $this->hasMany(SellerFeature::class);
    }

    public function brands(): HasMany
    {
        return $this->hasMany(SellerBrand::class);
    }

    public function suppliers(): HasMany
    {
        return $this->hasMany(SellerSupplier::class);
    }

    public function attributes(): HasMany
    {
        return $this->hasMany(SellerAttribute::class);
    }

    public function withdrawals(): HasMany
    {
        return $this->hasMany(SellerWithdrawal::class);
    }

    public function sellerReviews(): HasMany
    {
        return $this->hasMany(SellerReview::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SellerMessage::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SellerReturn::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(\App\Domain\Shipping\Models\Shipment::class);
    }
}
