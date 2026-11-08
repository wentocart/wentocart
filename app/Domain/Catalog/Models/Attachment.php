<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Localization\Models\Language;
use App\Domain\Store\Concerns\ShopLookupAssigned;
use App\Domain\Store\Models\ShopLookup;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'original_filename',
    'stored_filename',
    'storage_path',
    'disk',
    'mime_type',
    'file_size',
    'description',
])]
class Attachment extends Model
{
    use ShopLookupAssigned;

    public static function shopLookupFamily(): string
    {
        return ShopLookup::FAMILY_FILE;
    }
    public function translations(): HasMany
    {
        return $this->hasMany(AttachmentTranslation::class);
    }

    public function translation(?string $locale = null): ?AttachmentTranslation
    {
        return Language::pickTranslation($this->translations, $locale);
    }

    public function displayName(?string $locale = null): string
    {
        return $this->translation($locale)?->name ?? $this->name ?? "File #{$this->id}";
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'attachment_product')->withTimestamps();
    }
}
