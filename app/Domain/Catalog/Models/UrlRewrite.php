<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Store\StoreContext;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['store_id', 'language_code', 'request_path', 'entity_type', 'entity_id'])]
class UrlRewrite extends Model
{
    public $timestamps = false;

    public static function locate(string $path, ?string $language = null): ?self
    {
        $language ??= app()->getLocale();
        $query = static::query()
            ->where('language_code', $language)
            ->where('request_path', trim($path, '/'));

        $context = app(StoreContext::class);
        if ($context->enabled() && $context->id() > 0) {
            $current = $context->id();
            $ids = array_values(array_unique(array_filter([
                $current,
                ...$context->catalogStoreIds(),
            ])));
            $query->where(function (Builder $inner) use ($ids) {
                $inner->whereIn('store_id', $ids)->orWhereNull('store_id');
            });
            $query->orderByRaw('case when store_id = ? then 0 else 1 end', [$current]);
        }

        return $query->first();
    }
}
