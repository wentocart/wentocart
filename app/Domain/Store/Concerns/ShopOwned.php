<?php

namespace App\Domain\Store\Concerns;

use App\Domain\Store\StoreContext;
use Illuminate\Database\Eloquent\Builder;

trait ShopOwned
{
    /** @var array<string, bool> */
    protected static array $shopOwnedStoreIdColumn = [];

    public static function bootShopOwned(): void
    {
        static::addGlobalScope('shop', function (Builder $builder) {
            $family = static::$shopFamily ?? 'settings';
            app(StoreContext::class)->constrain($builder, $family);
        });

        static::creating(function ($model) {
            // Never stamp store_id before the column exists (installer / mid-migrate).
            // Cache hasColumn — Schema::hasColumn hits INFORMATION_SCHEMA on every create otherwise.
            $table = $model->getTable();
            if (! array_key_exists($table, static::$shopOwnedStoreIdColumn)) {
                static::$shopOwnedStoreIdColumn[$table] = \Illuminate\Support\Facades\Schema::hasColumn($table, 'store_id');
            }
            if (! static::$shopOwnedStoreIdColumn[$table]) {
                if (array_key_exists('store_id', $model->getAttributes())) {
                    unset($model->store_id);
                }

                return;
            }

            if (! $model->store_id) {
                $id = method_exists(static::class, 'newShopId')
                    ? (int) static::newShopId()
                    : app(StoreContext::class)->id();
                if ($id > 0) {
                    $model->store_id = $id;
                }
            }
        });
    }
}
