<?php

namespace App\Domain\Customer\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'email', 'phone', 'credit_limit', 'net_days', 'is_enabled'])]
class Company extends Model
{
    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
            'net_days' => 'integer',
            'is_enabled' => 'boolean',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'company_users')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function members(): HasMany
    {
        return $this->hasMany(CompanyUser::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }
}
