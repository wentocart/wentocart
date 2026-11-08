<?php

namespace App\Domain\Store\Concerns;

use App\Domain\Store\Models\ShopLookup;
use App\Domain\Store\StoreContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

trait ShopLookupAssigned
{
    abstract public static function shopLookupFamily(): string;

    public static function bootShopLookupAssigned(): void
    {
        static::addGlobalScope('shop', function (Builder $builder) {
            $model = $builder->getModel();
            if (method_exists($model, 'constrainToCurrentShop')) {
                $model->constrainToCurrentShop($builder);
            }
        });

        static::saved(function ($model) {
            if (method_exists($model, 'attachToCurrentShop')) {
                $model->attachToCurrentShop();
            }
        });
    }

    public function storeAssignments(): HasMany
    {
        return $this->hasMany(ShopLookup::class, 'lookup_id')
            ->where('shop_lookups.family', static::shopLookupFamily());
    }

    public function constrainToCurrentShop(Builder $query): Builder
    {
        return app(StoreContext::class)->constrainLookups($query, static::shopLookupFamily());
    }

    public function attachToCurrentShop(): void
    {
        foreach (app(StoreContext::class)->associationIds() as $storeId) {
            if ($storeId < 1) {
                continue;
            }
            ShopLookup::query()->firstOrCreate(
                [
                    'family' => static::shopLookupFamily(),
                    'lookup_id' => $this->id,
                    'store_id' => $storeId,
                ],
                ['is_visible' => true],
            );
        }
    }
}
