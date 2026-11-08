<?php

namespace App\Domain\Cms\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['menu_item_id', 'language_code', 'label'])]
class MenuItemTranslation extends Model
{
    public function item(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }
}
