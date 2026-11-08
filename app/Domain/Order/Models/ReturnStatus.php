<?php

namespace App\Domain\Order\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['title', 'color', 'is_enabled', 'is_default', 'position'])]
class ReturnStatus extends Model
{
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'is_default' => 'boolean',
            'position' => 'integer',
        ];
    }
}
