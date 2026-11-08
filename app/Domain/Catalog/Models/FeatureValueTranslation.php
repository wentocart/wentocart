<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['feature_value_id', 'language_code', 'value'])]
class FeatureValueTranslation extends Model
{
    public $timestamps = false;

    public function featureValue(): BelongsTo
    {
        return $this->belongsTo(FeatureValue::class);
    }
}
