<?php

namespace App\Domain\Order\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'description', 'return_period', 'is_enabled', 'is_default'])]
class ReturnRule extends Model
{
    protected function casts(): array
    {
        return [
            'return_period' => 'integer',
            'is_enabled' => 'boolean',
            'is_default' => 'boolean',
        ];
    }
}
