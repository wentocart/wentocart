<?php

namespace App\Domain\Cms\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['menu_id', 'parent_id', 'label', 'url', 'position'])]
class MenuItem extends Model
{
    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(MenuItemTranslation::class);
    }

    public function localizedLabel(?string $locale = null): string
    {
        $locale = $locale ?: app()->getLocale();
        $rows = $this->relationLoaded('translations') ? $this->translations : $this->translations()->get();
        $hit = $rows->firstWhere('language_code', $locale);
        if ($hit?->label) {
            return (string) $hit->label;
        }
        $default = \App\Domain\Localization\Models\Language::defaultCode();
        $fallback = $rows->firstWhere('language_code', $default);

        return (string) ($fallback?->label ?: $this->label);
    }
}
