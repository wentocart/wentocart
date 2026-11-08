<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['attribute_value_id', 'language_code', 'value'])]
class AttributeValueTranslation extends Model
{
    public $timestamps = false;

    public function attributeValue(): BelongsTo
    {
        return $this->belongsTo(AttributeValue::class);
    }
}
