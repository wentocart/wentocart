<?php

namespace App\Domain\Localization\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['ui_string_id', 'language_code', 'text'])]
class UiStringTranslation extends Model
{
    public function string(): BelongsTo
    {
        return $this->belongsTo(UiString::class, 'ui_string_id');
    }
}
