<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\CategoryObserver;
use App\Domain\Localization\Models\Language;
use App\Domain\Ops\EntityObserver;
use App\Domain\Ops\StaffActivityObserver;
use App\Domain\Store\Concerns\ShopAssigned;
use App\Domain\Store\Models\CategoryStore;
use App\Domain\Store\StoreContext;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['seller_id', 'parent_id', 'image_path', 'thumbnail_path', 'banner_path', 'cover_path', 'icon_path', 'customer_group_ids', 'is_enabled', 'position'])]
#[ObservedBy([CategoryObserver::class, EntityObserver::class, StaffActivityObserver::class])]
class Category extends Model
{
    use ShopAssigned;

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'customer_group_ids' => 'array',
        ];
    }

    /** @param  list<int|string|null>|null  $ids */
    public static function normalizeCustomerGroupIds(?array $ids): array
    {
        return array_values(array_unique(array_map(
            'intval',
            array_filter((array) ($ids ?? []), fn ($value) => $value !== '' && $value !== null)
        )));
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(CategoryTranslation::class);
    }

    public function translation(?string $locale = null): ?CategoryTranslation
    {
        return Language::pickTranslation($this->translations, $locale);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'category_product');
    }

    public function storeAssignments(): HasMany
    {
        return $this->hasMany(CategoryStore::class);
    }

    public function constrainToCurrentShop(Builder $query): Builder
    {
        return app(StoreContext::class)->constrainCategories($query);
    }

    public function attachToCurrentShop(): void
    {
        foreach (app(StoreContext::class)->associationIds() as $storeId) {
            if ($storeId < 1) {
                continue;
            }
            CategoryStore::query()->firstOrCreate(
                ['category_id' => $this->id, 'store_id' => $storeId],
                ['is_visible' => true],
            );
        }
    }
}
