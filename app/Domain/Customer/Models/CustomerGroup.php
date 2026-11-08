<?php

namespace App\Domain\Customer\Models;

use App\Domain\Store\Concerns\ShopLookupAssigned;
use App\Domain\Store\Models\ShopLookup;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable([
    'name', 'code', 'color', 'discount_percent', 'tax_exempt', 'hide_prices', 'description', 'is_enabled', 'is_system', 'position',
])]
class CustomerGroup extends Model
{
    use ShopLookupAssigned;

    public static function shopLookupFamily(): string
    {
        return ShopLookup::FAMILY_CUSTOMER_GROUP;
    }

    protected static function booted(): void
    {
        static::saving(function (CustomerGroup $group): void {
            if ($group->is_system) {
                $group->is_enabled = true;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'discount_percent' => 'float',
            'tax_exempt' => 'boolean',
            'hide_prices' => 'boolean',
            'is_enabled' => 'boolean',
            'is_system' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class, 'customer_group', 'code');
    }

    public static function catalog(?bool $enabled = null): Collection
    {
        \App\Domain\Customer\SystemCustomerGroups::ensure();

        return static::query()
            ->when($enabled !== null, fn ($q) => $q->where('is_enabled', $enabled))
            ->orderBy('position')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'color', 'discount_percent', 'tax_exempt', 'hide_prices', 'description', 'is_enabled', 'is_system']);
    }

    public static function codes(): array
    {
        return static::query()->orderBy('position')->pluck('code')->all();
    }
}
