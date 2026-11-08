<?php

namespace App\Domain\Settings\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['store_id', 'admin_id', 'name', 'sql', 'last_run_at'])]
class SavedQuery extends Model
{
    protected function casts(): array
    {
        return [
            'last_run_at' => 'datetime',
        ];
    }
}
