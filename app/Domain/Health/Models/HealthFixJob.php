<?php

namespace App\Domain\Health\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HealthFixJob extends Model
{
    protected $fillable = [
        'uuid', 'store_id', 'finding_id', 'rule_code', 'fix_type', 'status',
        'created_by', 'approved_by', 'preview_data', 'original_data',
        'proposed_data', 'applied_data', 'rollback_data', 'rollback_supported',
        'rolled_back_at', 'error', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'preview_data' => 'array',
            'original_data' => 'array',
            'proposed_data' => 'array',
            'applied_data' => 'array',
            'rollback_data' => 'array',
            'rollback_supported' => 'boolean',
            'rolled_back_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function finding(): BelongsTo
    {
        return $this->belongsTo(HealthFinding::class, 'finding_id');
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(HealthAiProposal::class, 'fix_job_id');
    }
}
