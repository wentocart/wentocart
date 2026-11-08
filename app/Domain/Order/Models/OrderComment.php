<?php

namespace App\Domain\Order\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'author_type', 'author_id', 'visibility', 'body'])]
class OrderComment extends Model
{
    public const VISIBILITY_CUSTOMER = 'customer';

    public const VISIBILITY_PRIVATE = 'private';

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
