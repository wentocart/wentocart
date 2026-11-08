<?php

namespace App\Domain\Tax\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'rate', 'is_enabled'])]
class TaxRate extends Model
{
    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4',
            'is_enabled' => 'boolean',
        ];
    }

    public function rules(): HasMany
    {
        return $this->hasMany(TaxRule::class);
    }
}
