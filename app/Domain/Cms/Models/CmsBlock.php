<?php

namespace App\Domain\Cms\Models;

use App\Domain\Store\Concerns\ShopOwned;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'title', 'location', 'content', 'is_enabled', 'position', 'store_id'])]
class CmsBlock extends Model
{
    use ShopOwned;

    protected static string $shopFamily = 'cms';
    public const LOCATIONS = [
        'home' => 'Home',
        'header' => 'Header',
        'footer' => 'Footer',
    ];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean'];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(CmsBlockTranslation::class);
    }

    public function translation(?string $locale = null): ?CmsBlockTranslation
    {
        $locale = $locale ?: app()->getLocale();
        $rows = $this->relationLoaded('translations') ? $this->translations : $this->translations()->get();
        $hit = $rows->firstWhere('language_code', $locale);
        if ($hit) {
            return $hit;
        }
        $default = \App\Domain\Localization\Models\Language::defaultCode();

        return $rows->firstWhere('language_code', $default);
    }

    public function localizedTitle(?string $locale = null): string
    {
        return (string) ($this->translation($locale)?->title ?: $this->title);
    }

    public function localizedContent(?string $locale = null): ?string
    {
        $translated = $this->translation($locale)?->content;
        if (is_string($translated) && $translated !== '') {
            return $translated;
        }

        return $this->content;
    }
}
