<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Localization\Models\Language;
use App\Domain\Store\Concerns\ShopLookupAssigned;
use App\Domain\Store\Models\ShopLookup;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['seller_id', 'code', 'type', 'supports_swatches', 'is_enabled', 'position'])]
class CatalogAttribute extends Model
{
    use ShopLookupAssigned;

    public static function shopLookupFamily(): string
    {
        return ShopLookup::FAMILY_ATTRIBUTE;
    }
    protected $table = 'attributes';

    public const TYPES = [
        'select' => 'Select',
        'radio' => 'Radio',
        'checkbox' => 'Checkbox',
        'text' => 'Text',
        'number' => 'Number',
    ];

    public const COMBINATION_TYPES = ['select', 'radio', 'checkbox'];

    public const SWATCH_TYPES = [
        'none' => 'None',
        'color' => 'Color',
        'image' => 'Image',
    ];

    protected function casts(): array
    {
        return [
            'supports_swatches' => 'boolean',
            'is_enabled' => 'boolean',
            'position' => 'integer',
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
        return $this->hasMany(AttributeTranslation::class, 'attribute_id');
    }

    public function values(): HasMany
    {
        return $this->hasMany(AttributeValue::class, 'attribute_id')->orderBy('position')->orderBy('id');
    }

    public function translation(?string $locale = null): ?AttributeTranslation
    {
        return Language::pickTranslation($this->translations, $locale);
    }

    public function isCombinationType(): bool
    {
        return in_array($this->type, self::COMBINATION_TYPES, true);
    }
}
