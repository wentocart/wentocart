<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Localization\Models\Language;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'attribute_id', 'code', 'swatch_type', 'color_code', 'swatch_image', 'is_enabled', 'position',
])]
class AttributeValue extends Model
{
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(CatalogAttribute::class, 'attribute_id');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(AttributeValueTranslation::class);
    }

    public function translation(?string $locale = null): ?AttributeValueTranslation
    {
        return Language::pickTranslation($this->translations, $locale);
    }
}
