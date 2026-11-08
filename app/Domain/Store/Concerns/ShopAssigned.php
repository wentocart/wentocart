<?php

namespace App\Domain\Store\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait ShopAssigned
{
    public static function bootShopAssigned(): void
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
}
