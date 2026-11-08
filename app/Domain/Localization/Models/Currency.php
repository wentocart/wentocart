<?php

namespace App\Domain\Localization\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'code', 'name', 'symbol', 'decimal_places', 'symbol_position',
    'exchange_rate', 'is_enabled', 'is_default', 'position',
])]
class Currency extends Model
{
    protected function casts(): array
    {
        return [
            'decimal_places' => 'integer',
            'exchange_rate' => 'decimal:6',
            'is_enabled' => 'boolean',
            'is_default' => 'boolean',
            'position' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => \App\Domain\Localization\Money::forgetCache());
        static::deleted(fn () => \App\Domain\Localization\Money::forgetCache());
    }
}
