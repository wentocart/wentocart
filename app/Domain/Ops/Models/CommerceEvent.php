<?php

namespace App\Domain\Ops\Models;

use App\Domain\Store\Concerns\ShopOwned;
use Illuminate\Database\Eloquent\Model;

class CommerceEvent extends Model
{
    use ShopOwned;

    public $timestamps = false;

    protected static string $shopFamily = 'settings';

    protected $fillable = [
        'store_id', 'name', 'operation_id', 'payload', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
