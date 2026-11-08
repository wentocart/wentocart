<?php

namespace App\Domain\Auth\Models;

use App\Models\Admin;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['slug', 'name'])]
class Role extends Model
{
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_role');
    }

    public function admins(): BelongsToMany
    {
        return $this->belongsToMany(Admin::class, 'admin_role');
    }

    public function profiles(): BelongsToMany
    {
        return $this->belongsToMany(Profile::class, 'profile_role');
    }

    public function isSuperAdminRole(): bool
    {
        return $this->slug === 'super_admin';
    }

    public function isSystem(): bool
    {
        return in_array($this->slug, [
            'super_admin',
            'catalog_manager',
            'order_manager',
            'customer_manager',
        ], true);
    }
}
