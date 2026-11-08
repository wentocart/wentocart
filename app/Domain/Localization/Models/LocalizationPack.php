<?php

namespace App\Domain\Localization\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['country_code', 'name', 'payload', 'applied_at'])]
class LocalizationPack extends Model
{
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'applied_at' => 'datetime',
        ];
    }
}
