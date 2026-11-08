<?php

namespace App\Domain\Health\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HealthAiProposal extends Model
{
    protected $fillable = [
        'uuid', 'store_id', 'fix_job_id', 'resource_type', 'resource_id',
        'task_type', 'status', 'input_context', 'current_content',
        'generated_output', 'edited_output', 'model', 'approved_by',
        'applied_at', 'error',
    ];

    protected function casts(): array
    {
        return [
            'input_context' => 'array',
            'applied_at' => 'datetime',
        ];
    }

    public function fixJob(): BelongsTo
    {
        return $this->belongsTo(HealthFixJob::class, 'fix_job_id');
    }
}
