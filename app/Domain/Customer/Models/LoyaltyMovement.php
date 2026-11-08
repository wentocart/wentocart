<?php

namespace App\Domain\Customer\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['customer_id', 'points', 'type', 'reference', 'order_id'])]
class LoyaltyMovement extends Model
{
    public const TYPE_EARN = 'earn';

    public const TYPE_REDEEM = 'redeem';

    public const TYPE_ADJUST = 'adjust';

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
