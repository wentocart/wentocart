<?php

namespace App\Domain\Cms\Models;

use App\Domain\Cms\CmsPageTemplates;
use App\Domain\Localization\Models\Language;
use App\Domain\Ops\EntityObserver;
use App\Domain\Ops\StaffActivityObserver;
use App\Domain\Store\Concerns\ShopOwned;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'is_enabled', 'status', 'is_visible', 'is_default', 'template',
    'cms_category_id', 'menu_id', 'canonical_url', 'robots', 'store_id',
])]
#[ObservedBy([EntityObserver::class, StaffActivityObserver::class])]
class CmsPage extends Model
{
    use ShopOwned;

    protected static string $shopFamily = 'cms';
    public const STATUS_PUBLISHED = 'published';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [
        self::STATUS_PUBLISHED => 'Published',
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_ARCHIVED => 'Archived',
    ];

    public const ROBOTS = [
        'index,follow' => 'Index, Follow',
        'noindex,follow' => 'Noindex, Follow',
        'index,nofollow' => 'Index, Nofollow',
        'noindex,nofollow' => 'Noindex, Nofollow',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'is_visible' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(CmsPageTranslation::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CmsCategory::class, 'cms_category_id');
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    public function translation(?string $locale = null): ?CmsPageTranslation
    {
        return Language::pickTranslation($this->translations, $locale);
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query
            ->where('is_enabled', true)
            ->where('is_visible', true)
            ->where('status', self::STATUS_PUBLISHED);
    }

    public static function templateOptions(): array
    {
        return CmsPageTemplates::options();
    }
}
