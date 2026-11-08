<?php

namespace App\Domain\Cms\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['cms_block_id', 'language_code', 'title', 'content'])]
class CmsBlockTranslation extends Model
{
    public function block(): BelongsTo
    {
        return $this->belongsTo(CmsBlock::class, 'cms_block_id');
    }
}
