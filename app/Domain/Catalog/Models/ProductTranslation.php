<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Ops\StaffActivityObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'product_id', 'language_code', 'name', 'slug', 'short_description', 'description',
    'meta_title', 'meta_description', 'meta_keywords',
    'focus_keyword', 'canonical_url', 'robots',
    'subscription_message',
])]
#[ObservedBy([StaffActivityObserver::class])]
class ProductTranslation extends Model
{
    public $timestamps = false;

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
