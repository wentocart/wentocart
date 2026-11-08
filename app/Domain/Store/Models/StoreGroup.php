<?php

namespace App\Domain\Store\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'code', 'description', 'share_customers', 'share_orders', 'share_stock', 'color', 'is_enabled'])]
class StoreGroup extends Model
{
    protected function casts(): array
    {
        return [
            'share_customers' => 'boolean',
            'share_orders' => 'boolean',
            'share_stock' => 'boolean',
            'is_enabled' => 'boolean',
        ];
    }

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    public function canBeDeleted(): bool
    {
        return $this->code !== 'default';
    }
}
