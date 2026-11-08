<?php

namespace App\Domain\Health\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HealthFinding extends Model
{
    protected $fillable = [
        'store_id', 'scan_id', 'rule_code', 'category', 'severity', 'status',
        'resource_type', 'resource_id', 'resource_label', 'resource_url',
        'title', 'detail', 'impact', 'recommendation', 'evidence',
        'current_value', 'expected_value', 'affected_count', 'fix_capabilities',
        'first_detected_at', 'last_seen_at', 'resolved_at', 'ignored_at',
        'snoozed_until', 'ignore_reason',
    ];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'current_value' => 'array',
            'expected_value' => 'array',
            'fix_capabilities' => 'array',
            'first_detected_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
            'ignored_at' => 'datetime',
            'snoozed_until' => 'datetime',
        ];
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(HealthScan::class, 'scan_id');
    }

    public function fixJobs(): HasMany
    {
        return $this->hasMany(HealthFixJob::class, 'finding_id');
    }

    public function isOpen(): bool
    {
        if ($this->status !== 'open') {
            return false;
        }
        if ($this->snoozed_until && $this->snoozed_until->isFuture()) {
            return false;
        }

        return true;
    }
}
