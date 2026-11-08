<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Localization\Models\Language;
use App\Domain\Ops\EntityObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([])]
#[ObservedBy([EntityObserver::class])]
class Tag extends Model
{
    public function translations(): HasMany
    {
        return $this->hasMany(TagTranslation::class);
    }

    public function translation(?string $locale = null): ?TagTranslation
    {
        return Language::pickTranslation($this->translations, $locale);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_tag');
    }
}
