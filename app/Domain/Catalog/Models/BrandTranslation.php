<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'brand_id', 'language_code', 'name', 'slug', 'short_description', 'tagline', 'description',
    'meta_title', 'meta_description', 'meta_keywords',
])]
class BrandTranslation extends Model
{
    public $timestamps = false;

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
