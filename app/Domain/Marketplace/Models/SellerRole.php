<?php

namespace App\Domain\Marketplace\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'seller_id', 'name', 'description', 'permission_type', 'permissions',
])]
class SellerRole extends Model
{
    public const TYPE_ALL = 'all';

    public const TYPE_CUSTOM = 'custom';

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(SellerUser::class, 'role_id');
    }

    public function allows(string $permission): bool
    {
        if ($this->permission_type === self::TYPE_ALL) {
            return true;
        }
        $perms = $this->permissions ?? [];

        return in_array($permission, $perms, true) || in_array('*', $perms, true);
    }
}
