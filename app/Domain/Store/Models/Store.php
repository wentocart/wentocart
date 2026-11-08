<?php

namespace App\Domain\Store\Models;

use App\Domain\Installer\Models\ShopUrl;
use App\Domain\Order\Models\Order;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'store_group_id', 'name', 'code', 'email', 'address', 'theme', 'theme_settings',
    'is_default', 'is_enabled', 'color',
])]
class Store extends Model
{
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_enabled' => 'boolean',
            'theme_settings' => 'array',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(StoreGroup::class, 'store_group_id');
    }

    public function urls(): HasMany
    {
        return $this->hasMany(ShopUrl::class);
    }

    public function primaryUrl(): ?ShopUrl
    {
        return $this->urls->firstWhere('is_primary', true) ?? $this->urls->first();
    }

    public function policies(): HasMany
    {
        return $this->hasMany(StoreSharingPolicy::class);
    }

    public function policy(string $family): ?StoreSharingPolicy
    {
        return $this->policies->firstWhere('family', $family);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function canBeDeleted(): bool
    {
        if ($this->is_default) {
            return false;
        }

        return ! $this->orders()->exists();
    }
}
