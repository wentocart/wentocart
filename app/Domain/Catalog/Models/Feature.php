<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Localization\Models\Language;
use App\Domain\Store\Concerns\ShopLookupAssigned;
use App\Domain\Store\Models\ShopLookup;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['seller_id', 'code', 'type', 'is_filterable', 'used_for_combinations', 'is_enabled', 'position'])]
class Feature extends Model
{
    use ShopLookupAssigned;

    public static function shopLookupFamily(): string
    {
        return ShopLookup::FAMILY_FEATURE;
    }
    public const TYPES = [
        'select' => 'Select',
        'color' => 'Color',
        'text' => 'Text',
    ];

    protected function casts(): array
    {
        return [
            'is_filterable' => 'boolean',
            'used_for_combinations' => 'boolean',
            'is_enabled' => 'boolean',
        ];
    }

    public static function typeOptions(): array
    {
        return collect(self::TYPES)
            ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
            ->values()
            ->all();
    }

    public function translations(): HasMany
    {
        return $this->hasMany(FeatureTranslation::class);
    }

    public function values(): HasMany
    {
        return $this->hasMany(FeatureValue::class)->orderBy('position')->orderBy('id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_feature_values');
    }

    public function translation(?string $locale = null): ?FeatureTranslation
    {
        return Language::pickTranslation($this->translations, $locale);
    }
}
