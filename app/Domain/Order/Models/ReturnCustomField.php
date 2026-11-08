<?php

namespace App\Domain\Order\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'code', 'label', 'type', 'is_required', 'position',
    'input_validation', 'is_enabled', 'options',
])]
class ReturnCustomField extends Model
{
    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'is_enabled' => 'boolean',
            'position' => 'integer',
            'options' => 'array',
        ];
    }
}
