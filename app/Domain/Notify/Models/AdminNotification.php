<?php

namespace App\Domain\Notify\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdminNotification extends Model
{
    protected $fillable = [
        'store_id',
        'type',
        'tone',
        'title',
        'body',
        'href',
        'subject_type',
        'subject_id',
    ];

    public function reads(): HasMany
    {
        return $this->hasMany(AdminNotificationRead::class);
    }
}
