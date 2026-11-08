<?php

namespace App\Domain\Store\Models;

use App\Domain\Catalog\Models\Category;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['category_id', 'store_id', 'is_visible'])]
class CategoryStore extends Model
{
    protected $table = 'category_store';

    protected function casts(): array
    {
        return ['is_visible' => 'boolean'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
