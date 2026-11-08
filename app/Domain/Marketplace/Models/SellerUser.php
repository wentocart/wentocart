<?php

namespace App\Domain\Marketplace\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'seller_id', 'role_id', 'name', 'email', 'phone', 'password', 'is_enabled', 'last_login_at',
])]
class SellerUser extends Model
{
    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(SellerRole::class, 'role_id');
    }
}
