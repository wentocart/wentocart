<?php

namespace App\Domain\Cms\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'cms_page_id', 'language_code', 'title', 'slug', 'short_description', 'body',
    'meta_title', 'meta_description', 'meta_keywords',
])]
class CmsPageTranslation extends Model
{
    public $timestamps = false;

    public function page(): BelongsTo
    {
        return $this->belongsTo(CmsPage::class, 'cms_page_id');
    }
}
