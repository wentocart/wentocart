<?php

namespace App\Domain\Customer\Models;

use App\Domain\Customer\CustomerObserver;
use App\Domain\Ops\StaffActivityObserver;
use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Company;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Order\Models\Order;
use App\Domain\Security\HasWebAuthn;
use App\Domain\Settings\MailEvents;
use App\Domain\Settings\Models\Setting;
use App\Domain\Settings\ShopMailer;
use App\Domain\Store\Concerns\ShopOwned;
use App\Domain\Store\StoreContext;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[ObservedBy([CustomerObserver::class, StaffActivityObserver::class])]
#[Fillable([
    'name', 'first_name', 'last_name', 'email', 'phone', 'password',
    'date_of_birth', 'tax_vat', 'gender', 'company_name',
    'business_type', 'company_website', 'gst_tax_id', 'newsletter', 'profile', 'avatar_path',
])]
#[Hidden(['password', 'remember_token', 'totp_secret', 'sandbox_payment_token'])]
class Customer extends Authenticatable
{
    /** @use HasFactory<CustomerFactory> */
    use HasApiTokens, HasFactory, HasWebAuthn, Notifiable, ShopOwned;

    protected static string $shopFamily = 'customers';

    /**
     * Trusted writes (admin, register, plugins via CustomerService). Privileged columns are not fillable.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function createTrusted(array $attributes): self
    {
        $customer = new static;
        $customer->forceFill($attributes)->save();

        return $customer;
    }

    public static function newShopId(): int
    {
        $home = app(StoreContext::class)->customerHomeId();

        return $home > 0 ? $home : app(StoreContext::class)->requireId();
    }

    /**
     * Customers for the current shop, including legacy rows with no store_id
     * when this is the only shop or the default shop.
     */
    public function scopeInCurrentShop(Builder $query): Builder
    {
        $ctx = app(StoreContext::class);
        $ids = $ctx->idsFor(static::$shopFamily);
        if ($ids === []) {
            $id = $ctx->id() ?: $ctx->requireId();
            $ids = $id > 0 ? [$id] : [];
        }
        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }

        $column = $query->getModel()->qualifyColumn('store_id');
        $includeLegacy = ! $ctx->enabled();
        if (! $includeLegacy) {
            $defaultId = (int) \App\Domain\Store\Models\Store::query()->where('is_default', true)->value('id');
            $includeLegacy = $defaultId > 0 && in_array($defaultId, $ids, true);
        }

        return $query->where(function (Builder $inner) use ($ids, $column, $includeLegacy) {
            $inner->whereIn($column, $ids);
            if ($includeLegacy) {
                $inner->orWhereNull($column);
            }
        });
    }

    protected $guard = 'customer';

    protected static function newFactory(): CustomerFactory
    {
        return CustomerFactory::new();
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'date_of_birth' => 'date',
            'password' => 'hashed',
            'is_enabled' => 'boolean',
            'newsletter' => 'boolean',
            'profile' => 'array',
            'totp_confirmed_at' => 'datetime',
            'credit_limit' => 'decimal:2',
        ];
    }

    public function displayName(): string
    {
        $full = trim(($this->first_name ?? '').' '.($this->last_name ?? ''));

        return $full !== '' ? $full : (string) $this->name;
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', $this->displayName()) ?: [];

        return strtoupper(collect($parts)->filter()->take(2)->map(fn ($p) => mb_substr($p, 0, 1))->implode(''));
    }

    public function avatarUrl(): ?string
    {
        if (! $this->avatar_path || str_contains($this->avatar_path, '/loadtest/')) {
            return null;
        }

        if (str_starts_with($this->avatar_path, 'http://') || str_starts_with($this->avatar_path, 'https://') || str_starts_with($this->avatar_path, '/')) {
            return $this->avatar_path;
        }

        return '/storage/'.ltrim($this->avatar_path, '/');
    }

    /** Group assignment uses customer_groups.code (admin customer screens). */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $url = rtrim((string) Setting::getValue('shop_url', config('app.url')), '/')
            .'/reset-password?'.http_build_query([
                'token' => $token,
                'email' => $this->email,
            ]);

        app(ShopMailer::class)->sendRecipient(
            MailEvents::CUSTOMER_FORGOT_PASSWORD,
            'customer',
            array_merge(app(ShopMailer::class)->customerVars($this), ['reset_url' => $url]),
            $this->email,
        );
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'customer_group', 'code');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function adminNotes(): HasMany
    {
        return $this->hasMany(CustomerNote::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function seller(): HasOne
    {
        return $this->hasOne(Seller::class);
    }

    /** @return array<string, mixed>|null */
    public function sellerPayload(): ?array
    {
        $seller = $this->relationLoaded('seller') ? $this->seller : $this->seller()->first();
        if (! $seller) {
            return null;
        }

        return [
            'id' => $seller->id,
            'status' => $seller->status,
            'shop_name' => $seller->shop_name,
            'shop_slug' => $seller->shop_slug,
            'is_approved' => $seller->isApproved(),
            'can_access_portal' => $seller->canAccessPortal(),
        ];
    }

    /** @return array<string, mixed> */
    public function toStorefrontArray(): array
    {
        $data = $this->toArray();
        $data['name'] = $this->displayName();
        $data['seller'] = $this->sellerPayload();
        $data['totp_enabled'] = (bool) $this->totp_confirmed_at;
        $data['passkeys_enabled'] = $this->hasPasskeys();
        unset($data['totp_secret'], $data['totp_confirmed_at']);

        return $data;
    }

    public function wishlist(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'wishlists')->withTimestamps();
    }
}
