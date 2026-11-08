<?php

namespace App\Domain\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 19 — append-only marketplace audit record.
 */
class MarketplaceAuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'store_id',
        'action',
        'entity_type',
        'entity_id',
        'actor_type',
        'actor_id',
        'seller_id',
        'customer_id',
        'before_state',
        'after_state',
        'reason',
        'reference',
        'context',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'before_state' => 'array',
            'after_state' => 'array',
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static fn () => false);
        static::deleting(static fn () => false);
    }
}
