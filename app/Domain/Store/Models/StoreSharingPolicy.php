<?php

namespace App\Domain\Store\Models;

use App\Domain\Store\Sharing;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['store_id', 'family', 'mode', 'source_store_id'])]
class StoreSharingPolicy extends Model
{
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'source_store_id');
    }

    public function shares(): bool
    {
        return in_array($this->mode, [Sharing::MODE_SHARE, Sharing::MODE_SHARE_OVERRIDE], true)
            && $this->source_store_id;
    }
}
