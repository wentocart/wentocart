<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'attachment_id', 'language_code', 'name', 'description',
])]
class AttachmentTranslation extends Model
{
    public $timestamps = false;

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }
}
