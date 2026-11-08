<?php

namespace App\Domain\Settings\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'document_template_id',
    'language_code',
    'email_subject',
    'body',
    'design_json',
])]
class DocumentTemplateTranslation extends Model
{
    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'document_template_id');
    }
}
