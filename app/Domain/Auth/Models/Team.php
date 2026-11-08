<?php

namespace App\Domain\Auth\Models;

use App\Models\Admin;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'color', 'description', 'is_enabled', 'default_profile_id'])]
class Team extends Model
{
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }

    public function admins(): HasMany
    {
        return $this->hasMany(Admin::class);
    }

    public function defaultProfile(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'default_profile_id');
    }
}
