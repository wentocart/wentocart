<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['feature_id', 'language_code', 'name', 'description'])]
class FeatureTranslation extends Model
{
    public $timestamps = false;

    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }
}
