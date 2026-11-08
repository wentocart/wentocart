<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['brand_id', 'path', 'position'])]
class BrandImage extends Model
{
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
