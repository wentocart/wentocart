<?php

namespace App\Domain\Settings\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'email_template_id',
    'language_code',
    'subject',
    'body',
    'design_json',
])]
class EmailTemplateTranslation extends Model
{
    public function template(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'email_template_id');
    }
}
