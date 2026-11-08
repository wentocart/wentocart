<?php

namespace App\Domain\Marketing\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'file_name', 'path', 'store_ids', 'generated_at', 'generated_files',
])]
class MarketingSitemap extends Model
{
    protected function casts(): array
    {
        return [
            'store_ids' => 'array',
            'generated_files' => 'array',
            'generated_at' => 'datetime',
        ];
    }
}
