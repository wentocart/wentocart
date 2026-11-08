<?php

namespace App\Domain\Customer\Models;

use App\Models\Admin;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['customer_id', 'admin_id', 'note', 'customer_notified'])]
class CustomerNote extends Model
{
    protected function casts(): array
    {
        return [
            'customer_notified' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
