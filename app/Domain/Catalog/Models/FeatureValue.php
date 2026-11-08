<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Localization\Models\Language;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['feature_id', 'code', 'is_enabled', 'position'])]
class FeatureValue extends Model
{
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }

    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }

    public function translations(): HasMany
    {
        return $this->hasMany(FeatureValueTranslation::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_feature_values');
    }

    public function translation(?string $locale = null): ?FeatureValueTranslation
    {
        return Language::pickTranslation($this->translations, $locale);
    }
}
