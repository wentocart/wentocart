<?php

namespace App\Domain\Auth\Models;

use App\Models\Admin;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'description', 'is_enabled'])]
class Profile extends Model
{
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'profile_permission');
    }

    /** @deprecated Staff profiles no longer bundle roles; kept for legacy reads only. */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'profile_role');
    }

    public function admins(): HasMany
    {
        return $this->hasMany(Admin::class);
    }

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class, 'default_profile_id');
    }
}
