<?php

namespace App\Domain\Localization\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['surface', 'page', 'key', 'source'])]
class UiString extends Model
{
    public function translations(): HasMany
    {
        return $this->hasMany(UiStringTranslation::class);
    }

    public function textFor(string $locale): string
    {
        $direct = $this->textIn($locale);
        if ($direct !== '') {
            return $direct;
        }

        if ($locale !== 'en') {
            $english = $this->textIn('en');
            if ($english !== '') {
                return $english;
            }
        }

        return $this->source;
    }

    protected function textIn(string $locale): string
    {
        $row = $this->relationLoaded('translations')
            ? $this->translations->firstWhere('language_code', $locale)
            : $this->translations()->where('language_code', $locale)->first();

        $text = trim((string) ($row?->text ?? ''));

        return $text;
    }
}
