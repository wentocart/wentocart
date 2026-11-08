<?php

namespace App\Domain\Order\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_return_id', 'admin_id', 'customer_id', 'body', 'is_admin'])]
class ReturnMessage extends Model
{
    protected function casts(): array
    {
        return [
            'is_admin' => 'boolean',
        ];
    }

    public function shopReturn(): BelongsTo
    {
        return $this->belongsTo(ShopReturn::class, 'order_return_id');
    }
}
