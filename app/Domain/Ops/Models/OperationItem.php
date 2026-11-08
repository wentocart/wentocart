<?php

namespace App\Domain\Ops\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationItem extends Model
{
    protected $fillable = [
        'operation_id', 'entity_type', 'entity_id', 'action', 'label',
        'before_state', 'after_state', 'reversible', 'rolled_back', 'error',
    ];

    protected function casts(): array
    {
        return [
            'before_state' => 'array',
            'after_state' => 'array',
            'reversible' => 'boolean',
            'rolled_back' => 'boolean',
        ];
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }
}
