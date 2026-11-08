<?php

namespace App\Domain\Health\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HealthScan extends Model
{
    protected $fillable = [
        'uuid', 'store_id', 'type', 'status', 'initiated_by',
        'pages_total', 'pages_checked', 'products_checked', 'images_checked',
        'assets_checked', 'links_checked', 'issues_found',
        'critical_count', 'warning_count', 'opportunity_count', 'passed_count',
        'overall_score', 'category_scores', 'progress', 'comparison',
        'error', 'current_step', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'category_scores' => 'array',
            'progress' => 'array',
            'comparison' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function findings(): HasMany
    {
        return $this->hasMany(HealthFinding::class, 'scan_id');
    }

    public function isRunning(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
    }
}
