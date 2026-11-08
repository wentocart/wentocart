<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tag_id', 'language_code', 'name', 'slug'])]
class TagTranslation extends Model
{
    public $timestamps = false;

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }
}
